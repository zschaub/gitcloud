<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Reacts to a GitCloud-tracked file being restored from Nextcloud's trash
 * (Files app "Deleted files", or any other trigger of the same event) by
 * auto-committing its return to the owner's GitCloud repository immediately.
 *
 * Without this, a restored file's history stays stuck at its last recorded
 * 'deleted' status forever - GitCloud never polls, it only ever finds out
 * about a delete or restore via these listeners - and the file stays missing
 * from git's index even though it is back on disk, which breaks the next
 * auto-tracked change on it (e.g. GitTrackedNodeRenamedListener staging a
 * rename against an old path git no longer has anything at).
 *
 * Only files GitCloud last saw as deleted react here - the tracked-file gate
 * check below is what keeps this from touching every restore in the instance.
 *
 * @template-implements IEventListener<NodeRestoredEvent>
 */
class GitTrackedNodeRestoredListener implements IEventListener {
	public function __construct(
		private SnapshotMapper $snapshotMapper,
		private IRootFolder $rootFolder,
		private VcsService $vcsService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeRestoredEvent)) {
			return;
		}

		$target = $event->getTarget();
		$owner = $target->getOwner();
		if ($owner === null) {
			return;
		}

		$fileId = $target->getId();
		$userId = $owner->getUID();

		$latest = $this->snapshotMapper->findLatestForFileId($userId, $fileId);
		if ($latest === null || $latest->getStatus() !== 'deleted') {
			// Either no GitCloud history directly under this node's own file_id,
			// GitCloud's last known state already agrees with reality (e.g. a
			// duplicate event dispatch), or - the case this fallback exists for -
			// this is a restored *folder*, which never has GitCloud history of its
			// own (only the files inside it do, see
			// GitTrackedNodeDeletedListener's identical reasoning for the delete
			// case). Fall back to treating the node's own path as a possible
			// folder prefix and look for tracked descendants under it that are
			// still marked deleted. For a genuinely untracked or already-synced
			// file this naturally matches nothing and is a no-op.
			$this->autoCommitRestoredFolderDescendants($target, $userId);
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Could not resolve user folder for %s while auto-committing a restore: %s', $userId, $e->getMessage()));
			return;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			$this->logger->warning(sprintf('Could not resolve repository path for %s while auto-committing a restore.', $userId));
			return;
		}

		$relativeFilePath = ltrim($userFolder->getRelativePath($target->getPath()) ?? '', '/');
		if ($relativeFilePath === '') {
			return;
		}

		$result = $this->vcsService->autoCommitRestore($repositoryPath, $relativeFilePath, $fileId, $userId);
		if (!$result['success']) {
			$this->logger->warning(sprintf('Failed to auto-commit restore of %s: %s', $relativeFilePath, $result['message']));
		}
	}

	/**
	 * A restored folder's tracked descendants are found from the snapshot
	 * history itself (its own node never carries GitCloud history - see
	 * GitTrackedNodeDeletedListener::autoCommitDeletedFolderDescendants() for the
	 * same reasoning applied to a delete): every one of the user's tracked files
	 * (by file_id, latest snapshot only) whose most recently known path falls
	 * inside the restored folder path, and whose last known status is
	 * 'deleted', is auto-committed as restored, individually, the same as a
	 * single-file restore.
	 */
	private function autoCommitRestoredFolderDescendants(Node $target, string $userId): void {
		try {
			$targetPath = $target->getPath();
		} catch (\Throwable $e) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Could not resolve user folder for %s while auto-committing a folder restore: %s', $userId, $e->getMessage()));
			return;
		}

		$relativeFolderPath = $userFolder->getRelativePath($targetPath);
		if ($relativeFolderPath === null) {
			return;
		}
		$relativeFolderPath = ltrim($relativeFolderPath, '/');
		if ($relativeFolderPath === '') {
			// Never react to the whole storage root being "restored".
			return;
		}

		// Scoped to the restored path in SQL rather than walking every snapshot the
		// user has ever recorded: this fallback runs for *every* restore in the
		// instance that isn't itself a tracked file, so it must stay cheap for the
		// overwhelmingly common case of an ordinary untracked folder, which matches
		// nothing here.
		$latestSnapshotsByFileId = [];
		foreach ($this->snapshotMapper->findAllForUserUnderPath($userId, $relativeFolderPath . '/') as $snapshot) {
			$snapshotFileId = $snapshot->getFileId();
			if ($snapshotFileId === null || isset($latestSnapshotsByFileId[$snapshotFileId])) {
				// Ordered newest-first, so the first snapshot seen for a given
				// file_id is already its latest.
				continue;
			}
			$latestSnapshotsByFileId[$snapshotFileId] = $snapshot;
		}

		$descendants = [];
		foreach ($latestSnapshotsByFileId as $descendantFileId => $snapshot) {
			if ($snapshot->getStatus() !== 'deleted') {
				// GitCloud's last known state for this file already agrees with
				// reality - nothing to reconcile.
				continue;
			}

			$descendants[$descendantFileId] = ltrim($snapshot->getFilePath(), '/');
		}

		if ($descendants === []) {
			// Either a plain untracked folder was restored (this path prefix
			// matches nothing) or a folder with no deleted tracked descendants
			// was - nothing to do.
			return;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			$this->logger->warning(sprintf('Could not resolve repository path for %s while auto-committing a folder restore.', $userId));
			return;
		}

		foreach ($descendants as $descendantFileId => $descendantPath) {
			$result = $this->vcsService->autoCommitRestore($repositoryPath, $descendantPath, (int)$descendantFileId, $userId);
			if (!$result['success']) {
				$this->logger->warning(sprintf('Failed to auto-commit restore of %s (inside restored folder %s): %s', $descendantPath, $relativeFolderPath, $result['message']));
			}
		}
	}
}

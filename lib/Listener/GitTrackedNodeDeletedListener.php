<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCA\GitCloud\Db\Snapshot;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Reacts to a file being deleted outside GitCloud (Files app, WebDAV, sync
 * clients, occ, ...) by auto-committing the deletion to the owner's GitCloud
 * repository immediately, so the dashboard and Git history stay in sync with
 * reality instead of the file silently vanishing with its history orphaned.
 *
 * Only files that already have GitCloud history react here - the tracked-file
 * gate check below is what keeps this from touching every delete in the instance.
 *
 * @template-implements IEventListener<NodeDeletedEvent>
 */
class GitTrackedNodeDeletedListener implements IEventListener {
	public function __construct(
		private SnapshotMapper $snapshotMapper,
		private IRootFolder $rootFolder,
		private VcsService $vcsService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeDeletedEvent)) {
			return;
		}

		$node = $event->getNode();
		$owner = $node->getOwner();
		if ($owner === null) {
			return;
		}

		$userId = $owner->getUID();

		try {
			$fileId = $node->getId();
		} catch (\Throwable $e) {
			$fileId = null;
		}

		if ($fileId !== null) {
			$latest = $this->snapshotMapper->findLatestForFileId($userId, $fileId);
			if ($latest !== null) {
				$this->autoCommitTrackedFileDeleted($latest, $fileId, $userId);
				return;
			}
		}

		// No GitCloud history directly under this node's own file_id - either it's
		// an untracked file (nothing to react to) or it's a deleted folder whose
		// *contents* are what's individually tracked, never the folder itself (see
		// ApiController::collectRelativeFilePaths). Nextcloud's Files-app delete
		// flow turns out not to be reliably distinguishable by node type here - a
		// deleted folder's node is empirically an OC\Files\Node\NonExistingFile,
		// the same generic stand-in used for a deleted plain file, not a
		// Folder/NonExistingFolder - and it does not dispatch a separate
		// NodeDeletedEvent for each descendant file either. So the folder case is
		// handled as a fallback: treat the node's own path as a possible folder
		// prefix and look for tracked descendants under it. For a genuinely
		// untracked plain file this naturally matches nothing and is a no-op.
		$this->autoCommitDeletedFolderDescendants($node, $userId);
	}

	/**
	 * The simple, direct case: the node deleted is itself a file GitCloud already
	 * has history for.
	 */
	private function autoCommitTrackedFileDeleted(Snapshot $latest, int $fileId, string $userId): void {
		if ($latest->getStatus() === 'deleted') {
			// Already recorded as deleted (defensive: guards against a duplicate
			// event dispatch re-triggering this listener for the same delete).
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Could not resolve user folder for %s while auto-committing a delete: %s', $userId, $e->getMessage()));
			return;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			$this->logger->warning(sprintf('Could not resolve repository path for %s while auto-committing a delete.', $userId));
			return;
		}

		// Uses the snapshot's own recorded path rather than deriving one from the
		// event's node, which may already be in a torn-down state post-delete.
		$result = $this->vcsService->autoCommitDelete($repositoryPath, $latest->getFilePath(), $fileId, $userId);
		if (!$result['success']) {
			$this->logger->warning(sprintf('Failed to auto-commit deletion for %s: %s', $latest->getFilePath(), $result['message']));
		}
	}

	/**
	 * A deleted folder's contents can no longer be listed from disk at this
	 * point, so its tracked descendants are found from the snapshot history
	 * itself instead: every one of the user's tracked files (by file_id, latest
	 * snapshot only) whose most recently known path falls inside the deleted
	 * node's path is auto-committed as deleted, individually, the same as a
	 * single-file delete.
	 */
	private function autoCommitDeletedFolderDescendants(Node $node, string $userId): void {
		try {
			$nodePath = $node->getPath();
		} catch (\Throwable $e) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Could not resolve user folder for %s while auto-committing a folder delete: %s', $userId, $e->getMessage()));
			return;
		}

		$relativeFolderPath = $userFolder->getRelativePath($nodePath);
		if ($relativeFolderPath === null) {
			return;
		}
		$relativeFolderPath = ltrim($relativeFolderPath, '/');
		if ($relativeFolderPath === '') {
			// Never react to the whole storage root being "deleted".
			return;
		}

		$latestSnapshotsByFileId = [];
		foreach ($this->snapshotMapper->findAllForUser($userId) as $snapshot) {
			$snapshotFileId = $snapshot->getFileId();
			if ($snapshotFileId === null || isset($latestSnapshotsByFileId[$snapshotFileId])) {
				// findAllForUser is ordered newest-first, so the first snapshot
				// seen for a given file_id is already its latest.
				continue;
			}
			$latestSnapshotsByFileId[$snapshotFileId] = $snapshot;
		}

		$descendants = [];
		foreach ($latestSnapshotsByFileId as $descendantFileId => $snapshot) {
			if ($snapshot->getStatus() === 'deleted') {
				continue;
			}

			$descendantPath = ltrim($snapshot->getFilePath(), '/');
			if (str_starts_with($descendantPath, $relativeFolderPath . '/')) {
				$descendants[$descendantFileId] = $descendantPath;
			}
		}

		if ($descendants === []) {
			// Either a plain untracked file was deleted (this path prefix matches
			// nothing) or a folder with no tracked descendants was - nothing to do.
			return;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			$this->logger->warning(sprintf('Could not resolve repository path for %s while auto-committing a folder delete.', $userId));
			return;
		}

		foreach ($descendants as $descendantFileId => $descendantPath) {
			$result = $this->vcsService->autoCommitDelete($repositoryPath, $descendantPath, (int)$descendantFileId, $userId);
			if (!$result['success']) {
				$this->logger->warning(sprintf('Failed to auto-commit deletion for %s (inside deleted folder %s): %s', $descendantPath, $relativeFolderPath, $result['message']));
			}
		}
	}
}

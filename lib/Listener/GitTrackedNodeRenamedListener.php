<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCA\GitCloud\Service\WorkingTree;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * Reacts to a file being renamed or moved outside GitCloud (same event covers
 * both a same-directory rename and a cross-directory move - Nextcloud has no
 * separate move event) by auto-committing the rename to the owner's GitCloud
 * repository immediately, so the file's history stays linked to its new path
 * instead of the old path being orphaned and the new path looking like a
 * brand-new, historyless file.
 *
 * Only files that already have GitCloud history react here - the tracked-file
 * gate check below is what keeps this from touching every rename in the instance.
 *
 * @template-implements IEventListener<NodeRenamedEvent>
 */
class GitTrackedNodeRenamedListener implements IEventListener {
	public function __construct(
		private SnapshotMapper $snapshotMapper,
		private IRootFolder $rootFolder,
		private VcsService $vcsService,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof NodeRenamedEvent)) {
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
		if ($latest === null) {
			// No GitCloud history directly under this node's own file_id - either
			// it's an untracked file, or it's a renamed/moved *folder*, which never
			// has GitCloud history of its own (only the files inside it do, see
			// GitTrackedNodeDeletedListener's identical reasoning for the delete
			// case). Fall back to treating both the old and new node paths as
			// possible folder prefixes and look for tracked descendants under the
			// old one. For a genuinely untracked plain file this naturally matches
			// nothing and is a no-op.
			$this->autoCommitRenamedFolderDescendants($event->getSource(), $target, $userId);
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Could not resolve user folder for %s while auto-committing a rename: %s', $userId, $e->getMessage()));
			return;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			$this->logger->warning(sprintf('Could not resolve repository path for %s while auto-committing a rename.', $userId));
			return;
		}

		$oldRelativePath = $latest->getFilePath();
		$newRelativePath = ltrim($userFolder->getRelativePath($target->getPath()) ?? '', '/');

		if ($newRelativePath === '' || $oldRelativePath === $newRelativePath) {
			// Nothing actually changed from GitCloud's perspective (defensive).
			return;
		}

		if (!WorkingTree::contains($target, $userFolder)) {
			// The file was moved onto a different storage - a group folder, a
			// received share, an external mount - which is outside the Git
			// working tree, so there is no new path for git to stage (and
			// staging the old one alone would fail the whole rename). From
			// GitCloud's perspective the file has left its world, which is
			// exactly what a deletion already means: the file drops off as
			// Deleted while its History/Rollback stay reachable.
			$result = $this->vcsService->autoCommitDelete($repositoryPath, $oldRelativePath, $fileId, $userId);
			if (!$result['success']) {
				$this->logger->warning(sprintf('Failed to auto-commit deletion of %s after it was moved outside the working tree: %s', $oldRelativePath, $result['message']));
			}

			return;
		}

		$result = $this->vcsService->autoCommitRename($repositoryPath, $oldRelativePath, $newRelativePath, $fileId, $userId);
		if (!$result['success']) {
			$this->logger->warning(sprintf('Failed to auto-commit rename from %s to %s: %s', $oldRelativePath, $newRelativePath, $result['message']));
		}
	}

	/**
	 * A renamed/moved folder's tracked descendants are found from the snapshot
	 * history itself (its own node never carries GitCloud history - see
	 * GitTrackedNodeDeletedListener::autoCommitDeletedFolderDescendants() for the
	 * same reasoning applied to a delete): every one of the user's tracked files
	 * (by file_id, latest snapshot only) whose most recently known path falls
	 * inside the old folder path is auto-committed as a rename to its
	 * corresponding new path, individually, the same as a single-file rename.
	 */
	private function autoCommitRenamedFolderDescendants(Node $source, Node $target, string $userId): void {
		try {
			$sourcePath = $source->getPath();
			$targetPath = $target->getPath();
		} catch (\Throwable $e) {
			return;
		}

		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->warning(sprintf('Could not resolve user folder for %s while auto-committing a folder rename: %s', $userId, $e->getMessage()));
			return;
		}

		$relativeOldFolderPath = $userFolder->getRelativePath($sourcePath);
		if ($relativeOldFolderPath === null) {
			return;
		}
		$relativeOldFolderPath = ltrim($relativeOldFolderPath, '/');
		if ($relativeOldFolderPath === '') {
			// Never react to the whole storage root being "renamed".
			return;
		}

		$relativeNewFolderPath = $userFolder->getRelativePath($targetPath);
		if ($relativeNewFolderPath === null) {
			return;
		}
		$relativeNewFolderPath = ltrim($relativeNewFolderPath, '/');

		if ($relativeOldFolderPath === $relativeNewFolderPath) {
			// Nothing actually changed from GitCloud's perspective (defensive).
			return;
		}

		// Scoped to the old path in SQL rather than walking every snapshot the user
		// has ever recorded: this fallback runs for *every* rename/move in the
		// instance that isn't itself a tracked file, so it must stay cheap for the
		// overwhelmingly common case of an ordinary untracked folder, which matches
		// nothing here.
		$latestSnapshotsByFileId = [];
		foreach ($this->snapshotMapper->findAllForUserUnderPath($userId, $relativeOldFolderPath . '/') as $snapshot) {
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
			if ($snapshot->getStatus() === 'deleted') {
				// Already deleted, so it can't have moved along with the folder.
				continue;
			}

			$descendants[$descendantFileId] = ltrim($snapshot->getFilePath(), '/');
		}

		if ($descendants === []) {
			// Either a plain untracked folder was renamed/moved (this path prefix
			// matches nothing) or a folder with no tracked descendants was -
			// nothing to do.
			return;
		}

		$repositoryPath = $this->vcsService->resolveRepositoryPath($userFolder);
		if ($repositoryPath === false) {
			$this->logger->warning(sprintf('Could not resolve repository path for %s while auto-committing a folder rename.', $userId));
			return;
		}

		if (!WorkingTree::contains($target, $userFolder)) {
			// The folder was moved onto a different storage - see the single-file
			// case above for why this is treated as a deletion of every descendant
			// rather than a rename.
			foreach ($descendants as $descendantFileId => $descendantOldPath) {
				$result = $this->vcsService->autoCommitDelete($repositoryPath, $descendantOldPath, (int)$descendantFileId, $userId);
				if (!$result['success']) {
					$this->logger->warning(sprintf('Failed to auto-commit deletion of %s after its folder was moved outside the working tree: %s', $descendantOldPath, $result['message']));
				}
			}

			return;
		}

		foreach ($descendants as $descendantFileId => $descendantOldPath) {
			$descendantNewPath = $relativeNewFolderPath . '/' . substr($descendantOldPath, strlen($relativeOldFolderPath) + 1);
			$result = $this->vcsService->autoCommitRename($repositoryPath, $descendantOldPath, $descendantNewPath, (int)$descendantFileId, $userId);
			if (!$result['success']) {
				$this->logger->warning(sprintf('Failed to auto-commit rename from %s to %s (inside renamed folder %s): %s', $descendantOldPath, $descendantNewPath, $relativeOldFolderPath, $result['message']));
			}
		}
	}
}

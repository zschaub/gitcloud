<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\IRootFolder;
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
			// No GitCloud history for this file (or file_id was never recorded
			// for it, e.g. it predates the migration that added this column) -
			// nothing for GitCloud to react to.
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

		$result = $this->vcsService->autoCommitRename($repositoryPath, $oldRelativePath, $newRelativePath, $fileId, $userId);
		if (!$result['success']) {
			$this->logger->warning(sprintf('Failed to auto-commit rename from %s to %s: %s', $oldRelativePath, $newRelativePath, $result['message']));
		}
	}
}

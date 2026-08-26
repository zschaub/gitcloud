<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\IRootFolder;
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

		$fileId = $node->getId();
		$userId = $owner->getUID();

		$latest = $this->snapshotMapper->findLatestForFileId($userId, $fileId);
		if ($latest === null) {
			// No GitCloud history for this file (or file_id was never recorded
			// for it, e.g. it predates the migration that added this column) -
			// nothing for GitCloud to react to.
			return;
		}

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

		// Uses the snapshot's own recorded path rather than deriving one from
		// $node, which may already be in a torn-down state post-delete.
		$result = $this->vcsService->autoCommitDelete($repositoryPath, $latest->getFilePath(), $fileId, $userId);
		if (!$result['success']) {
			$this->logger->warning(sprintf('Failed to auto-commit deletion for %s: %s', $latest->getFilePath(), $result['message']));
		}
	}
}

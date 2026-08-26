<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GitCloud\Db\SnapshotMapper;
use OCA\GitCloud\Service\VcsService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\IRootFolder;
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
			// Either no GitCloud history for this file, or GitCloud's last known
			// state already agrees with reality (e.g. a duplicate event dispatch) -
			// nothing to reconcile.
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
}

<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\IRootFolder;

/**
 * Blocks deleting GitCloud's own .git repository, or anything inside it,
 * through Nextcloud's normal file operations - see GitRepositoryPathGuard.
 *
 * @template-implements IEventListener<BeforeNodeDeletedEvent>
 */
class GitRepositoryDeletionBlockedListener implements IEventListener {
	use GitRepositoryPathGuard;

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeNodeDeletedEvent)) {
			return;
		}

		if ($this->isInsideGitRepository($event->getNode(), $this->rootFolder)) {
			throw new AbortedEventException('The GitCloud .git repository cannot be deleted directly. Manage your files normally through the Files app, or use Settings > Personal > GitCloud to back up or delete its history.');
		}
	}
}

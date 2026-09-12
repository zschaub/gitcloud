<?php

declare(strict_types=1);

namespace OCA\GitCloud\Listener;

use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Exceptions\AbortedEventException;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\IRootFolder;

/**
 * Blocks renaming or moving GitCloud's own .git repository (or anything inside
 * it) either away from or onto its path, through Nextcloud's normal file
 * operations - see GitRepositoryPathGuard.
 *
 * @template-implements IEventListener<BeforeNodeRenamedEvent>
 */
class GitRepositoryRenameBlockedListener implements IEventListener {
	use GitRepositoryPathGuard;

	public function __construct(
		private IRootFolder $rootFolder,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeNodeRenamedEvent)) {
			return;
		}

		if ($this->isInsideGitRepository($event->getSource(), $this->rootFolder)
			|| $this->isInsideGitRepository($event->getTarget(), $this->rootFolder)) {
			throw new AbortedEventException('The GitCloud .git repository cannot be renamed or moved directly. Manage your files normally through the Files app, or use Settings > Personal > GitCloud to back up or delete its history.');
		}
	}
}

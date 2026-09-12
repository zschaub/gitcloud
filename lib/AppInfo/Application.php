<?php

declare(strict_types=1);

namespace OCA\GitCloud\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\GitCloud\Listener\GitRepositoryDeletionBlockedListener;
use OCA\GitCloud\Listener\GitRepositoryRenameBlockedListener;
use OCA\GitCloud\Listener\GitRepositoryWriteGuard;
use OCA\GitCloud\Listener\GitTrackedNodeDeletedListener;
use OCA\GitCloud\Listener\GitTrackedNodeRenamedListener;
use OCA\GitCloud\Listener\GitTrackedNodeRestoredListener;
use OCA\GitCloud\Listener\LoadAdditionalScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Util;

class Application extends App implements IBootstrap {
	public const APP_ID = 'gitcloud';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			LoadAdditionalScriptsEvent::class,
			LoadAdditionalScriptsListener::class,
		);
		$context->registerEventListener(
			NodeDeletedEvent::class,
			GitTrackedNodeDeletedListener::class,
		);
		$context->registerEventListener(
			NodeRenamedEvent::class,
			GitTrackedNodeRenamedListener::class,
		);
		$context->registerEventListener(
			NodeRestoredEvent::class,
			GitTrackedNodeRestoredListener::class,
		);
		$context->registerEventListener(
			BeforeNodeDeletedEvent::class,
			GitRepositoryDeletionBlockedListener::class,
		);
		$context->registerEventListener(
			BeforeNodeRenamedEvent::class,
			GitRepositoryRenameBlockedListener::class,
		);
	}

	public function boot(IBootContext $context): void {
		// Not a typed event listener - see GitRepositoryWriteGuard's own
		// docblock for why cancelling a write needs the legacy hook instead.
		Util::connectHook('OC_Filesystem', 'write', new GitRepositoryWriteGuard(), 'blockWrite');
	}
}

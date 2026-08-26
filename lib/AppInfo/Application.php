<?php

declare(strict_types=1);

namespace OCA\GitCloud\AppInfo;

use OCA\Files\Event\LoadAdditionalScriptsEvent;
use OCA\GitCloud\Listener\GitTrackedNodeDeletedListener;
use OCA\GitCloud\Listener\GitTrackedNodeRenamedListener;
use OCA\GitCloud\Listener\LoadAdditionalScriptsListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;

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
	}

	public function boot(IBootContext $context): void {
	}
}

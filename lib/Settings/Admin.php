<?php

declare(strict_types=1);

namespace OCA\GitCloud\Settings;

use OCA\GitCloud\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IAppConfig;
use OCP\Settings\IDelegatedSettings;
use OCP\Util;

/**
 * @psalm-suppress UnusedClass
 */
class Admin implements IDelegatedSettings {
	public function __construct(
		private IInitialState $initialState,
		private IAppConfig $appConfig,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialState->provideInitialState(
			'max-file-size-mb',
			$this->appConfig->getValueInt(Application::APP_ID, 'max_file_size_mb', 100),
		);
		$this->initialState->provideInitialState(
			'enforcement-mode',
			$this->appConfig->getValueString(Application::APP_ID, 'enforcement_mode', 'block'),
		);

		Util::addScript(Application::APP_ID, Application::APP_ID . '-settings-admin');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-settings-admin');

		return new TemplateResponse(Application::APP_ID, 'settings-admin', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}

	public function getName(): ?string {
		return null;
	}

	public function getAuthorizedAppConfig(): array {
		return [
			Application::APP_ID => ['/^max_file_size_mb$/', '/^enforcement_mode$/'],
		];
	}
}

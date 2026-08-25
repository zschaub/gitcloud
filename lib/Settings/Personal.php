<?php

declare(strict_types=1);

namespace OCA\GitCloud\Settings;

use OCA\GitCloud\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;
use OCP\Util;

/**
 * @psalm-suppress UnusedClass
 */
class Personal implements ISettings {
	public function getForm(): TemplateResponse {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-settings-personal');
		Util::addStyle(Application::APP_ID, Application::APP_ID . '-settings-personal');

		return new TemplateResponse(Application::APP_ID, 'settings-personal', [], '');
	}

	public function getSection(): string {
		return Application::APP_ID;
	}

	public function getPriority(): int {
		return 50;
	}
}

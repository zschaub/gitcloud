<?php

declare(strict_types=1);

namespace OCA\GitCloud\Settings;

use OCA\GitCloud\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * A dedicated personal section is needed rather than reusing Nextcloud's built-in
 * "Additional settings" section: that section only renders at all once two or more
 * apps register a personal setting under it (\OC\Settings\Manager::getPersonalSections),
 * so relying on it alone would silently hide this page whenever GitCloud is the only
 * app using it.
 *
 * @psalm-suppress UnusedClass
 */
class PersonalSection implements IIconSection {
	public function __construct(
		private IL10N $l,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('GitCloud');
	}

	public function getPriority(): int {
		return 50;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}

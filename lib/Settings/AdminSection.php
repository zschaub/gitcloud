<?php

declare(strict_types=1);

namespace OCA\GitCloud\Settings;

use OCA\GitCloud\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * @psalm-suppress UnusedClass
 */
class AdminSection implements IIconSection {
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
		// The Settings navigation renders section icons as a black glyph that's CSS-inverted
		// to white in dark theme, unlike app.svg's white fill (meant for the colored circle
		// background of the main Nextcloud navigation) which would invert to black instead.
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg');
	}
}

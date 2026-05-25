<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Snapcloud\AppInfo\Application::APP_ID, OCA\Snapcloud\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Snapcloud\AppInfo\Application::APP_ID, OCA\Snapcloud\AppInfo\Application::APP_ID . '-main');

?>

<div id="snapcloud"></div>

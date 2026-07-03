<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\GitCloud\AppInfo\Application::APP_ID, OCA\GitCloud\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\GitCloud\AppInfo\Application::APP_ID, OCA\GitCloud\AppInfo\Application::APP_ID . '-main');

?>

<div id="gitcloud"></div>

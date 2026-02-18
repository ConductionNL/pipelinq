<?php

use OCP\Util;

$appId = OCA\Pipelinq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="pipelinq-settings"></div>

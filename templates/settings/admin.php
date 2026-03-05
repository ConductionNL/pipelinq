<?php

use OCP\Util;

$appId = OCA\Pipelinq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-settings');
?>
<div id="pipelinq-settings" data-config="<?php p($_['config'] ?? '{}'); ?>" data-version="<?php p($_['version'] ?? ''); ?>"></div>

<?php

use OCP\Util;

$appId = OCA\Pipelinq\AppInfo\Application::APP_ID;
// Load the shared webpack chunks before the settings entry chunk — see the
// comment in templates/index.php for why all three are required.
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-settings');
?>
<div id="pipelinq-settings" data-config="<?php p($_['config'] ?? '{}'); ?>" data-version="<?php p($_['version'] ?? ''); ?>"></div>

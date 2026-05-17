<?php

use OCP\Util;

$appId = OCA\Pipelinq\AppInfo\Application::APP_ID;
// The webpack build (see webpack.config.js → optimization.splitChunks) emits
// the entry point as three files: the shared vendor chunk, the shared
// @conduction/nextcloud-vue chunk, and the entry chunk itself. All three must
// be loaded, in dependency order, for the bundle to bootstrap — the entry
// chunk references modules that live in the shared chunks. (The dashboard
// widget loaders in lib/Dashboard/*Widget.php already do this; this template
// must too.)
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-main');
?>
<div id="content"></div>

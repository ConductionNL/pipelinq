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
<?php
// The mount host is DELIBERATELY NOT `#content`.
//
// Nextcloud core's `layout.user.php` already renders a `<div id="content">`
// that this template's output is placed inside, so this element used to be a
// DUPLICATE of core's. Under Vue 2, `new Vue(...).$mount('#content')` matched
// core's outer div and REPLACED it, so the duplication never showed. Vue 3's
// `app.mount()` renders INSIDE the matched element instead of replacing it —
// selecting `#content` would resolve to core's wrapper and render the app in
// the wrong place (and leave this empty div orphaned below it).
//
// Use an app-owned id so the selector can only ever match this element.
?>
<div id="pipelinq-app"></div>

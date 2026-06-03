<?php

/**
 * Customer portal SPA template (public, separate auth domain).
 *
 * Loads only the standalone portal bundle — never the Nextcloud-authenticated
 * main app shell. The page itself is public (#[PublicPage]); the customer
 * authenticates client-side with a bearer token against /portal/api (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

use OCP\Util;

$appId = OCA\Pipelinq\AppInfo\Application::APP_ID;
Util::addScript($appId, $appId . '-shared-vendor');
Util::addScript($appId, $appId . '-shared-nc-vue');
Util::addScript($appId, $appId . '-portal');
?>
<div id="pipelinq-portal"></div>

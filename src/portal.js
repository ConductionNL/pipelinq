/**
 * Customer portal SPA entrypoint.
 *
 * Bootstraps the standalone, separate-auth-domain portal app (Vue 3 +
 * vue-router 4) mounted at /apps/pipelinq/portal/*. It deliberately does NOT
 * load the Nextcloud-authenticated main app shell — the portal authenticates its
 * own customers with a bearer token (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { translatePlural as n, translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { createApp } from 'vue'
import { createRouter, createWebHistory } from 'vue-router'
import PortalApp from './portal/PortalApp.vue'
import { installPortalGuard, portalRoutes } from './portal/portalRoutes.js'

import './assets/app.css'

/**
 * The router base for THIS page load.
 *
 * ⚠️ `generateUrl('/apps/pipelinq/portal')` alone is not enough. Nextcloud
 * serves the app under BOTH /apps/pipelinq/portal/... and
 * /index.php/apps/pipelinq/portal/..., but `generateUrl()` returns only the
 * form the instance is configured for. A visitor arriving on the other form
 * falls outside the router base, vue-router cannot resolve the path, and the
 * portal silently lands on its default route instead of the one that was
 * linked — which for a customer-facing booking link means the booking is lost
 * with no error shown.
 *
 * @return {string} The base path vue-router should strip from the URL.
 */
function routerBase() {
	const match = window.location.pathname.match(
		/^(.*\/apps\/pipelinq\/portal)(?:\/|$)/,
	)
	return match ? match[1] : generateUrl('/apps/pipelinq/portal')
}

// History mode. The server already serves this: `portalPage#subpath` in
// appinfo/routes.php answers /portal/{path} with requirement `^(?!api/).*`,
// so every portal deep link reaches the SPA shell while /portal/api/* stays
// with the real controllers. That api exclusion is what makes history mode
// safe here — without it the SPA would swallow the booking API, which is
// exactly what it was doing to /portal/services until #1697.
const router = createRouter({
	history: createWebHistory(routerBase()),
	routes: portalRoutes,
})
installPortalGuard(router)

document.addEventListener('DOMContentLoaded', () => {
	const mount =
		document.getElementById('pipelinq-portal')
		|| document.body.appendChild(document.createElement('div'))
	mount.id = 'pipelinq-portal'
	const app = createApp(PortalApp)
	// Vue 3 has no `Vue.prototype`; `app.config.globalProperties` is the
	// per-instance equivalent, and it is what makes bare `t(…)` / `n(…)` in the
	// portal's templates resolve.
	app.config.globalProperties.t = t
	app.config.globalProperties.n = n
	app.use(router)
	// Vue 3 `mount()` renders INSIDE the host element (Vue 2 `$mount()`
	// REPLACED it). The host is app-owned (templates/portal.php), so rendering
	// inside it is correct and keeps the id stable for the fallback branch above.
	app.mount(mount)
})

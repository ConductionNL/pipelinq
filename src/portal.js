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

import { createApp } from 'vue'
import { createRouter, createWebHashHistory } from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import PortalApp from './portal/PortalApp.vue'
import { portalRoutes, installPortalGuard } from './portal/portalRoutes.js'
import './assets/app.css'

// vue-router 4 replaces `mode: 'hash'` + `base` with a history object.
const router = createRouter({
	history: createWebHashHistory(generateUrl('/apps/pipelinq/portal')),
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

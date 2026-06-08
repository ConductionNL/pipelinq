/**
 * Customer portal SPA entrypoint.
 *
 * Bootstraps the standalone, separate-auth-domain portal app (Vue 2 +
 * vue-router 3) mounted at /apps/pipelinq/portal/*. It deliberately does NOT
 * load the Nextcloud-authenticated main app shell — the portal authenticates its
 * own customers with a bearer token (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import Vue from 'vue'
import VueRouter from 'vue-router'
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

import PortalApp from './portal/PortalApp.vue'
import { portalRoutes, installPortalGuard } from './portal/portalRoutes.js'
import './assets/app.css'

Vue.use(VueRouter)
Vue.prototype.t = t
Vue.prototype.n = n

const router = new VueRouter({
	mode: 'hash',
	base: generateUrl('/apps/pipelinq/portal'),
	routes: portalRoutes,
})
installPortalGuard(router)

document.addEventListener('DOMContentLoaded', () => {
	const mount = document.getElementById('pipelinq-portal') || document.body.appendChild(document.createElement('div'))
	mount.id = 'pipelinq-portal'
	// eslint-disable-next-line no-new
	new Vue({
		router,
		render: (h) => h(PortalApp),
	}).$mount(mount)
})

/**
 * Customer portal frontend routes (vue-router 3).
 *
 * Defines the standalone portal SPA's routes and a navigation guard that gates
 * every authenticated route on the presence of a non-expired portal session
 * token (sessionStorage), redirecting to the login page otherwise. This is a UX
 * guard only — the server re-authenticates every API call from the bearer token,
 * so the real access boundary is server-side (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { getToken, getExpiry } from './portalApi.js'

import PortalLogin from './views/PortalLogin.vue'
import PortalPasswordReset from './views/PortalPasswordReset.vue'
import PortalDashboard from './views/PortalDashboard.vue'
import PortalRequests from './views/PortalRequests.vue'
import PortalProfile from './views/PortalProfile.vue'
import PortalDelegations from './views/PortalDelegations.vue'
import PortalExport from './views/PortalExport.vue'
import PortalWidget from './views/PortalWidget.vue'
import BookingPortal from '../views/portal/BookingPortal.vue'
import BookingConfirmationPage from '../views/portal/BookingConfirmationPage.vue'

export const portalRoutes = [
	{ path: '/', redirect: '/dashboard' },
	{ path: '/login', component: PortalLogin, meta: { public: true } },
	{ path: '/password-reset', component: PortalPasswordReset, meta: { public: true } },
	{ path: '/widget', component: PortalWidget, meta: { public: true } },
	// Public appointment-booking portal (member 06) — no portal session required.
	{ path: '/book/:serviceSlug', component: BookingPortal, meta: { public: true } },
	{ path: '/booking-confirmation/:bookingId', component: BookingConfirmationPage, meta: { public: true } },
	{ path: '/dashboard', component: PortalDashboard },
	{ path: '/requests', component: PortalRequests },
	{ path: '/profile', component: PortalProfile },
	{ path: '/delegations', component: PortalDelegations },
	{ path: '/export', component: PortalExport },
]

/**
 * Whether the current portal session token is present and unexpired.
 *
 * @return {boolean} True when authenticated.
 */
export function isAuthenticated() {
	const token = getToken()
	if (!token) {
		return false
	}
	const expiry = getExpiry()
	return expiry === 0 || expiry > Date.now()
}

/**
 * Install the auth navigation guard on a router instance.
 *
 * @param {object} router The vue-router instance.
 */
export function installPortalGuard(router) {
	router.beforeEach((to, from, next) => {
		if (to.meta && to.meta.public) {
			next()
			return
		}
		if (!isAuthenticated()) {
			next('/login')
			return
		}
		next()
	})
}

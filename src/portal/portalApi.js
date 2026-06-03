/**
 * Customer portal API client.
 *
 * A thin fetch wrapper for the separate-auth-domain portal. The portal does NOT
 * use the Nextcloud session: it authenticates with a bearer token stored in
 * sessionStorage (never localStorage — it must not outlive the tab), sent in the
 * Authorization header on every call. The token is shown once at login and only
 * its hash is stored server-side, so there is no ambient cookie and these
 * requests are not CSRF-able (ADR-005).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

import { generateUrl } from '@nextcloud/router'

const TOKEN_KEY = 'pipelinq-portal-token'
const EXPIRY_KEY = 'pipelinq-portal-expires'

/**
 * Read the stored bearer token, or null.
 *
 * @return {string|null} The token.
 */
export function getToken() {
	return window.sessionStorage.getItem(TOKEN_KEY)
}

/**
 * Store the session token and its expiry.
 *
 * @param {string} token The bearer token.
 * @param {string} expiresAt ISO-8601 expiry.
 */
export function setToken(token, expiresAt) {
	window.sessionStorage.setItem(TOKEN_KEY, token)
	if (expiresAt) {
		window.sessionStorage.setItem(EXPIRY_KEY, expiresAt)
	}
}

/**
 * The stored session expiry timestamp (ms epoch), or 0.
 *
 * @return {number} The expiry in ms.
 */
export function getExpiry() {
	const raw = window.sessionStorage.getItem(EXPIRY_KEY)
	return raw ? Date.parse(raw) : 0
}

/**
 * Clear the stored session.
 */
export function clearToken() {
	window.sessionStorage.removeItem(TOKEN_KEY)
	window.sessionStorage.removeItem(EXPIRY_KEY)
}

/**
 * The widget tenant (from the URL ?tenant= param), or null.
 *
 * @return {string|null} The tenant id.
 */
function widgetTenant() {
	const params = new URLSearchParams(window.location.search)
	return params.get('tenant')
}

/**
 * Perform a portal API request.
 *
 * @param {string} method HTTP method.
 * @param {string} path API path under /portal/api.
 * @param {object|null} body Optional JSON body.
 * @return {Promise<object>} Resolves to the parsed JSON body.
 * @throws {object} Rejects with {status, errorCode, message} on a non-2xx response.
 */
export async function portalFetch(method, path, body = null) {
	const headers = { Accept: 'application/json' }
	const token = getToken()
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	const tenant = widgetTenant()
	if (tenant) {
		headers['X-Portal-Tenant'] = tenant
	}
	if (body !== null) {
		headers['Content-Type'] = 'application/json'
	}

	const response = await fetch(generateUrl('/apps/pipelinq/portal/api' + path), {
		method,
		headers,
		body: body !== null ? JSON.stringify(body) : undefined,
	})

	let payload = {}
	try {
		payload = await response.json()
	} catch (e) {
		payload = {}
	}

	if (!response.ok) {
		const err = Object.assign(new Error(payload.message || `HTTP ${response.status}`), { status: response.status }, payload)
		throw err
	}
	return payload
}

export const portalApi = {
	tenantConfig: () => portalFetch('GET', '/tenant-config'),
	login: (email, password, totpCode) => portalFetch('POST', '/auth/login', { email, password, totpCode }),
	logout: () => portalFetch('POST', '/auth/logout'),
	extendSession: () => portalFetch('POST', '/auth/extend-session'),
	passwordResetRequest: (email) => portalFetch('POST', '/auth/password-reset-request', { email }),
	passwordReset: (token, password) => portalFetch('POST', '/auth/password-reset', { token, password }),
	mfaEnroll: () => portalFetch('POST', '/auth/mfa-enroll'),
	mfaVerify: (code) => portalFetch('POST', '/auth/mfa-verify', { code }),
	profile: () => portalFetch('GET', '/accounts/profile'),
	updateProfile: (changes) => portalFetch('PUT', '/accounts/profile', changes),
	invoices: (page = 1) => portalFetch('GET', `/invoices?page=${page}`),
	contracts: (page = 1) => portalFetch('GET', `/contracts?page=${page}`),
	orders: (page = 1) => portalFetch('GET', `/orders?page=${page}`),
	requests: (page = 1) => portalFetch('GET', `/requests?page=${page}`),
	request: (id) => portalFetch('GET', `/requests/${encodeURIComponent(id)}`),
	submitRequest: (payload) => portalFetch('POST', '/requests', payload),
	replyRequest: (id, message) => portalFetch('POST', `/requests/${encodeURIComponent(id)}/reply`, { message }),
	signDocument: (objectId, objectType) => portalFetch('POST', '/documents/sign', { objectId, objectType }),
	delegations: () => portalFetch('GET', '/delegations'),
	grantDelegation: (payload) => portalFetch('POST', '/delegations', payload),
	revokeDelegation: (id) => portalFetch('DELETE', `/delegations/${encodeURIComponent(id)}`),
	requestExport: () => portalFetch('POST', '/exports'),
	requestClose: () => portalFetch('POST', '/accounts/close'),
}

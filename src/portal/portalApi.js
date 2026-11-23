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
 * The headers of a portal request: the bearer token and the widget's tenant
 * when there are any, and a JSON content type for a request with a body.
 *
 * @param {boolean} hasBody Whether the request sends a JSON body.
 * @return {object} The headers.
 */
function portalHeaders(hasBody) {
	const headers = { Accept: 'application/json' }
	const token = getToken()
	if (token) {
		headers.Authorization = `Bearer ${token}`
	}
	const tenant = widgetTenant()
	if (tenant) {
		headers['X-Portal-Tenant'] = tenant
	}
	if (hasBody) {
		headers['Content-Type'] = 'application/json'
	}
	return headers
}

/**
 * @param {Response} response The portal's answer.
 * @return {Promise<object>} Its JSON body, or {} when it has none.
 */
async function readPortalPayload(response) {
	try {
		return await response.json()
	} catch {
		return {}
	}
}

/**
 * Perform a portal API request.
 *
 * @param {{method: string, path: string, body: (object|null|undefined)}} request The HTTP method, the API path under /portal/api, and the optional JSON body.
 * @return {Promise<object>} Resolves to the parsed JSON body.
 * @throws {object} Rejects with {status, errorCode, message} on a non-2xx response.
 */
export async function portalFetch(request) {
	const body = request.body ?? null
	const response = await fetch(generateUrl('/apps/pipelinq/portal/api' + request.path), {
		method: request.method,
		headers: portalHeaders(body !== null),
		body: body !== null ? JSON.stringify(body) : undefined,
	})

	const payload = await readPortalPayload(response)

	if (!response.ok) {
		const err = Object.assign(
			new Error(payload.message || `HTTP ${response.status}`),
			{ status: response.status },
			payload,
		)
		throw err
	}
	return payload
}

/**
 * @param {string} path The API path.
 * @return {Promise<object>} The answer to a GET.
 */
function get(path) {
	return portalFetch({ method: 'GET', path })
}

/**
 * @param {string} path The API path.
 * @param {(object|undefined)} body The JSON body, if any.
 * @return {Promise<object>} The answer to a POST.
 */
function post(path, body) {
	return portalFetch({ method: 'POST', path, body })
}

export const portalApi = {
	tenantConfig: () => get('/tenant-config'),
	login: (credentials) =>
		post('/auth/login', {
			email: credentials.email,
			password: credentials.password,
			totpCode: credentials.totpCode,
		}),
	logout: () => post('/auth/logout'),
	extendSession: () => post('/auth/extend-session'),
	passwordResetRequest: (email) =>
		post('/auth/password-reset-request', { email }),
	passwordReset: (token, password) =>
		post('/auth/password-reset', { token, password }),
	mfaEnroll: () => post('/auth/mfa-enroll'),
	mfaVerify: (code) => post('/auth/mfa-verify', { code }),
	profile: () => get('/accounts/profile'),
	updateProfile: (changes) => portalFetch({ method: 'PUT', path: '/accounts/profile', body: changes }),
	invoices: (page = 1) => get(`/invoices?page=${page}`),
	contracts: (page = 1) => get(`/contracts?page=${page}`),
	orders: (page = 1) => get(`/orders?page=${page}`),
	requests: (page = 1) => get(`/requests?page=${page}`),
	request: (id) => get(`/requests/${encodeURIComponent(id)}`),
	submitRequest: (payload) => post('/requests', payload),
	replyRequest: (id, message) =>
		post(`/requests/${encodeURIComponent(id)}/reply`, {
			message,
		}),
	signDocument: (objectId, objectType) =>
		post('/documents/sign', { objectId, objectType }),
	delegations: () => get('/delegations'),
	grantDelegation: (payload) => post('/delegations', payload),
	revokeDelegation: (id) =>
		portalFetch({ method: 'DELETE', path: `/delegations/${encodeURIComponent(id)}` }),
	requestExport: () => post('/exports'),
	requestClose: () => post('/accounts/close'),
}

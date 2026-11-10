// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The customer portal's requests: the bearer token and tenant they carry,
 * the JSON body, the parsed answer, and the refusal they reject with.
 *
 * @spec openspec/specs/customer-portal/spec.md
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))

const { clearToken, portalApi, portalFetch, setToken } = await import('../../src/portal/portalApi.js')

/**
 * @param {number} status The status to answer.
 * @param {*} body The JSON body to answer, or an Error for a body that does not parse.
 * @return {object} The fetch mock.
 */
function answer(status, body) {
	const fetchMock = vi.fn(async () => ({
		ok: status >= 200 && status < 300,
		status,
		json: async () => {
			if (body instanceof Error) throw body
			return body
		},
	}))
	vi.stubGlobal('fetch', fetchMock)
	return fetchMock
}

beforeEach(() => {
	clearToken()
	window.history.replaceState({}, '', '/apps/pipelinq/portal')
})

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('portalFetch', () => {
	it('sends the bearer token, the tenant and a JSON body, and answers the payload', async () => {
		setToken('tok-1', '2026-12-01T00:00:00Z')
		window.history.replaceState({}, '', '/apps/pipelinq/portal?tenant=zuiddrecht')
		const fetchMock = answer(200, { ok: 1 })
		expect(await portalFetch('POST', '/requests', { title: 'x' })).toEqual({ ok: 1 })
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/pipelinq/portal/api/requests', {
			method: 'POST',
			headers: { Accept: 'application/json', Authorization: 'Bearer tok-1', 'X-Portal-Tenant': 'zuiddrecht', 'Content-Type': 'application/json' },
			body: '{"title":"x"}',
		})
	})

	it('sends no token, tenant or body when there are none', async () => {
		const fetchMock = answer(200, [1])
		expect(await portalFetch('GET', '/delegations')).toEqual([1])
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/pipelinq/portal/api/delegations', { method: 'GET', headers: { Accept: 'application/json' }, body: undefined })
	})

	it('rejects a refusal with its status, its message and its payload', async () => {
		setToken('expired')
		answer(401, { errorCode: 'session_expired', message: 'Session expired' })
		await expect(portalFetch('GET', '/accounts/profile')).rejects.toMatchObject({ status: 401, errorCode: 'session_expired', message: 'Session expired' })
		answer(403, new Error('not json'))
		const refusal = await portalFetch('POST', '/exports').catch((e) => e)
		expect(refusal).toBeInstanceOf(Error)
		expect([refusal.status, refusal.message]).toEqual([403, 'HTTP 403'])
	})

	it('answers an empty payload for a success without JSON', async () => {
		answer(204, new Error('empty'))
		expect(await portalFetch('POST', '/auth/logout')).toEqual({})
	})
})

describe('portalApi', () => {
	it('asks each endpoint with its method, path and body', async () => {
		const fetchMock = answer(200, {})
		const calls = [
			[() => portalApi.tenantConfig(), 'GET', '/tenant-config', undefined],
			[() => portalApi.login('a@b.nl', 'pw', '123456'), 'POST', '/auth/login', { email: 'a@b.nl', password: 'pw', totpCode: '123456' }],
			[() => portalApi.logout(), 'POST', '/auth/logout', undefined],
			[() => portalApi.extendSession(), 'POST', '/auth/extend-session', undefined],
			[() => portalApi.passwordResetRequest('a@b.nl'), 'POST', '/auth/password-reset-request', { email: 'a@b.nl' }],
			[() => portalApi.passwordReset('t', 'p'), 'POST', '/auth/password-reset', { token: 't', password: 'p' }],
			[() => portalApi.mfaEnroll(), 'POST', '/auth/mfa-enroll', undefined],
			[() => portalApi.mfaVerify('9'), 'POST', '/auth/mfa-verify', { code: '9' }],
			[() => portalApi.profile(), 'GET', '/accounts/profile', undefined],
			[() => portalApi.updateProfile({ a: 1 }), 'PUT', '/accounts/profile', { a: 1 }],
			[() => portalApi.invoices(), 'GET', '/invoices?page=1', undefined],
			[() => portalApi.contracts(2), 'GET', '/contracts?page=2', undefined],
			[() => portalApi.orders(3), 'GET', '/orders?page=3', undefined],
			[() => portalApi.requests(), 'GET', '/requests?page=1', undefined],
			[() => portalApi.request('a/b'), 'GET', '/requests/a%2Fb', undefined],
			[() => portalApi.submitRequest({ t: 1 }), 'POST', '/requests', { t: 1 }],
			[() => portalApi.replyRequest('r 1', 'hi'), 'POST', '/requests/r%201/reply', { message: 'hi' }],
			[() => portalApi.signDocument('o1', 'contract'), 'POST', '/documents/sign', { objectId: 'o1', objectType: 'contract' }],
			[() => portalApi.delegations(), 'GET', '/delegations', undefined],
			[() => portalApi.grantDelegation({ g: 1 }), 'POST', '/delegations', { g: 1 }],
			[() => portalApi.revokeDelegation('d/1'), 'DELETE', '/delegations/d%2F1', undefined],
			[() => portalApi.requestExport(), 'POST', '/exports', undefined],
			[() => portalApi.requestClose(), 'POST', '/accounts/close', undefined],
		]
		for (const [call, method, path, body] of calls) {
			await call()
			const [url, init] = fetchMock.mock.calls.at(-1)
			expect([url, init.method, init.body === undefined ? undefined : JSON.parse(init.body)]).toEqual(['/index.php/apps/pipelinq/portal/api' + path, method, body])
		}
		expect(fetchMock).toHaveBeenCalledTimes(calls.length)
	})
})

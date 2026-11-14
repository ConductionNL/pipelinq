// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The customer portal's shell: which routes are public, the guard that sends
 * a visitor without a live session to sign-in, and the app frame that loads
 * the tenant's branding and signs out. The session is the real portalApi
 * token in sessionStorage; only the network calls are stubbed.
 *
 * @spec exclude client-side portal session handling; no requirement governs it (customer-portal)
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text) => String(text)
})

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const remote = vi.hoisted(() => ({ tenantConfig: vi.fn(), profile: vi.fn(), logout: vi.fn() }))
vi.mock('../../src/portal/portalApi.js', async (importOriginal) => {
	const actual = await importOriginal()
	return { ...actual, portalApi: remote }
})

const view = (name) => ({ default: { name, render: () => null } })
vi.mock('../../src/views/portal/BookingConfirmationPage.vue', () => view('BookingConfirmationPage'))
vi.mock('../../src/views/portal/BookingPortal.vue', () => view('BookingPortal'))
vi.mock('../../src/views/surveys/PublicSurveyForm.vue', () => view('PublicSurveyForm'))
vi.mock('../../src/portal/views/PortalDashboard.vue', () => view('PortalDashboard'))
vi.mock('../../src/portal/views/PortalDelegations.vue', () => view('PortalDelegations'))
vi.mock('../../src/portal/views/PortalExport.vue', () => view('PortalExport'))
vi.mock('../../src/portal/views/PortalLogin.vue', () => view('PortalLogin'))
vi.mock('../../src/portal/views/PortalPasswordReset.vue', () => view('PortalPasswordReset'))
vi.mock('../../src/portal/views/PortalProfile.vue', () => view('PortalProfile'))
vi.mock('../../src/portal/views/PortalRequests.vue', () => view('PortalRequests'))
vi.mock('../../src/portal/views/PortalWidget.vue', () => view('PortalWidget'))
vi.mock('../../src/portal/components/PortalSessionWarning.vue', () => view('PortalSessionWarning'))

const { installPortalGuard, isAuthenticated, portalRoutes } = await import('../../src/portal/portalRoutes.js')
const { setToken, getToken } = await import('../../src/portal/portalApi.js')
const { default: PortalApp } = await import('../../src/portal/PortalApp.vue')

const NOW = Date.parse('2026-06-01T12:00:00Z')

/**
 * Run the guard for one route and return where it sends the visitor.
 *
 * @param {object} to the target route
 * @return {*} the `next` argument: undefined to proceed, a path to redirect
 */
function guard(to) {
	let registered
	installPortalGuard({ beforeEach: (fn) => { registered = fn } })
	let decision = 'not called'
	registered(to, {}, (arg) => { decision = arg })
	return decision
}

/**
 * The app frame's `this`.
 *
 * @return {object} the context
 */
function app() {
	const ctx = { $router: { push: vi.fn() } }
	for (const [name, fn] of Object.entries(PortalApp.methods)) ctx[name] = fn.bind(ctx)
	Object.assign(ctx, PortalApp.data.call(ctx))
	for (const [name, def] of Object.entries(PortalApp.computed)) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(NOW)
	window.sessionStorage.clear()
	document.documentElement.removeAttribute('style')
	for (const fn of Object.values(remote)) fn.mockReset()
})

afterEach(() => {
	vi.useRealTimers()
})

describe('portal routes', () => {
	it('opens on the dashboard and keeps sign-in, reset, widget, booking and survey public', () => {
		expect(portalRoutes[0]).toEqual({ path: '/', redirect: '/dashboard' })
		const isPublic = Object.fromEntries(portalRoutes.filter((r) => r.component).map((r) => [r.path, r.meta?.public === true]))
		expect(isPublic).toEqual({
			'/login': true,
			'/password-reset': true,
			'/widget': true,
			'/book/:serviceSlug': true,
			'/booking-confirmation/:bookingId': true,
			'/survey/:token': true,
			'/dashboard': false,
			'/requests': false,
			'/profile': false,
			'/delegations': false,
			'/export': false,
		})
	})
})

describe('portal session', () => {
	it('is signed in with a token that has not expired, or that has no expiry', () => {
		expect(isAuthenticated()).toBe(false)
		setToken('tok', '2026-06-01T12:00:01Z')
		expect(isAuthenticated()).toBe(true)
		vi.setSystemTime(NOW + 1000)
		expect(isAuthenticated()).toBe(false)
		window.sessionStorage.clear()
		setToken('tok')
		expect(isAuthenticated()).toBe(true)
	})

	it('lets anyone through to a public route', () => {
		expect(guard({ path: '/login', meta: { public: true } })).toBeUndefined()
	})

	it('sends a visitor without a live session to sign-in', () => {
		expect(guard({ path: '/profile', meta: {} })).toBe('/login')
		expect(guard({ path: '/profile' })).toBe('/login')
		setToken('tok', '2026-06-01T11:59:59Z')
		expect(guard({ path: '/profile', meta: {} })).toBe('/login')
	})

	it('lets a signed-in visitor through to a private route', () => {
		setToken('tok', '2026-06-02T00:00:00Z')
		expect(guard({ path: '/profile', meta: {} })).toBeUndefined()
	})
})

describe('PortalApp', () => {
	it('applies the tenant branding colours and reads the account type when signed in', async () => {
		setToken('tok')
		remote.tenantConfig.mockResolvedValue({ displayName: 'Gemeente', brandPrimaryColor: '#123456', brandSecondaryColor: '#abcdef' })
		remote.profile.mockResolvedValue({ accountType: 'b2b' })
		const vm = app()
		expect(vm.branding).toEqual({ displayName: 'Customer portal', brandPrimaryColor: '#21468B' })
		await PortalApp.mounted.call(vm)
		expect(vm.branding.displayName).toBe('Gemeente')
		expect(document.documentElement.style.getPropertyValue('--portal-brand-primary')).toBe('#123456')
		expect(document.documentElement.style.getPropertyValue('--portal-brand-secondary')).toBe('#abcdef')
		expect(vm.isB2b).toBe(true)
		expect(vm.hasToken()).toBe(true)
	})

	it('keeps the default branding and skips the profile when signed out or failing', async () => {
		remote.tenantConfig.mockRejectedValue(new Error('offline'))
		const vm = app()
		await PortalApp.mounted.call(vm)
		expect(vm.branding.brandPrimaryColor).toBe('#21468B')
		expect(document.documentElement.style.getPropertyValue('--portal-brand-primary')).toBe('')
		expect(remote.profile).not.toHaveBeenCalled()
		expect(vm.hasToken()).toBe(false)

		setToken('tok')
		remote.tenantConfig.mockResolvedValue({ displayName: 'Gemeente' })
		remote.profile.mockRejectedValue(new Error('expired'))
		const signedIn = app()
		await PortalApp.mounted.call(signedIn)
		expect(signedIn.isB2b).toBe(false)
		expect(document.documentElement.style.getPropertyValue('--portal-brand-primary')).toBe('')
	})

	it('signing out clears the session even when the server call fails', async () => {
		setToken('tok', '2026-06-02T00:00:00Z')
		remote.logout.mockRejectedValue(new Error('offline'))
		const vm = app()
		await vm.logout()
		expect(getToken()).toBeNull()
		expect(window.sessionStorage.getItem('pipelinq-portal-expires')).toBeNull()
		expect(vm.$router.push).toHaveBeenCalledWith('/login')
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal session: the route guard that sends an unauthenticated visitor
 * to the login page, and the warning that counts down the last minute,
 * extends the session, logs out, or ends it when time runs out.
 *
 * @spec openspec/specs/customer-portal/spec.md
 */

import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
for (const view of [
	'../../src/views/portal/BookingConfirmationPage.vue',
	'../../src/views/portal/BookingPortal.vue',
	'../../src/views/surveys/PublicSurveyForm.vue',
	'../../src/portal/views/PortalDashboard.vue',
	'../../src/portal/views/PortalDelegations.vue',
	'../../src/portal/views/PortalExport.vue',
	'../../src/portal/views/PortalLogin.vue',
	'../../src/portal/views/PortalPasswordReset.vue',
	'../../src/portal/views/PortalProfile.vue',
	'../../src/portal/views/PortalRequests.vue',
	'../../src/portal/views/PortalWidget.vue',
]) {
	vi.doMock(view, () => ({ default: { name: view } }))
}

const { clearToken, getToken, portalApi, setToken } = await import('../../src/portal/portalApi.js')
const { installPortalGuard, isAuthenticated } = await import('../../src/portal/portalRoutes.js')
const { default: PortalSessionWarning } = await import('../../src/portal/components/PortalSessionWarning.vue')

const NOW = Date.parse('2026-11-13T10:00:00Z')

/**
 * A session token that expires the given number of seconds from NOW.
 *
 * @param {number} seconds Seconds left.
 */
function sessionFor(seconds) {
	setToken('tok-1', new Date(NOW + seconds * 1000).toISOString())
}

beforeEach(() => {
	vi.useFakeTimers()
	vi.setSystemTime(NOW)
	window.sessionStorage.clear()
})

afterEach(() => {
	vi.useRealTimers()
	vi.restoreAllMocks()
})

describe('portal route guard', () => {
	it('is authenticated with a token that has not expired, or has no expiry', () => {
		expect(isAuthenticated()).toBe(false)

		setToken('tok-1')
		expect(isAuthenticated()).toBe(true)

		sessionFor(30)
		expect(isAuthenticated()).toBe(true)

		sessionFor(-1)
		expect(isAuthenticated()).toBe(false)
	})

	it('lets public routes through, and sends everyone else without a session to the login page', () => {
		let guard
		installPortalGuard({ beforeEach: (fn) => { guard = fn } })
		const next = vi.fn()

		guard({ meta: { public: true } }, {}, next)
		guard({ meta: {} }, {}, next)
		sessionFor(600)
		guard({}, {}, next)

		expect(next.mock.calls).toEqual([[], ['/login'], []])
	})
})

describe('portal session warning', () => {
	/**
	 * Mount the warning on the given route.
	 *
	 * @param {string} path The current route path.
	 * @return {object} The wrapper and its router.
	 */
	function mountWarning(path = '/dashboard') {
		const router = { currentRoute: { value: { path } }, push: vi.fn() }
		const wrapper = mount(PortalSessionWarning, {
			global: { mocks: { $router: router, t: (app, text, vars = {}) => text.replace('{seconds}', vars.seconds) } },
		})
		return { wrapper, router }
	}

	it('stays hidden while more than a minute is left, then counts down', async () => {
		sessionFor(120)
		const { wrapper } = mountWarning()

		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)

		vi.setSystemTime(NOW + 75 * 1000)
		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('[role="alert"]').text()).toContain('Your session expires in 44 seconds.')
	})

	it('hides without a session', async () => {
		const { wrapper } = mountWarning()
		wrapper.vm.visible = true

		await vi.advanceTimersByTimeAsync(1000)

		expect(wrapper.vm.visible).toBe(false)
	})

	it('ends the session and goes to the login page when time runs out', async () => {
		sessionFor(1)
		const { wrapper, router } = mountWarning()

		vi.setSystemTime(NOW + 2000)
		await vi.advanceTimersByTimeAsync(1000)

		expect(getToken()).toBeNull()
		expect(wrapper.vm.visible).toBe(false)
		expect(router.push).toHaveBeenCalledWith('/login')
	})

	it('does not navigate when already on the login page', () => {
		const { wrapper, router } = mountWarning('/login')

		wrapper.vm.expire()

		expect(router.push).not.toHaveBeenCalled()
	})

	it('extends the session with the new expiry and hides', async () => {
		sessionFor(30)
		vi.spyOn(portalApi, 'extendSession').mockResolvedValue({ expiresAt: new Date(NOW + 3600 * 1000).toISOString() })
		const { wrapper } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)

		await wrapper.findAll('button')[1].trigger('click')
		await vi.advanceTimersByTimeAsync(0)

		expect(getToken()).toBe('tok-1')
		expect(wrapper.vm.visible).toBe(false)
		expect(window.sessionStorage.getItem('pipelinq-portal-expires')).toBe(new Date(NOW + 3600 * 1000).toISOString())
	})

	it('ends the session when it cannot be extended', async () => {
		sessionFor(30)
		vi.spyOn(portalApi, 'extendSession').mockRejectedValue(new Error('401'))
		const { wrapper, router } = mountWarning()

		await wrapper.vm.extend()

		expect(getToken()).toBeNull()
		expect(router.push).toHaveBeenCalledWith('/login')
	})

	it('logs out, even when the server call fails', async () => {
		sessionFor(30)
		const logout = vi.spyOn(portalApi, 'logout').mockRejectedValue(new Error('offline'))
		const { wrapper, router } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)

		await wrapper.findAll('button')[0].trigger('click')
		await vi.advanceTimersByTimeAsync(0)

		expect(logout).toHaveBeenCalledOnce()
		expect(getToken()).toBeNull()
		expect(router.push).toHaveBeenCalledWith('/login')
	})

	it('stops its timer when it goes away', () => {
		const clear = vi.spyOn(window, 'clearInterval')
		const { wrapper } = mountWarning()

		wrapper.unmount()

		expect(clear).toHaveBeenCalledOnce()
		clearToken()
	})
})

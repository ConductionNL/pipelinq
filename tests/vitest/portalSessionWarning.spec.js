// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal's session warning: hidden while the session has more than a
 * minute left or no token, a countdown in its last minute, extending stores
 * the new expiry and hides it, and an expired session, a failed extension or
 * a log-out clear the token and go to the login page (unless already there).
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	token: 'tok',
	expiry: 0,
	clearToken: vi.fn(),
	setToken: vi.fn(),
	extendSession: vi.fn(),
	logout: vi.fn(),
}))
vi.mock('../../src/portal/portalApi.js', () => ({
	getToken: () => api.token,
	getExpiry: () => api.expiry,
	clearToken: api.clearToken,
	setToken: api.setToken,
	portalApi: { extendSession: api.extendSession, logout: api.logout },
}))

const { default: PortalSessionWarning } =
	await import('../../src/portal/components/PortalSessionWarning.vue')

function t (app, text, vars) {
  return text.replace(/\{(\w+)\}/g, (m, k) => (vars && k in vars ? vars[k] : m))
}

function mountWarning(path = '/requests') {
	const push = vi.fn()
	const wrapper = mount(PortalSessionWarning, {
		global: {
			mocks: { t, $router: { push, currentRoute: { value: { path } } } },
		},
	})
	return { wrapper, push }
}

function expiresIn (seconds) {
	api.expiry = Date.now() + seconds * 1000
}

describe('the portal session warning', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		api.token = 'tok'
		api.clearToken.mockReset()
		api.setToken.mockReset()
		api.extendSession.mockReset()
		api.logout.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
	})

	it('stays hidden with more than a minute left, and counts down in the last minute', async () => {
		expiresIn(300)
		const { wrapper } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)

		expiresIn(46)
		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('[role="alert"] span').text()).toBe(
			'Your session expires in 45 seconds.',
		)

		expiresIn(120)
		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
	})

	it('stays hidden without a token', async () => {
		api.token = ''
		expiresIn(10)
		const { wrapper, push } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(push).not.toHaveBeenCalled()
	})

	it('extends the session with the new expiry and hides', async () => {
		api.extendSession.mockResolvedValue({ expiresAt: 1234 })
		expiresIn(30)
		const { wrapper } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)
		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()
		expect(api.setToken).toHaveBeenCalledWith('tok', 1234)
		expect(wrapper.find('[role="alert"]').exists()).toBe(false)
		expect(api.clearToken).not.toHaveBeenCalled()
	})

	it('ends the session when extending fails', async () => {
		api.extendSession.mockRejectedValue(new Error('gone'))
		expiresIn(30)
		const { wrapper, push } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)
		await wrapper.findAll('button')[1].trigger('click')
		await flushPromises()
		expect(api.clearToken).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith('/login')
	})

	it('logs out, even when the server call fails', async () => {
		api.logout.mockRejectedValue(new Error('offline'))
		expiresIn(30)
		const { wrapper, push } = mountWarning()
		await vi.advanceTimersByTimeAsync(1000)
		await wrapper.findAll('button')[0].trigger('click')
		await flushPromises()
		expect(api.logout).toHaveBeenCalledTimes(1)
		expect(api.clearToken).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith('/login')
	})

	it('ends an expired session, without navigating when already on the login page', async () => {
		expiresIn(-5)
		const { push } = mountWarning('/login')
		await vi.advanceTimersByTimeAsync(1000)
		expect(api.clearToken).toHaveBeenCalledTimes(1)
		expect(push).not.toHaveBeenCalled()
	})

	it('stops ticking once removed', async () => {
		expiresIn(-5)
		const { wrapper } = mountWarning()
		wrapper.unmount()
		await vi.advanceTimersByTimeAsync(3000)
		expect(api.clearToken).not.toHaveBeenCalled()
	})
})

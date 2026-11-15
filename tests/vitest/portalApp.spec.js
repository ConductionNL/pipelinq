// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The portal shell: tenant branding applied as CSS variables, the header
 * only for a signed-in visitor, the shared-access link only for a business
 * account, and logout.
 *
 * @spec openspec/specs/customer-portal/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('../../src/portal/portalRoutes.js', async () => {
	const api = await import('../../src/portal/portalApi.js')
	return { isAuthenticated: () => api.getToken() !== null }
})
vi.mock('../../src/portal/components/PortalSessionWarning.vue', () => ({ default: { name: 'PortalSessionWarning', template: '<div class="session-warning" />' } }))

globalThis.t = (app, text) => text

const { getToken, portalApi, setToken } = await import('../../src/portal/portalApi.js')
const { default: PortalApp } = await import('../../src/portal/PortalApp.vue')

/**
 * Mount the shell and let its mounted hook finish.
 *
 * @return {Promise<object>} The wrapper and its router.
 */
async function mountApp() {
	const router = { push: vi.fn() }
	const wrapper = mount(PortalApp, {
		global: {
			mocks: { $router: router, t: globalThis.t },
			stubs: { 'router-link': { props: ['to'], template: '<a :href="to"><slot /></a>' }, 'router-view': { template: '<div class="view" />' } },
		},
	})
	await flushPromises()
	return { wrapper, router }
}

beforeEach(() => {
	window.sessionStorage.clear()
	document.documentElement.style.removeProperty('--portal-brand-primary')
	document.documentElement.style.removeProperty('--portal-brand-secondary')
})

afterEach(() => vi.restoreAllMocks())

describe('portal shell', () => {
	it('applies the tenant branding and shows no header to a visitor who is not signed in', async () => {
		vi.spyOn(portalApi, 'tenantConfig').mockResolvedValue({ displayName: 'Gemeente Zuiddrecht', brandPrimaryColor: '#004488', brandSecondaryColor: '#ffcc00' })
		const profile = vi.spyOn(portalApi, 'profile')

		const { wrapper } = await mountApp()

		expect(document.documentElement.style.getPropertyValue('--portal-brand-primary')).toBe('#004488')
		expect(document.documentElement.style.getPropertyValue('--portal-brand-secondary')).toBe('#ffcc00')
		expect(wrapper.find('header').exists()).toBe(false)
		expect(profile).not.toHaveBeenCalled()
		expect(wrapper.find('.session-warning').exists()).toBe(true)
	})

	it('keeps the default branding when the tenant config cannot be read', async () => {
		vi.spyOn(portalApi, 'tenantConfig').mockRejectedValue(new Error('offline'))
		setToken('tok-1')
		vi.spyOn(portalApi, 'profile').mockRejectedValue(new Error('offline'))

		const { wrapper } = await mountApp()

		expect(wrapper.find('.portal-brand').text()).toBe('Customer portal')
		expect(document.documentElement.style.getPropertyValue('--portal-brand-primary')).toBe('')
		expect(wrapper.find('a[href="/delegations"]').exists()).toBe(false)
	})

	it('shows the shared-access link only to a business account', async () => {
		vi.spyOn(portalApi, 'tenantConfig').mockResolvedValue({ displayName: 'Gemeente Zuiddrecht' })
		setToken('tok-1')
		vi.spyOn(portalApi, 'profile').mockResolvedValueOnce({ accountType: 'b2b' }).mockResolvedValueOnce({ accountType: 'b2c' })

		const business = (await mountApp()).wrapper
		expect(business.find('.portal-brand').text()).toBe('Gemeente Zuiddrecht')
		expect(business.find('a[href="/delegations"]').exists()).toBe(true)

		const consumer = (await mountApp()).wrapper
		expect(consumer.find('a[href="/delegations"]').exists()).toBe(false)
		expect(consumer.findAll('nav a').map((a) => a.attributes('href'))).toEqual(['/dashboard', '/requests', '/profile', '/export'])
	})

	it('logs out, even when the server call fails, and goes to the login page', async () => {
		vi.spyOn(portalApi, 'tenantConfig').mockResolvedValue({ displayName: 'X' })
		setToken('tok-1')
		vi.spyOn(portalApi, 'profile').mockResolvedValue({ accountType: 'b2c' })
		const logout = vi.spyOn(portalApi, 'logout').mockRejectedValue(new Error('offline'))
		const { wrapper, router } = await mountApp()

		expect(wrapper.vm.hasToken()).toBe(true)
		await wrapper.find('nav button').trigger('click')
		await flushPromises()

		expect(logout).toHaveBeenCalledOnce()
		expect(getToken()).toBeNull()
		expect(wrapper.vm.hasToken()).toBe(false)
		expect(router.push).toHaveBeenCalledWith('/login')
	})
})

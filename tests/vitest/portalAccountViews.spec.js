// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The customer portal's account pages: signing in (with or without a second
 * factor), resetting a password by email and by token, requesting a data
 * export or closing the account, granting and revoking a colleague's access,
 * and editing one's own details. The portal API is the HTTP boundary and is
 * doubled; every message the pages show is asserted.
 *
 * @spec openspec/specs/customer-portal/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
	portalApi: {
		login: vi.fn(),
		passwordResetRequest: vi.fn(),
		passwordReset: vi.fn(),
		requestExport: vi.fn(),
		requestClose: vi.fn(),
		delegations: vi.fn(),
		grantDelegation: vi.fn(),
		revokeDelegation: vi.fn(),
		profile: vi.fn(),
		updateProfile: vi.fn(),
	},
	setToken: vi.fn(),
}))
vi.mock('../../src/portal/portalApi.js', () => api)
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))

const translate = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
globalThis.t = translate

const { default: PortalLogin } = await import('../../src/portal/views/PortalLogin.vue')
const { default: PortalPasswordReset } = await import('../../src/portal/views/PortalPasswordReset.vue')
const { default: PortalExport } = await import('../../src/portal/views/PortalExport.vue')
const { default: PortalDelegations } = await import('../../src/portal/views/PortalDelegations.vue')
const { default: PortalProfile } = await import('../../src/portal/views/PortalProfile.vue')

let push

/**
 * @param {object} component The view.
 * @return {object} The mounted view.
 */
function mountView(component) {
	return mount(component, { global: { mocks: { t: translate, $router: { push } } } })
}

/**
 * @param {object} wrapper The view.
 * @param {string} label A button's text.
 * @return {object} The button.
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((b) => b.text() === label)
}

beforeEach(() => {
	push = vi.fn()
	api.setToken.mockReset()
	for (const fn of Object.values(api.portalApi)) {
		fn.mockReset()
	}
})

afterEach(() => {
	vi.useRealTimers()
	window.history.replaceState(null, '', '/')
})

describe('signing in', () => {
	it('stores the token and opens the dashboard once authenticated', async () => {
		api.portalApi.login.mockResolvedValueOnce({ status: 'authenticated', token: 'tok', expiresAt: '2026-12-01T00:00:00Z' })
		const wrapper = mountView(PortalLogin)
		await wrapper.find('#portal-email').setValue('jan@example.nl')
		await wrapper.find('#portal-password').setValue('secret')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(api.portalApi.login).toHaveBeenCalledWith({ email: 'jan@example.nl', password: 'secret', totpCode: null })
		expect(api.setToken).toHaveBeenCalledWith('tok', '2026-12-01T00:00:00Z')
		expect(push).toHaveBeenCalledWith('/dashboard')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('asks for the verification code, then sends it', async () => {
		api.portalApi.login
			.mockResolvedValueOnce({ status: 'mfa-required', mfaRequired: true })
			.mockResolvedValueOnce({ status: 'authenticated', token: 'tok2' })
		const wrapper = mountView(PortalLogin)
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Enter your verification code.')
		expect(wrapper.find('#portal-email').attributes('aria-describedby')).toBe('portal-login-error')
		await wrapper.find('#portal-totp').setValue('123456')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(api.portalApi.login.mock.calls[1][0].totpCode).toBe('123456')
		expect(push).toHaveBeenCalledWith('/dashboard')
	})

	it('says when two-factor setup is required, and does not sign in without a token', async () => {
		api.portalApi.login
			.mockResolvedValueOnce({ status: 'mfa-enrollment-required', mfaRequired: true })
			.mockResolvedValueOnce({ status: 'authenticated' })
		const wrapper = mountView(PortalLogin)

		await wrapper.vm.onSubmit()
		expect(wrapper.vm.error).toBe('Two-factor authentication setup is required.')
		await wrapper.vm.onSubmit()
		expect(wrapper.vm.error).toBe('')
		expect(api.setToken).not.toHaveBeenCalled()
		expect(push).not.toHaveBeenCalled()
	})

	it('shows the failure, else that the credentials are wrong', async () => {
		api.portalApi.login
			.mockRejectedValueOnce(new Error('Account locked.'))
			.mockRejectedValueOnce(new Error(''))
		const wrapper = mountView(PortalLogin)

		await wrapper.vm.onSubmit()
		expect(wrapper.vm.error).toBe('Account locked.')
		await wrapper.vm.onSubmit()
		expect(wrapper.vm.error).toBe('Email or password is incorrect.')
		expect(wrapper.vm.loading).toBe(false)
	})
})

describe('resetting a password', () => {
	it('asks for a reset link without a token, and gives the same answer whether the address exists', async () => {
		api.portalApi.passwordResetRequest.mockResolvedValueOnce({})
		const wrapper = mountView(PortalPasswordReset)
		expect(wrapper.find('#portal-new-password').exists()).toBe(false)

		await wrapper.find('#portal-reset-email').setValue('jan@example.nl')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(api.portalApi.passwordResetRequest).toHaveBeenCalledWith('jan@example.nl')
		expect(wrapper.find('[role="status"]').text()).toBe('If that email address exists, a reset link has been sent.')
	})

	it('shows why the reset link could not be requested', async () => {
		api.portalApi.passwordResetRequest.mockRejectedValueOnce(new Error('Too many requests.'))
		const wrapper = mountView(PortalPasswordReset)

		await wrapper.vm.requestReset()
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe('Too many requests.')
		expect(wrapper.vm.message).toBe('')
	})

	it('sets the new password with the token from the address, then goes to sign-in after two seconds', async () => {
		window.history.replaceState(null, '', '/portal?token=abc123')
		vi.useFakeTimers()
		api.portalApi.passwordReset.mockResolvedValueOnce({})
		const wrapper = mountView(PortalPasswordReset)

		await wrapper.find('#portal-new-password').setValue('a-long-password')
		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(api.portalApi.passwordReset).toHaveBeenCalledWith('abc123', 'a-long-password')
		expect(wrapper.vm.message).toBe('Your password has been reset. You can now sign in.')
		vi.advanceTimersByTime(1999)
		expect(push).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(push).toHaveBeenCalledWith('/login')
	})

	it('shows why the password could not be reset, else a general message', async () => {
		window.history.replaceState(null, '', '/portal?token=abc123')
		api.portalApi.passwordReset
			.mockRejectedValueOnce(new Error('The link has expired.'))
			.mockRejectedValueOnce(new Error(''))
		const wrapper = mountView(PortalPasswordReset)

		await wrapper.vm.doReset()
		expect(wrapper.vm.error).toBe('The link has expired.')
		await wrapper.vm.doReset()
		expect(wrapper.vm.error).toBe('Could not reset your password.')
		expect(push).not.toHaveBeenCalled()
	})
})

describe('privacy and data', () => {
	it('links the export once it is ready', async () => {
		api.portalApi.requestExport.mockResolvedValueOnce({ downloadUrl: '/portal/exports/e1/download' })
		const wrapper = mountView(PortalExport)

		await button(wrapper, 'Request my data').trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="status"] a').attributes('href')).toBe('/index.php/apps/pipelinq/portal/exports/e1/download')
	})

	it('shows why the export could not be requested, else a general message', async () => {
		api.portalApi.requestExport
			.mockRejectedValueOnce(new Error('Already running.'))
			.mockRejectedValueOnce(new Error(''))
		const wrapper = mountView(PortalExport)

		await wrapper.vm.requestExport()
		expect(wrapper.vm.error).toBe('Already running.')
		await wrapper.vm.requestExport()
		expect(wrapper.vm.error).toBe('Could not request the export.')
		expect(wrapper.vm.exportLink).toBe('')
	})

	it('asks before closing the account, and can be cancelled', async () => {
		const wrapper = mountView(PortalExport)

		await button(wrapper, 'Close my account').trigger('click')
		expect(wrapper.text()).toContain('Are you sure?')
		await button(wrapper, 'Cancel').trigger('click')

		expect(button(wrapper, 'Yes, close my account')).toBeUndefined()
		expect(api.portalApi.requestClose).not.toHaveBeenCalled()
	})

	it('requests closure on confirmation and says to check the email', async () => {
		api.portalApi.requestClose.mockResolvedValueOnce({})
		const wrapper = mountView(PortalExport)

		await button(wrapper, 'Close my account').trigger('click')
		await button(wrapper, 'Yes, close my account').trigger('click')
		await flushPromises()

		expect(api.portalApi.requestClose).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.confirming).toBe(false)
		expect(wrapper.find('[role="status"]').text()).toBe('Check your email to confirm closing your account.')
	})

	it('keeps asking when closure fails, and says why', async () => {
		api.portalApi.requestClose
			.mockRejectedValueOnce(new Error('Open requests remain.'))
			.mockRejectedValueOnce(new Error(''))
		const wrapper = mountView(PortalExport)
		wrapper.vm.confirming = true

		await wrapper.vm.requestClose()
		expect([wrapper.vm.error, wrapper.vm.confirming]).toEqual(['Open requests remain.', true])
		await wrapper.vm.requestClose()
		expect(wrapper.vm.error).toBe('Could not request account closure.')
	})
})

describe('delegations', () => {
	it('lists each delegation with its scopes and end date, or none', async () => {
		api.portalApi.delegations.mockResolvedValueOnce({
			delegations: [
				{ id: 'd1', granteeEmail: 'piet@example.nl', scopes: ['view-invoices', 'submit-requests'], validUntil: '2026-12-31' },
				{ granteeEmail: 'kees@example.nl' },
			],
		})
		const wrapper = mountView(PortalDelegations)
		await flushPromises()
		const rows = wrapper.findAll('tbody tr')

		expect(rows.map((row) => row.findAll('td').slice(0, 3).map((td) => td.text()))).toEqual([
			['piet@example.nl', 'view-invoices, submit-requests', '2026-12-31'],
			['kees@example.nl', '', 'No end date'],
		])
	})

	it('draws no table without delegations, and shows a failed read', async () => {
		api.portalApi.delegations.mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Session expired.'))
		const wrapper = mountView(PortalDelegations)
		await flushPromises()
		expect(wrapper.find('table').exists()).toBe(false)

		await wrapper.vm.load()
		expect(wrapper.vm.error).toBe('Session expired.')
	})

	it('grants access, clears the form and reloads', async () => {
		api.portalApi.delegations.mockResolvedValue({ delegations: [] })
		api.portalApi.grantDelegation.mockResolvedValueOnce({})
		const wrapper = mountView(PortalDelegations)
		await flushPromises()
		wrapper.vm.form = { granteeEmail: 'piet@example.nl', scopes: ['view-contracts'], validUntil: '' }

		await wrapper.vm.grant()
		await flushPromises()

		expect(api.portalApi.grantDelegation).toHaveBeenCalledWith({ granteeEmail: 'piet@example.nl', scopes: ['view-contracts'], validUntil: null })
		expect(wrapper.vm.message).toBe('Access has been granted.')
		expect(wrapper.vm.form).toEqual({ granteeEmail: '', scopes: [], validUntil: '' })
		expect(api.portalApi.delegations).toHaveBeenCalledTimes(2)
	})

	it('keeps the form when granting fails, and says why', async () => {
		api.portalApi.delegations.mockResolvedValue({ delegations: [] })
		api.portalApi.grantDelegation.mockRejectedValueOnce(new Error('Unknown colleague.')).mockRejectedValueOnce(new Error(''))
		const wrapper = mountView(PortalDelegations)
		await flushPromises()
		wrapper.vm.form = { granteeEmail: 'x@example.nl', scopes: [], validUntil: '2027-01-01' }

		await wrapper.vm.grant()
		expect(wrapper.vm.error).toBe('Unknown colleague.')
		expect(wrapper.vm.form.granteeEmail).toBe('x@example.nl')
		await wrapper.vm.grant()
		expect(wrapper.vm.error).toBe('Could not grant access.')
		expect(api.portalApi.grantDelegation.mock.calls[0][0].validUntil).toBe('2027-01-01')
	})

	it('revokes by id or by the record id, reloads, and shows a failed revoke', async () => {
		api.portalApi.delegations.mockResolvedValue({ delegations: [{ id: 'd1', granteeEmail: 'piet@example.nl' }] })
		api.portalApi.revokeDelegation.mockResolvedValueOnce({}).mockResolvedValueOnce({}).mockRejectedValueOnce(new Error('Not yours.'))
		const wrapper = mountView(PortalDelegations)
		await flushPromises()

		await wrapper.find('tbody button').trigger('click')
		await flushPromises()
		await wrapper.vm.revoke({ '@self': { id: 'd2' } })
		await wrapper.vm.revoke({ id: 'd3' })

		expect(api.portalApi.revokeDelegation.mock.calls.map((call) => call[0])).toEqual(['d1', 'd2', 'd3'])
		expect(api.portalApi.delegations).toHaveBeenCalledTimes(3)
		expect(wrapper.vm.error).toBe('Not yours.')
	})
})

describe('the profile', () => {
	it('fills the form from the profile, with defaults for what is missing', async () => {
		api.portalApi.profile.mockResolvedValueOnce({ displayName: 'Jan', email: 'jan@example.nl', pendingEmail: 'nieuw@example.nl' })
		const wrapper = mountView(PortalProfile)
		await flushPromises()

		expect(wrapper.vm.form).toEqual({ displayName: 'Jan', phone: '', address: '', jobTitle: '', locale: 'nl', email: 'jan@example.nl', accountType: 'b2c' })
		expect(wrapper.find('small').text()).toBe('A verification link has been sent to nieuw@example.nl.')
		expect(wrapper.find('#portal-jobtitle').exists()).toBe(false)
	})

	it('shows the job title only for a business account', async () => {
		api.portalApi.profile.mockResolvedValueOnce({ accountType: 'b2b', jobTitle: 'Inkoper', locale: 'en' })
		const wrapper = mountView(PortalProfile)
		await flushPromises()

		expect(wrapper.find('#portal-jobtitle').element.value).toBe('Inkoper')
		expect(wrapper.vm.form.locale).toBe('en')
	})

	it('shows a failed read', async () => {
		api.portalApi.profile.mockRejectedValueOnce(new Error('Session expired.'))
		const wrapper = mountView(PortalProfile)
		await flushPromises()

		expect(wrapper.vm.error).toBe('Session expired.')
	})

	it('saves the form and shows the pending email from the answer', async () => {
		api.portalApi.profile.mockResolvedValueOnce({ displayName: 'Jan' })
		api.portalApi.updateProfile.mockResolvedValueOnce({ pendingEmail: 'nieuw@example.nl' })
		const wrapper = mountView(PortalProfile)
		await flushPromises()
		await wrapper.find('#portal-name').setValue('Jan Jansen')

		await wrapper.find('form').trigger('submit')
		await flushPromises()

		expect(api.portalApi.updateProfile.mock.calls[0][0]).toMatchObject({ displayName: 'Jan Jansen', locale: 'nl', accountType: 'b2c' })
		expect(api.portalApi.updateProfile.mock.calls[0][0]).not.toBe(wrapper.vm.form)
		expect(wrapper.vm.pendingEmail).toBe('nieuw@example.nl')
		expect(wrapper.find('[role="status"]').text()).toBe('Your details have been saved.')
	})

	it('shows why saving failed, else a general message', async () => {
		api.portalApi.profile.mockResolvedValueOnce({})
		api.portalApi.updateProfile.mockRejectedValueOnce(new Error('Phone number is invalid.')).mockRejectedValueOnce(new Error(''))
		const wrapper = mountView(PortalProfile)
		await flushPromises()

		await wrapper.vm.save()
		expect(wrapper.vm.error).toBe('Phone number is invalid.')
		await wrapper.vm.save()
		expect([wrapper.vm.error, wrapper.vm.message]).toEqual(['Could not save your details.', ''])
	})
})

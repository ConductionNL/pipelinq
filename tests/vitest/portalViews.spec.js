// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The customer portal's own views, driven through their methods against a
 * stubbed portalApi: signing in (including the two-factor states), password
 * reset, the data export and account closure, delegations, the profile, and
 * the embeddable widget's messages to its host page.
 *
 * @spec exclude client-side behaviour of the customer portal views; no requirement governs it (customer-portal)
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
})

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))

const portalApi = vi.hoisted(() => ({
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
}))
const tokens = vi.hoisted(() => ({ setToken: vi.fn(), getToken: vi.fn() }))
vi.mock('../../src/portal/portalApi.js', () => ({ portalApi, ...tokens }))
vi.mock('../../src/portal/views/PortalDashboard.vue', () => ({ default: { name: 'PortalDashboard', render: () => null } }))

const { default: PortalLogin } = await import('../../src/portal/views/PortalLogin.vue')
const { default: PortalPasswordReset } = await import('../../src/portal/views/PortalPasswordReset.vue')
const { default: PortalExport } = await import('../../src/portal/views/PortalExport.vue')
const { default: PortalDelegations } = await import('../../src/portal/views/PortalDelegations.vue')
const { default: PortalProfile } = await import('../../src/portal/views/PortalProfile.vue')
const { default: PortalWidget } = await import('../../src/portal/views/PortalWidget.vue')

/**
 * A component's `this`: its data, bound methods and computed getters.
 *
 * @param {object} comp the component options
 * @param {object} [extra] extra instance fields
 * @return {object} the context
 */
function context(comp, extra = {}) {
	const ctx = { $router: { push: vi.fn() }, ...extra }
	for (const [name, fn] of Object.entries(comp.methods || {})) {
		ctx[name] = fn.bind(ctx)
	}
	Object.assign(ctx, comp.data ? comp.data.call(ctx) : {})
	for (const [name, def] of Object.entries(comp.computed || {})) {
		Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
	}
	return ctx
}

beforeEach(() => {
	for (const fn of [...Object.values(portalApi), ...Object.values(tokens)]) fn.mockReset()
})

describe('PortalLogin', () => {
	it('stores the token and opens the dashboard once authenticated', async () => {
		portalApi.login.mockResolvedValue({ status: 'authenticated', token: 'tok', expiresAt: '2026-12-01T00:00:00Z' })
		const vm = context(PortalLogin)
		Object.assign(vm, { email: 'a@b.nl', password: 'secret' })
		await vm.onSubmit()
		expect(portalApi.login).toHaveBeenCalledWith({ email: 'a@b.nl', password: 'secret', totpCode: null })
		expect(tokens.setToken).toHaveBeenCalledWith('tok', '2026-12-01T00:00:00Z')
		expect(vm.$router.push).toHaveBeenCalledWith('/dashboard')
		expect(vm.loading).toBe(false)
	})

	it('asks for the verification code, or for two-factor setup', async () => {
		const vm = context(PortalLogin)
		portalApi.login.mockResolvedValue({ status: 'mfa-required', mfaRequired: true })
		await vm.onSubmit()
		expect(vm.mfaRequired).toBe(true)
		expect(vm.error).toBe('Enter your verification code.')

		vm.totpCode = '123456'
		portalApi.login.mockResolvedValue({ status: 'mfa-enrollment-required', mfaRequired: true })
		await vm.onSubmit()
		expect(portalApi.login.mock.calls[1][0].totpCode).toBe('123456')
		expect(vm.error).toBe('Two-factor authentication setup is required.')
		expect(tokens.setToken).not.toHaveBeenCalled()
		expect(vm.$router.push).not.toHaveBeenCalled()
	})

	it('an authenticated answer without a token signs nobody in', async () => {
		portalApi.login.mockResolvedValue({ status: 'authenticated' })
		const vm = context(PortalLogin)
		await vm.onSubmit()
		expect(tokens.setToken).not.toHaveBeenCalled()
		expect(vm.$router.push).not.toHaveBeenCalled()
		expect(vm.error).toBe('')
	})

	it('a refused sign-in shows the server message, else a general one', async () => {
		const vm = context(PortalLogin)
		portalApi.login.mockRejectedValue(new Error('Account locked.'))
		await vm.onSubmit()
		expect(vm.error).toBe('Account locked.')
		portalApi.login.mockRejectedValue(new Error(''))
		await vm.onSubmit()
		expect(vm.error).toBe('Email or password is incorrect.')
		expect(vm.loading).toBe(false)
	})
})

describe('PortalPasswordReset', () => {
	afterEach(() => {
		vi.useRealTimers()
		window.history.replaceState({}, '', '/')
	})

	it('reads the reset token from the link', () => {
		window.history.replaceState({}, '', '/?token=reset-1')
		expect(context(PortalPasswordReset).token).toBe('reset-1')
		window.history.replaceState({}, '', '/')
		expect(context(PortalPasswordReset).token).toBe('')
	})

	it('a reset request never says whether the address exists', async () => {
		portalApi.passwordResetRequest.mockResolvedValue({})
		const vm = context(PortalPasswordReset)
		vm.email = 'a@b.nl'
		await vm.requestReset()
		expect(portalApi.passwordResetRequest).toHaveBeenCalledWith('a@b.nl')
		expect(vm.message).toBe('If that email address exists, a reset link has been sent.')

		portalApi.passwordResetRequest.mockRejectedValue(new Error('Too many requests.'))
		await vm.requestReset()
		expect(vm.message).toBe('')
		expect(vm.error).toBe('Too many requests.')
	})

	it('a reset sets the new password and returns to sign-in after two seconds', async () => {
		vi.useFakeTimers()
		portalApi.passwordReset.mockResolvedValue({})
		const vm = context(PortalPasswordReset, {})
		Object.assign(vm, { token: 'reset-1', password: 'new-secret' })
		await vm.doReset()
		expect(portalApi.passwordReset).toHaveBeenCalledWith('reset-1', 'new-secret')
		expect(vm.message).toBe('Your password has been reset. You can now sign in.')
		vi.advanceTimersByTime(1999)
		expect(vm.$router.push).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		expect(vm.$router.push).toHaveBeenCalledWith('/login')
	})

	it('a refused reset says so', async () => {
		const vm = context(PortalPasswordReset)
		portalApi.passwordReset.mockRejectedValue(new Error('The link has expired.'))
		await vm.doReset()
		expect(vm.error).toBe('The link has expired.')
		portalApi.passwordReset.mockRejectedValue(new Error(''))
		await vm.doReset()
		expect(vm.error).toBe('Could not reset your password.')
		expect(vm.message).toBe('')
	})
})

describe('PortalExport', () => {
	it('links the export under the app', async () => {
		portalApi.requestExport.mockResolvedValue({ downloadUrl: '/portal/api/export/x1' })
		const vm = context(PortalExport)
		await vm.requestExport()
		expect(vm.exportLink).toBe('/index.php/apps/pipelinq/portal/api/export/x1')
		expect(vm.error).toBe('')
	})

	it('a failed export says so', async () => {
		const vm = context(PortalExport)
		portalApi.requestExport.mockRejectedValue(new Error(''))
		await vm.requestExport()
		expect(vm.error).toBe('Could not request the export.')
		expect(vm.exportLink).toBe('')
	})

	it('closing asks for confirmation by email', async () => {
		portalApi.requestClose.mockResolvedValue({})
		const vm = context(PortalExport)
		vm.confirming = true
		await vm.requestClose()
		expect(vm.confirming).toBe(false)
		expect(vm.closeMessage).toBe('Check your email to confirm closing your account.')

		portalApi.requestClose.mockRejectedValue(new Error('Open requests remain.'))
		vm.confirming = true
		await vm.requestClose()
		expect(vm.error).toBe('Open requests remain.')
		expect(vm.confirming).toBe(true)
		portalApi.requestClose.mockRejectedValue(new Error(''))
		await vm.requestClose()
		expect(vm.error).toBe('Could not request account closure.')
	})
})

describe('PortalDelegations', () => {
	it('loads the delegations, none when the answer has none', async () => {
		portalApi.delegations.mockResolvedValue({ delegations: [{ id: 'd1' }] })
		const vm = context(PortalDelegations)
		await vm.load()
		expect(vm.delegations).toEqual([{ id: 'd1' }])
		portalApi.delegations.mockResolvedValue({})
		await vm.load()
		expect(vm.delegations).toEqual([])
		portalApi.delegations.mockRejectedValue(new Error('Session expired.'))
		await vm.load()
		expect(vm.error).toBe('Session expired.')
	})

	it('grants access, clears the form and reloads', async () => {
		portalApi.grantDelegation.mockResolvedValue({})
		portalApi.delegations.mockResolvedValue({ delegations: [{ id: 'd2' }] })
		const vm = context(PortalDelegations)
		vm.form = { granteeEmail: 'b@c.nl', scopes: ['requests'], validUntil: '' }
		await vm.grant()
		expect(portalApi.grantDelegation).toHaveBeenCalledWith({ granteeEmail: 'b@c.nl', scopes: ['requests'], validUntil: null })
		expect(vm.message).toBe('Access has been granted.')
		expect(vm.form).toEqual({ granteeEmail: '', scopes: [], validUntil: '' })
		expect(vm.delegations).toEqual([{ id: 'd2' }])
	})

	it('a refused grant keeps the form and says why', async () => {
		portalApi.grantDelegation.mockRejectedValue(new Error(''))
		const vm = context(PortalDelegations)
		vm.form = { granteeEmail: 'b@c.nl', scopes: [], validUntil: '2027-01-01' }
		await vm.grant()
		expect(portalApi.grantDelegation.mock.calls[0][0].validUntil).toBe('2027-01-01')
		expect(vm.error).toBe('Could not grant access.')
		expect(vm.form.granteeEmail).toBe('b@c.nl')
		expect(portalApi.delegations).not.toHaveBeenCalled()
	})

	it('revokes by id or by the object id, then reloads', async () => {
		portalApi.revokeDelegation.mockResolvedValue({})
		portalApi.delegations.mockResolvedValue({ delegations: [] })
		const vm = context(PortalDelegations)
		await vm.revoke({ id: 'd1' })
		await vm.revoke({ '@self': { id: 'd2' } })
		expect(portalApi.revokeDelegation.mock.calls).toEqual([['d1'], ['d2']])
		expect(portalApi.delegations).toHaveBeenCalledTimes(2)
		portalApi.revokeDelegation.mockRejectedValue(new Error('Not yours.'))
		await vm.revoke({ id: 'd3' })
		expect(vm.error).toBe('Not yours.')
	})
})

describe('PortalProfile', () => {
	it('loads the profile with defaults for what is missing', async () => {
		portalApi.profile.mockResolvedValue({ displayName: 'Anna', email: 'a@b.nl', pendingEmail: 'new@b.nl' })
		const vm = context(PortalProfile)
		await vm.load()
		expect(vm.form).toEqual({ displayName: 'Anna', phone: '', address: '', jobTitle: '', locale: 'nl', email: 'a@b.nl', accountType: 'b2c' })
		expect(vm.pendingEmail).toBe('new@b.nl')
		portalApi.profile.mockRejectedValue(new Error('Session expired.'))
		await vm.load()
		expect(vm.error).toBe('Session expired.')
	})

	it('saves a copy of the form and shows a pending email change', async () => {
		portalApi.updateProfile.mockResolvedValue({ pendingEmail: 'new@b.nl' })
		const vm = context(PortalProfile)
		vm.form.displayName = 'Anna'
		await vm.save()
		const sent = portalApi.updateProfile.mock.calls[0][0]
		expect(sent).toEqual({ ...vm.form })
		expect(sent).not.toBe(vm.form)
		expect(vm.pendingEmail).toBe('new@b.nl')
		expect(vm.message).toBe('Your details have been saved.')

		portalApi.updateProfile.mockResolvedValue({})
		await vm.save()
		expect(vm.pendingEmail).toBe('')
		portalApi.updateProfile.mockRejectedValue(new Error(''))
		await vm.save()
		expect(vm.error).toBe('Could not save your details.')
		expect(vm.message).toBe('')
	})
})

describe('PortalWidget', () => {
	const top = window.top

	afterEach(() => {
		Object.defineProperty(window, 'top', { value: top, configurable: true })
		vi.restoreAllMocks()
	})

	it('shows the dashboard with a token and the sign-in without', () => {
		tokens.getToken.mockReturnValue('tok')
		expect(context(PortalWidget).innerView).toBe('PortalDashboard')
		tokens.getToken.mockReturnValue(null)
		expect(context(PortalWidget).innerView).toBe('PortalLogin')
	})

	it('posts its height and the logout to the host page only when embedded', () => {
		const post = vi.spyOn(window.parent, 'postMessage').mockImplementation(() => {})
		const vm = context(PortalWidget, { $refs: { root: { scrollHeight: 420 } } })
		expect(vm.isEmbedded).toBe(false)
		vm.postResize()
		vm.onLogout()
		expect(post).not.toHaveBeenCalled()

		Object.defineProperty(window, 'top', { value: {}, configurable: true })
		expect(vm.isEmbedded).toBe(true)
		vm.postResize()
		vm.onLogout()
		expect(post.mock.calls).toEqual([
			[{ type: 'portal:resize', height: 420 }, '*'],
			[{ type: 'portal:logout' }, '*'],
		])
	})

	it('an embedded widget without its root posts no height', () => {
		const post = vi.spyOn(window.parent, 'postMessage').mockImplementation(() => {})
		Object.defineProperty(window, 'top', { value: {}, configurable: true })
		context(PortalWidget, { $refs: {} }).postResize()
		expect(post).not.toHaveBeenCalled()
	})

	it('follows its own size once mounted and stops when unmounted', () => {
		const observed = []
		const disconnect = vi.fn()
		vi.stubGlobal('ResizeObserver', class {
			constructor(callback) { this.callback = callback }
			observe(el) { observed.push(el) }
			disconnect() { disconnect() }
		})
		const root = { scrollHeight: 10 }
		const vm = context(PortalWidget, { $refs: { root } })
		PortalWidget.mounted.call(vm)
		expect(observed).toEqual([root])
		PortalWidget.beforeUnmount.call(vm)
		expect(disconnect).toHaveBeenCalledTimes(1)
		vi.unstubAllGlobals()
	})
})

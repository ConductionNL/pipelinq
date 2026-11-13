/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The payment provider form's actions: loading the broker credentials,
 * saving and toggling a provider, testing its connection, and the labels
 * it shows for credentials, environments and moments.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = { listProviders: vi.fn(), testConnection: vi.fn(), updateProvider: vi.fn() }
const http = { get: vi.fn() }
const toast = { showError: vi.fn(), showSuccess: vi.fn() }

vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcSelect: {}, NcSettingsSection: {}, NcTextField: {} }))
vi.mock('vue-material-design-icons/Refresh.vue', () => ({ default: {} }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => toast)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('../../src/services/posPaymentApi.js', () => api)

let Form

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (match, key) => (key in vars ? String(vars[key]) : match))
	Form = (await import('../../src/views/settings/PaymentSettingsForm.vue')).default
})

beforeEach(() => {
	vi.clearAllMocks()
})

/**
 * The form's state with its methods and computed values bound to it.
 *
 * @param {object} state Overrides for the data.
 * @return {object} The context.
 */
function form(state = {}) {
	const ctx = { ...Form.data(), ...state }
	for (const [name, fn] of Object.entries(Form.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	Object.defineProperty(ctx, 'environmentOptions', { get: () => Form.computed.environmentOptions.call(ctx) })
	return ctx
}

describe('PaymentSettingsForm credentials', () => {
	it('loads the broker credentials and offers each provider only its own, by name or id', async () => {
		http.get.mockResolvedValue({ data: { results: [
			{ id: 'k1', name: 'Adyen live', provider: 'adyen' },
			{ id: 'k2', provider: 'adyen-test' },
			{ id: 'k3', name: 'Stripe', provider: 'stripe' },
			{ id: 'k4', name: 'Other', provider: 'custom' },
		] } })
		const ctx = form()

		await ctx.fetchCredentials()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/credentials')
		expect(ctx.loadingCredentials).toBe(false)
		expect(ctx.credentialsFor('adyen')).toEqual([{ label: 'Adyen live', value: 'k1' }, { label: 'k2', value: 'k2' }])
		expect(ctx.credentialsFor('mollie')).toEqual([])
		expect(ctx.credentialsFor('custom')).toEqual([{ label: 'Other', value: 'k4' }])
	})

	it('falls back to no credentials when the list cannot be read or has no results', async () => {
		const ctx = form({ credentials: [{ id: 'old', provider: 'stripe' }] })

		http.get.mockRejectedValueOnce(new Error('403'))
		await ctx.fetchCredentials()
		expect(ctx.credentials).toEqual([])

		http.get.mockResolvedValueOnce({ data: {} })
		await ctx.fetchCredentials()
		expect(ctx.credentials).toEqual([])
		expect(ctx.loadingCredentials).toBe(false)
	})

	it('stores the picked credential id and clears it when the pick is removed', () => {
		const provider = { credentialId: 'k1' }
		const ctx = form()

		ctx.onCredentialChange(provider, { value: 'k9', label: 'x' })
		expect(provider.credentialId).toBe('k9')
		ctx.onCredentialChange(provider, null)
		expect(provider.credentialId).toBe('')
	})
})

describe('PaymentSettingsForm labels', () => {
	it('names providers, environments, provider types and moments', () => {
		const ctx = form({ providers: [{ name: 'ccv', displayName: 'CCV' }, { name: 'stripe' }] })

		expect([ctx.displayName('ccv'), ctx.displayName('stripe'), ctx.displayName('unknown')]).toEqual(['CCV', 'stripe', 'unknown'])
		expect([ctx.environmentLabel('live'), ctx.environmentLabel('sandbox'), ctx.environmentLabel('staging'), ctx.environmentLabel(undefined)]).toEqual(['Production', 'Sandbox', 'staging', ''])
		expect([ctx.providerTypeLabel('terminal'), ctx.providerTypeLabel('online')]).toEqual(['PIN-terminal', 'Online'])
		expect(ctx.formatMoment('not a date')).toBe('not a date')
		expect(ctx.formatMoment('2026-11-12T10:30:00Z')).not.toBe('2026-11-12T10:30:00Z')
	})

	it('keeps a typed secret as typed', () => {
		const provider = { webhookSecret: '***SET***' }

		form().onSecretChange(provider, 'webhookSecret', 'whsec_1')
		expect(provider.webhookSecret).toBe('whsec_1')
	})
})

describe('PaymentSettingsForm saving', () => {
	it('saves the payload, sending the webhook secret only when typed, and takes the saved provider back', async () => {
		api.updateProvider.mockResolvedValue({ name: 'adyen', isActive: true, config: {}, credentialId: 'k1' })
		const provider = { name: 'adyen', displayName: 'Adyen', isActive: true, environment: 'live', testMode: false, config: { merchantAccount: 'M' }, credentialId: 'k1', webhookSecret: 'whsec_2' }
		const ctx = form()

		await ctx.onSave(provider)

		expect(api.updateProvider).toHaveBeenCalledWith('adyen', { isActive: true, environment: 'live', testMode: false, config: { merchantAccount: 'M' }, credentialId: 'k1', webhookSecret: 'whsec_2' })
		expect(provider.config).toEqual({ merchantAccount: '' })
		expect(provider.webhookSecret).toBe('')
		expect(toast.showSuccess).toHaveBeenCalledWith('Provider Adyen saved')
		expect(ctx.savingProvider).toBeNull()
	})

	it('leaves a masked secret and a missing config out, and keeps the provider when nothing comes back', async () => {
		api.updateProvider.mockResolvedValue(null)
		const provider = { name: 'mollie', displayName: 'Mollie', isActive: false, webhookSecret: '***SET***' }

		await form().onSave(provider)

		expect(api.updateProvider).toHaveBeenCalledWith('mollie', { isActive: false, environment: undefined, testMode: undefined, config: {}, credentialId: '' })
		expect(provider.webhookSecret).toBe('***SET***')
		expect(toast.showSuccess).toHaveBeenCalledWith('Provider Mollie saved')
	})

	it('reports a failed save', async () => {
		api.updateProvider.mockRejectedValueOnce(new Error('Forbidden')).mockRejectedValueOnce({})
		const ctx = form()

		await ctx.onSave({ name: 'ccv' })
		await ctx.onSave({ name: 'ccv' })

		expect(toast.showError.mock.calls).toEqual([['Save failed: Forbidden'], ['Save failed: unknown']])
		expect(toast.showSuccess).not.toHaveBeenCalled()
		expect(ctx.savingProvider).toBeNull()
	})

	it('toggles a provider on only for a literal true, and saves it', async () => {
		api.updateProvider.mockResolvedValue(null)
		const provider = { name: 'stripe', isActive: false }
		const ctx = form()

		await ctx.onToggle(provider, true)
		expect(provider.isActive).toBe(true)
		await ctx.onToggle(provider, 'yes')
		expect(provider.isActive).toBe(false)
		expect(api.updateProvider).toHaveBeenCalledTimes(2)
	})
})

describe('PaymentSettingsForm connection test', () => {
	it('records an ok result with its moment and reports success', async () => {
		api.testConnection.mockResolvedValue({ status: 'ok' })
		const provider = { name: 'mollie', displayName: 'Mollie' }
		const ctx = form()

		await ctx.onTest(provider)

		expect(api.testConnection).toHaveBeenCalledWith('mollie')
		expect(provider.testResult).toEqual({ status: 'ok' })
		expect(Number.isNaN(Date.parse(provider.lastTestedAt))).toBe(false)
		expect(toast.showSuccess).toHaveBeenCalledWith('Connection to Mollie successful')
		expect(ctx.testingProvider).toBeNull()
	})

	it('reports a refused test with the provider message', async () => {
		api.testConnection.mockResolvedValue({ status: 'error', message: 'Invalid key' })
		const provider = { name: 'stripe', displayName: 'Stripe' }

		await form().onTest(provider)

		expect(provider.testResult).toEqual({ status: 'error', message: 'Invalid key' })
		expect(toast.showError).toHaveBeenCalledWith('Test failed: Invalid key')
	})

	it('turns a thrown test into an error result', async () => {
		api.testConnection.mockRejectedValueOnce(new Error('timeout')).mockRejectedValueOnce({})
		const first = { name: 'ccv' }
		const second = { name: 'ccv' }
		const ctx = form()

		await ctx.onTest(first)
		await ctx.onTest(second)

		expect(first.testResult).toEqual({ status: 'error', message: 'timeout' })
		expect(second.testResult).toEqual({ status: 'error', message: 'unknown' })
		expect(toast.showError.mock.calls).toEqual([['Test failed: timeout'], ['Test failed: netwerkfout']])
		expect(ctx.testingProvider).toBeNull()
	})
})

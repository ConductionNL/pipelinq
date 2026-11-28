// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The messaging settings (channel providers, send budgets, templates, webhook
 * links, connection tests) and the product category manager, mounted with the
 * object store and axios doubled.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: stub('CnSettingsSection') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/components/admin/ServiceAccountPicker.vue', () => ({ default: stub('ServiceAccountPicker') }))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({ default: stub('ConfirmDialog') }))
const store = { fetchCollection: vi.fn(), saveObject: vi.fn(), deleteObject: vi.fn() }
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

const { default: MessagingSettings } = await import('../../src/views/settings/MessagingSettings.vue')
const { default: ProductCategoryManager } = await import('../../src/views/settings/ProductCategoryManager.vue')

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component) {
	const w = shallowMount(component, { global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	store.fetchCollection.mockImplementation(async (type) => ({
		channelProvider: [{ id: 'p1', displayName: 'Twilio NL', kind: 'sms' }, { id: 'p2', displayName: 'Meta', kind: 'whatsapp-cloud-api' }],
		messageSendBudget: [{ id: 'b1', maxMessages: 100, currentPeriodMessages: 25 }],
		messageTemplate: [{ id: 't1' }],
		productCategory: [{ id: 'c2', name: 'Zeta' }, { id: 'c1', name: 'Alpha', order: 1 }, { id: 'c3', name: 'Beta' }],
	}[type]))
	axios.post = vi.fn()
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('MessagingSettings', () => {
	it('loads providers, budgets and templates and offers the providers to the budget form', async () => {
		const w = await mountIt(MessagingSettings)
		expect([w.vm.providers.length, w.vm.budgets.length, w.vm.templates.length]).toEqual([2, 1, 1])
		expect(w.vm.providerOptions).toEqual([{ value: 'p1', label: 'Twilio NL' }, { value: 'p2', label: 'Meta' }])
		expect(w.vm.kindOptions.map((o) => o.value)).toEqual(['whatsapp-cloud-api', 'whatsapp-bsp', 'sms'])
		expect(w.vm.vendorOptions).toHaveLength(6)
		expect(w.vm.periodOptions.map((o) => o.value)).toEqual(['daily', 'weekly', 'monthly'])
		expect(w.vm.serviceAccountUrl).toContain('/apps/pipelinq/api/messaging/service-account')
	})

	it('says so when a list cannot be loaded', async () => {
		store.fetchCollection.mockRejectedValue(new Error('x'))
		const w = await mountIt(MessagingSettings)
		expect([w.vm.providers, w.vm.budgets, w.vm.templates]).toEqual([[], [], []])
		expect(toasts.map((e) => e[1]).sort()).toEqual(['Failed to load channel providers.', 'Failed to load send budgets.', 'Failed to load templates.'])
	})

	it('labels kinds, vendors, template states and providers', async () => {
		const w = await mountIt(MessagingSettings)
		expect([w.vm.kindLabel('whatsapp-bsp'), w.vm.kindLabel('fax'), w.vm.kindLabel('')]).toEqual(['WhatsApp (BSP)', 'fax', '—'])
		expect([w.vm.vendorLabel('cm-com'), w.vm.vendorLabel(null)]).toEqual(['CM.com', '—'])
		expect(['approved', 'pending', 'rejected', 'disabled', 'odd', ''].map(w.vm.templateStatusLabel)).toEqual(['Approved', 'Pending', 'Rejected', 'Disabled', 'odd', '—'])
		expect([w.vm.templateStatusClass('rejected'), w.vm.templateStatusClass('odd')]).toEqual(['cn-status-badge--error', 'cn-status-badge--default'])
		expect([w.vm.providerDisplayName('p2'), w.vm.providerDisplayName('zz'), w.vm.providerDisplayName(null)]).toEqual(['Meta', 'zz', '—'])
	})

	it('builds the webhook address per channel and copies it', async () => {
		const w = await mountIt(MessagingSettings)
		expect(w.vm.webhookUrl({ id: 'p1', kind: 'sms' })).toMatch(/\/apps\/pipelinq\/api\/messaging-webhooks\/sms\/p1$/)
		expect(w.vm.shortWebhookUrl({ id: 'p2', kind: 'whatsapp-bsp' })).toBe('…/whatsapp/p2')
		const writeText = vi.fn(async () => {})
		Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true })
		await w.vm.copyWebhookUrl({ id: 'p1', kind: 'sms' })
		writeText.mockRejectedValueOnce(new Error('denied'))
		await w.vm.copyWebhookUrl({ id: 'p1', kind: 'sms' })
		expect(toasts).toEqual([['success', 'Webhook URL copied to clipboard.'], ['error', 'Could not copy the webhook URL.']])
	})

	it('describes a connection test and the share of the budget used', async () => {
		const w = await mountIt(MessagingSettings)
		expect([w.vm.testResultLabel({ reachable: true }), w.vm.testResultLabel({ reachable: true, mock: true })]).toEqual(['Reachable', 'Reachable (mock mode)'])
		expect([w.vm.testResultLabel({ reachable: false, cause: 'auth' }), w.vm.testResultLabel({})]).toEqual(['Degraded: auth', 'Degraded: unknown'])
		expect([w.vm.budgetUsageShare({ maxMessages: 100, currentPeriodMessages: 25 }), w.vm.budgetUsageShare({ maxMessages: 10, currentPeriodMessages: 50 }), w.vm.budgetUsageShare({ maxMessages: 0 })]).toEqual([0.25, 1, 0])
	})

	it('tests a provider and keeps the answer, or a request failure', async () => {
		const w = await mountIt(MessagingSettings)
		axios.post.mockResolvedValueOnce({ data: { reachable: true } })
		await w.vm.testProviderConnection({ id: 'p1' })
		expect(axios.post.mock.calls[0][0]).toContain('/apps/pipelinq/api/messaging/providers/p1/test')
		axios.post.mockRejectedValueOnce({ response: { data: { reachable: false, cause: 'timeout' } } })
		await w.vm.testProviderConnection({ id: 'p2' })
		axios.post.mockRejectedValueOnce({})
		await w.vm.testProviderConnection({ id: 'p3' })
		expect(w.vm.testResults).toEqual({ p1: { reachable: true }, p2: { reachable: false, cause: 'timeout' }, p3: { reachable: false, cause: 'request-failed' } })
		expect(w.vm.testingProviderId).toBeNull()
	})

	it('creates and edits a provider, and resets the form afterwards', async () => {
		const w = await mountIt(MessagingSettings)
		w.vm.startNewProvider()
		expect([w.vm.showProviderForm, w.vm.providerForm.kind, w.vm.providerForm.vendor]).toEqual([true, 'sms', 'twilio'])
		w.vm.editProvider({ id: 'p2', displayName: 'Meta', kind: 'whatsapp-cloud-api' })
		expect(w.vm.providerForm).toMatchObject({ id: 'p2', displayName: 'Meta', kind: 'whatsapp-cloud-api', priority: 10 })
		await w.vm.saveProvider()
		expect(store.saveObject).toHaveBeenCalledWith('channelProvider', expect.objectContaining({ id: 'p2', displayName: 'Meta' }))
		expect([w.vm.showProviderForm, w.vm.editingProvider, w.vm.providerForm.id]).toEqual([false, null, null])
		store.saveObject.mockRejectedValueOnce(new Error('Phone number taken'))
		await w.vm.saveProvider()
		expect([toasts.at(-1), w.vm.savingProvider]).toEqual([['error', 'Phone number taken'], false])
	})

	it('deletes a provider after confirmation, and nothing without one', async () => {
		const w = await mountIt(MessagingSettings)
		await w.vm.performDeleteProvider()
		expect(store.deleteObject).not.toHaveBeenCalled()
		w.vm.deleteProvider({ id: 'p1', displayName: 'Twilio NL' })
		expect(w.vm.deleteProviderMessage).toBe('Delete provider "Twilio NL"?')
		await w.vm.performDeleteProvider()
		expect(store.deleteObject).toHaveBeenCalledWith('channelProvider', 'p1')
		expect(w.vm.deleteProviderMessage).toBe('')
		w.vm.deleteProvider({ id: 'p2' })
		store.deleteObject.mockRejectedValueOnce({})
		await w.vm.performDeleteProvider()
		expect(toasts.map((e) => e[1])).toEqual(['Provider deleted.', 'Failed to delete the provider.'])
	})

	it('creates, saves and deletes send budgets', async () => {
		const w = await mountIt(MessagingSettings)
		w.vm.startNewBudget()
		w.vm.budgetForm.maxMessages = 500
		await w.vm.createBudget()
		expect(store.saveObject).toHaveBeenCalledWith('messageSendBudget', { tenantId: '', providerId: null, period: 'monthly', maxMessages: 500, maxCostEur: 0, hardStop: false })
		expect([w.vm.showBudgetForm, w.vm.budgetForm.maxMessages]).toEqual([false, 0])
		await w.vm.saveBudget({ id: 'b1', maxMessages: 10 })
		expect(w.vm.savingBudgetId).toBeNull()
		w.vm.deleteBudget({ id: 'b1' })
		await w.vm.performDeleteBudget()
		await w.vm.performDeleteBudget()
		expect(store.deleteObject).toHaveBeenCalledTimes(1)
		store.saveObject.mockRejectedValueOnce({}).mockRejectedValueOnce(new Error('Cap below usage'))
		await w.vm.createBudget()
		await w.vm.saveBudget({ id: 'b1' })
		w.vm.deleteBudget({ id: 'b1' })
		store.deleteObject.mockRejectedValueOnce({})
		await w.vm.performDeleteBudget()
		expect(toasts.map((e) => e[1])).toEqual(['Budget created.', 'Budget saved.', 'Budget deleted.', 'Failed to create the budget.', 'Cap below usage', 'Failed to delete the budget.'])
		w.vm.cancelBudgetForm()
		expect(w.vm.showBudgetForm).toBe(false)
	})
})

describe('ProductCategoryManager', () => {
	it('lists the categories by order, then by name', async () => {
		const w = await mountIt(ProductCategoryManager)
		expect(store.fetchCollection).toHaveBeenCalledWith('productCategory', { _limit: 100 })
		expect(w.vm.sortedCategories.map((c) => c.name)).toEqual(['Alpha', 'Beta', 'Zeta'])
		store.fetchCollection.mockRejectedValueOnce(new Error('x'))
		await w.vm.fetchCategories()
		expect([w.vm.categories, w.vm.loading]).toEqual([[], false])
	})

	it('adds a trimmed category at the end, and nothing without a name', async () => {
		const w = await mountIt(ProductCategoryManager)
		w.vm.startAdding()
		expect(w.vm.adding).toBe(true)
		await w.vm.saveNew()
		expect(store.saveObject).not.toHaveBeenCalled()
		w.vm.addForm = { name: ' Service ', description: ' Hours ' }
		await w.vm.saveNew()
		expect(store.saveObject).toHaveBeenCalledWith('productCategory', { name: 'Service', description: 'Hours', order: 3 })
		expect(w.vm.adding).toBe(false)
		w.vm.addForm = { name: 'X', description: '' }
		store.saveObject.mockRejectedValueOnce({})
		await w.vm.saveNew()
		expect(w.vm.error).toBe('Failed to create category')
		w.vm.cancelAdding()
		expect([w.vm.adding, w.vm.error]).toEqual([false, null])
	})

	it('renames a category, and nothing to a blank name', async () => {
		const w = await mountIt(ProductCategoryManager)
		w.vm.startEditing({ id: 'c1', name: 'Alpha' })
		expect([w.vm.editingId, w.vm.editForm]).toEqual(['c1', { name: 'Alpha', description: '' }])
		w.vm.editForm.name = ' '
		await w.vm.saveEdit('c1')
		expect(store.saveObject).not.toHaveBeenCalled()
		w.vm.editForm = { name: ' Alfa ', description: 'd' }
		await w.vm.saveEdit('c1')
		expect(store.saveObject).toHaveBeenCalledWith('productCategory', { id: 'c1', name: 'Alfa', description: 'd' })
		expect(w.vm.editingId).toBeNull()
		w.vm.editForm = { name: 'Y', description: '' }
		store.saveObject.mockRejectedValueOnce(new Error('Locked'))
		await w.vm.saveEdit('c1')
		expect(w.vm.error).toBe('Locked')
		w.vm.cancelEdit()
		expect(w.vm.editingId).toBeNull()
	})

	it('removes a category only after confirmation', async () => {
		const w = await mountIt(ProductCategoryManager)
		vi.stubGlobal('confirm', vi.fn(() => false))
		await w.vm.confirmRemove({ id: 'c1', name: 'Alpha' })
		expect(store.deleteObject).not.toHaveBeenCalled()
		expect(confirm).toHaveBeenCalledWith('Are you sure you want to remove "Alpha"?')
		vi.stubGlobal('confirm', vi.fn(() => true))
		await w.vm.confirmRemove({ id: 'c1', name: 'Alpha' })
		expect(store.deleteObject).toHaveBeenCalledWith('productCategory', 'c1')
		store.deleteObject.mockRejectedValueOnce({})
		await w.vm.confirmRemove({ id: 'c2', name: 'Zeta' })
		expect(w.vm.error).toBe('Failed to remove category')
	})
})

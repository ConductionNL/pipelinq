// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Two admin sections rendered with their slots, as an administrator sees and
 * drives them: the marketing traffic settings (what typing into each field
 * sets, the service account note, the last import line, the key removal
 * button and the result message) and the pipeline manager (the list rows, the
 * empty state, and the buttons that open and close the form and delete dialogs).
 *
 * @spec openspec/specs/marketing-campaign-attribution/spec.md#requirement-marketing-traffic-settings
 * @spec openspec/specs/pipeline/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
const toasts = vi.hoisted(() => [])
vi.mock('@nextcloud/dialogs', () => ({ showError: (m) => toasts.push(m) }))

/**
 * An input stand-in that renders its slot and reports a new value as the real one does.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
const field = (name) => ({ name, props: ['modelValue', 'label'], emits: ['update:modelValue'], template: '<div><slot /></div>' })
const slots = (name, tag = 'div') => ({ name, template: `<${tag}><slot name="icon" /><slot name="actions" /><slot /><slot name="action" /></${tag}>` })

vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['disabled', 'ariaLabel'], emits: ['click'], template: '<button :aria-label="ariaLabel" @click="$emit(\'click\')"><slot name="icon" /><slot /></button>' },
	NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
	NcEmptyContent: { name: 'NcEmptyContent', props: ['name'], template: '<div class="empty">{{ name }}<slot name="action" /></div>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<i class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<p class="note" :data-type="type"><slot /></p>' },
	NcSettingsSection: slots('NcSettingsSection', 'section'),
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: slots('CnSettingsSection', 'section') }))
for (const icon of ['Delete', 'Pencil', 'Plus', 'Star', 'ViewColumn']) {
	vi.doMock(`vue-material-design-icons/${icon}.vue`, () => ({ default: { name: icon, template: `<i class="icon-${icon}" />` } }))
}
vi.mock('../../src/dialogs/DeletePipelineDialog.vue', () => ({ default: { name: 'DeletePipelineDialog', props: ['pipeline', 'affectedCount'], emits: ['cancel', 'confirm'], template: '<div />' } }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: { name: 'PipelineFormDialog', props: ['pipeline'], emits: ['save', 'cancel'], template: '<div />' } }))

const settings = vi.hoisted(() => ({ saveSettings: vi.fn() }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => settings }))
const store = vi.hoisted(() => ({
	collections: { pipeline: [] },
	loading: {},
	objectTypeRegistry: { lead: { register: 'r', schema: 'lead' }, request: { register: 'r', schema: 'request' } },
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))

const { default: MarketingTrafficSettings } = await import('../../src/views/settings/MarketingTrafficSettings.vue')
const { default: PipelineManager } = await import('../../src/views/settings/PipelineManager.vue')

const global = { mocks: { t: globalThis.t, n: globalThis.n } }

beforeEach(() => {
	axios.get.mockReset()
	settings.saveSettings.mockReset()
	toasts.length = 0
})

describe('MarketingTrafficSettings, rendered', () => {
	it('saves what is typed into each field', async () => {
		axios.get.mockResolvedValue({ data: {} })
		settings.saveSettings.mockResolvedValue({})
		const w = mount(MarketingTrafficSettings, { global })
		await flushPromises()
		await w.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', false)
		await w.findComponent({ name: 'NcTextField' }).vm.$emit('update:modelValue', ' open-tilburg ')
		const areas = w.findAllComponents({ name: 'NcTextArea' })
		await areas.at(0).vm.$emit('update:modelValue', 'sc-domain:example.org')
		await areas.at(1).vm.$emit('update:modelValue', '{"type":"service_account"}')
		await w.find('[data-testid="marketing-traffic-save"]').trigger('click')
		await flushPromises()

		expect(settings.saveSettings).toHaveBeenCalledWith({
			'blast.utm_auto': 'false',
			'blast.traffic_portal': 'open-tilburg',
			'search.gsc.properties': 'sc-domain:example.org',
			'search.gsc.service_account_key': '{"type":"service_account"}',
		})
		expect(w.find('.note').text()).toBe('Marketing traffic settings saved')
		expect(w.find('.note').attributes('data-type')).toBe('success')
	})

	it('names the service account and the last import, and removes a stored key from its button', async () => {
		axios.get.mockResolvedValue({ data: { serviceAccountEmail: 'sa@proj.iam.gserviceaccount.com', lastImportAt: '2026-11-01 06:00' } })
		settings.saveSettings.mockResolvedValue({})
		const w = mount(MarketingTrafficSettings, { props: { config: { 'search.gsc.service_account_key_set': 'true' } }, global })
		await flushPromises()

		expect(w.text()).toContain('Add sa@proj.iam.gserviceaccount.com as a user with full permission on each property in Search Console, or the import is refused.')
		expect(w.text()).toContain('Last import: 2026-11-01 06:00')
		await w.find('[data-testid="marketing-gsc-key-clear"]').trigger('click')
		await flushPromises()

		expect(settings.saveSettings).toHaveBeenCalledWith({ 'search.gsc.service_account_key_clear': 'true' })
		expect(w.find('[data-testid="marketing-gsc-key-clear"]').exists()).toBe(false)
		expect(w.text()).not.toContain('sa@proj.iam.gserviceaccount.com')
		expect(w.find('.note').text()).toBe('The stored key was removed')
	})

	it('shows a spinner on the save button while saving', async () => {
		axios.get.mockResolvedValue({ data: {} })
		let finish
		settings.saveSettings.mockReturnValue(new Promise((resolve) => { finish = resolve }))
		const w = mount(MarketingTrafficSettings, { global })
		await flushPromises()
		await w.find('[data-testid="marketing-traffic-save"]').trigger('click')

		expect(w.find('[data-testid="marketing-traffic-save"] .loading').exists()).toBe(true)
		finish(null)
		await flushPromises()
		expect(w.find('[data-testid="marketing-traffic-save"] .loading').exists()).toBe(false)
		expect(w.find('.note').attributes('data-type')).toBe('error')
	})
})

describe('PipelineManager, rendered', () => {
	const sales = { id: 'p1', title: 'Sales', isDefault: true, entityType: 'lead', stages: [{ name: 'New', order: 0 }, { name: 'Won', order: 1 }] }
	const service = { id: 'p2', title: 'Service', entityType: 'request', stages: [] }

	afterEach(() => {
		vi.unstubAllGlobals()
	})

	/**
	 * Mount the manager over the given pipelines.
	 *
	 * @param {Array} pipelines The pipelines in the store.
	 * @return {Promise<object>} The wrapper.
	 */
	async function mountManager(pipelines) {
		store.collections = { pipeline: pipelines }
		const w = mount(PipelineManager, { global })
		await flushPromises()
		return w
	}

	it('lists each pipeline with its scope, stages and a star on the default', async () => {
		const w = await mountManager([sales, service])
		const cards = w.findAll('.pipeline-card')

		expect(cards).toHaveLength(2)
		expect(cards.at(0).find('.icon-Star').exists()).toBe(true)
		expect(cards.at(0).find('.entity-type-badge').text()).toBe('Leads')
		expect(cards.at(0).find('.pipeline-meta').text()).toBe('2 stages · New → Won')
		expect(cards.at(1).find('.icon-Star').exists()).toBe(false)
		expect(cards.at(1).find('.pipeline-meta').text()).toBe('0 stages · No stages')
	})

	it('opens the form for a pipeline from its edit button, and closes it on cancel', async () => {
		const w = await mountManager([sales])
		await w.find('[aria-label="Edit pipeline Sales"]').trigger('click')
		expect(w.findComponent({ name: 'PipelineFormDialog' }).props('pipeline')).toEqual(sales)

		await w.findComponent({ name: 'PipelineFormDialog' }).vm.$emit('cancel')
		expect(w.findComponent({ name: 'PipelineFormDialog' }).exists()).toBe(false)
	})

	it('asks before deleting a pipeline from its delete button, and closes on cancel', async () => {
		vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, json: async () => ({ total: 2 }) }))
		const w = await mountManager([service])
		await w.find('[aria-label="Delete pipeline Service"]').trigger('click')
		await flushPromises()

		const dialog = w.findComponent({ name: 'DeletePipelineDialog' })
		expect(dialog.props('pipeline')).toEqual(service)
		await dialog.vm.$emit('cancel')
		expect(w.findComponent({ name: 'DeletePipelineDialog' }).exists()).toBe(false)
		expect(store.deleteObject).not.toHaveBeenCalled()
	})

	it('offers to create the first pipeline when there is none', async () => {
		const w = await mountManager([])
		expect(w.find('.empty').text()).toContain('No pipelines configured')
		await w.find('.empty button').trigger('click')

		expect(w.findComponent({ name: 'PipelineFormDialog' }).props('pipeline')).toBeNull()
	})

	it('shows a spinner while the pipelines load', async () => {
		store.loading = { pipeline: true }
		const w = await mountManager([])
		store.loading = {}

		expect(w.find('.loading').exists()).toBe(true)
		expect(w.find('.empty').exists()).toBe(false)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four admin sections mounted as a user sees them: the detected integrations,
 * the CTI form, the VAT rates and the menu structure. Each is checked for what
 * it shows and for what its buttons and switches send.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const state = vi.hoisted(() => ({ values: {} }))
const http = vi.hoisted(() => ({ post: vi.fn() }))
const cti = vi.hoisted(() => ({ getConfig: vi.fn(), testConnection: vi.fn(), updateConfig: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const menu = vi.hoisted(() => ({ saveMenuStructure: vi.fn() }))

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.OC = { requestToken: 'tok' }
})

vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => (key in state.values ? state.values[key] : fallback) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (...args) => globalThis.t(...args) }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('../../src/services/ctiApi.js', () => cti)
vi.mock('../../src/services/menuStructureSetting.js', () => menu)

/**
 * A stub that renders its slots and exposes a modelValue input.
 *
 * @param {string} name The component name.
 * @param {string} [tag] The element to render.
 * @return {object} The component.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'name', 'description', 'type', 'label', 'value', 'disabled', 'options', 'inputLabel'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, { 'data-stub': name, 'data-type': this.type, onClick: () => this.$emit('click') }, [
				this.name ? h('h2', this.name) : null,
				this.label ? h('label', this.label) : null,
				this.$slots.icon?.(),
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
	NcTextField: stub('NcTextField'),
}))

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: DetectedIntegrations } = await import('../../src/views/settings/DetectedIntegrations.vue')
const { default: CtiSettings } = await import('../../src/views/settings/CtiSettings.vue')
const { default: VatRatesSettings, parseVatRates } = await import('../../src/views/settings/VatRatesSettings.vue')
const { default: MenuStructureSettings } = await import('../../src/views/settings/MenuStructureSettings.vue')

beforeEach(() => {
	state.values = {}
	vi.resetAllMocks()
})

describe('DetectedIntegrations', () => {
	it('says nothing is installed when the server found nothing', () => {
		const wrapper = mount(DetectedIntegrations, mountOptions)
		const cards = wrapper.findAll('[data-stub="NcNoteCard"]')
		expect(cards.map((c) => c.attributes('data-type'))).toEqual(['info', 'info'])
		expect(cards[0].text()).toBe('Shillinq is not installed. Billing hand-off stays off.')
		expect(cards[1].text()).toBe('XWiki is not connected. Install the XWiki app, or integriq with an XWiki source.')
	})

	it('links an installed Shillinq and names the XWiki app route', () => {
		state.values.detected_integrations = {
			shillinq: { installed: true, url: '/apps/shillinq' },
			xwiki: { available: true, source: 'xwiki-app' },
		}
		const wrapper = mount(DetectedIntegrations, mountOptions)
		const cards = wrapper.findAll('[data-stub="NcNoteCard"]')
		expect(cards.map((c) => c.attributes('data-type'))).toEqual(['success', 'success'])
		expect(wrapper.find('a').attributes('href')).toBe('/apps/shillinq')
		expect(wrapper.find('a').text()).toBe('Open Shillinq')
		expect(cards[1].text()).toBe('XWiki is connected through the XWiki app.')
	})

	it('names the OpenRegister route for XWiki', () => {
		state.values.detected_integrations = { xwiki: { available: true, source: 'openregister' } }
		const wrapper = mount(DetectedIntegrations, mountOptions)
		expect(wrapper.findAll('[data-stub="NcNoteCard"]')[1].text()).toBe('XWiki is connected through OpenRegister and integriq.')
	})
})

describe('CtiSettings', () => {
	it('loads the stored configuration over the defaults', async () => {
		cti.getConfig.mockResolvedValueOnce({ platform: 'other', default_country_code: 'BE' })
		const wrapper = mount(CtiSettings, mountOptions)
		await flushPromises()
		expect(wrapper.vm.config.platform).toBe('other')
		expect(wrapper.vm.config.default_country_code).toBe('BE')
		expect(wrapper.vm.config.auth_method).toBe('webhook-secret')
		expect(wrapper.find('[data-testid="cti-settings"]').exists()).toBe(true)
	})

	it('saves through the button and confirms it', async () => {
		cti.getConfig.mockResolvedValueOnce(null)
		cti.updateConfig.mockResolvedValueOnce({ api_base_url: 'https://pbx' })
		const wrapper = mount(CtiSettings, mountOptions)
		await flushPromises()
		const save = wrapper.findAll('button').find((b) => b.text() === 'Save')
		await save.trigger('click')
		await flushPromises()
		expect(cti.updateConfig).toHaveBeenCalledWith(expect.objectContaining({ platform: 'callvoip', default_country_code: 'NL' }))
		expect(wrapper.vm.config.api_base_url).toBe('https://pbx')
		expect(toasts.showSuccess).toHaveBeenCalledWith('CTI configuration saved.')
	})

	it('shows the result of a connection test under the form', async () => {
		cti.getConfig.mockResolvedValueOnce({})
		cti.testConnection.mockResolvedValueOnce({ ok: false, message: 'timeout' })
		const wrapper = mount(CtiSettings, mountOptions)
		await flushPromises()
		await wrapper.findAll('button').find((b) => b.text() === 'Test connection').trigger('click')
		await flushPromises()
		expect(wrapper.find('.cti-settings__status').text()).toBe('Connection failed: timeout')
	})

	it('reports a configuration that cannot load', async () => {
		cti.getConfig.mockRejectedValueOnce(new Error('403'))
		mount(CtiSettings, mountOptions)
		await flushPromises()
		expect(toasts.showError).toHaveBeenCalledWith('Failed to load CTI config: 403')
	})
})

describe('VatRatesSettings', () => {
	it('reads stored rates over the defaults and ignores what does not parse', () => {
		expect(parseVatRates('{"high":20,"low":"x"}')).toEqual({ high: 20, low: 9, zero: 0, exempt: 0 })
		expect(parseVatRates('not json')).toEqual({ high: 21, low: 9, zero: 0, exempt: 0 })
		expect(parseVatRates('null')).toEqual({ high: 21, low: 9, zero: 0, exempt: 0 })
	})

	it('shows one field per VAT class with its rate', () => {
		const wrapper = mount(VatRatesSettings, { ...mountOptions, props: { config: { vat_rates: '{"high":19}' } } })
		const fields = wrapper.findAllComponents({ name: 'NcTextField' })
		expect(fields.map((f) => [f.props('label'), f.props('modelValue')])).toEqual([
			['High rate (%)', '19'],
			['Low rate (%)', '9'],
			['Zero rate (%)', '0'],
			['Exempt (%)', '0'],
		])
	})

	it('follows a changed stored value', async () => {
		const wrapper = mount(VatRatesSettings, { ...mountOptions, props: { config: { vat_rates: '{}' } } })
		await wrapper.setProps({ config: { vat_rates: '{"low":6}' } })
		expect(wrapper.vm.rates.low).toBe(6)
	})

	it('saves the rates as numbers and confirms it', async () => {
		http.post.mockResolvedValueOnce({})
		const wrapper = mount(VatRatesSettings, mountOptions)
		wrapper.findAllComponents({ name: 'NcTextField' })[0].vm.$emit('update:modelValue', '20.5')
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/settings', {
			vat_rates: JSON.stringify({ high: 20.5, low: 9, zero: 0, exempt: 0 }),
		})
		const note = wrapper.find('[data-stub="NcNoteCard"]')
		expect([note.attributes('data-type'), note.text()]).toEqual(['success', 'VAT rates saved. Reload the app to see the new labels.'])
	})

	it('refuses a rate out of range without saving', async () => {
		const wrapper = mount(VatRatesSettings, mountOptions)
		wrapper.findAllComponents({ name: 'NcTextField' })[1].vm.$emit('update:modelValue', '101')
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(http.post).not.toHaveBeenCalled()
		const note = wrapper.find('[data-stub="NcNoteCard"]')
		expect([note.attributes('data-type'), note.text()]).toEqual(['error', 'Each rate must be a number from 0 to 100.'])
	})

	it('reports a failed save and frees the button', async () => {
		http.post.mockRejectedValueOnce(new Error('500'))
		const wrapper = mount(VatRatesSettings, mountOptions)
		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-stub="NcNoteCard"]').text()).toBe('Could not save the VAT rates.')
		expect(wrapper.vm.saving).toBe(false)
	})
})

describe('MenuStructureSettings', () => {
	it('saves the chosen structure and shows that it was saved', async () => {
		state.values.menu_structure = 'simple'
		menu.saveMenuStructure.mockImplementationOnce(async (choice) => ({ structure: 'full', modules: choice.modules }))
		const wrapper = mount(MenuStructureSettings, mountOptions)
		const radio = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.props('value') === 'full')
		radio.vm.$emit('update:modelValue', 'full')
		await flushPromises()
		expect(menu.saveMenuStructure).toHaveBeenCalledWith(
			expect.objectContaining({ structure: 'full' }),
			{ url: '/index.php/apps/pipelinq/api/settings', requestToken: 'tok' },
		)
		expect(wrapper.vm.structure).toBe('full')
		expect(wrapper.find('[role="status"]').text()).toBe('Saved. People see the change the next time they open Pipelinq.')
	})

	it('puts the stored choice back and says so when saving fails', async () => {
		menu.saveMenuStructure.mockRejectedValueOnce(new Error('500'))
		const wrapper = mount(MenuStructureSettings, mountOptions)
		const before = wrapper.vm.structure
		const other = before === 'full' ? 'simple' : 'full'
		wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).find((c) => c.props('value') === other).vm.$emit('update:modelValue', other)
		await flushPromises()
		expect(wrapper.vm.structure).toBe(before)
		expect(wrapper.find('[data-stub="NcNoteCard"]').text()).toBe('The menu could not be saved. Try again.')
		expect(wrapper.find('[role="status"]').text()).toBe('')
	})

	it('offers one switch per module of the simple menu', () => {
		const wrapper = mount(MenuStructureSettings, mountOptions)
		const modules = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' }).filter((c) => c.props('type') === 'checkbox')
		expect(modules.map((c) => c.props('value'))).toEqual(wrapper.vm.moduleOptions.map((m) => m.key))
		expect(modules.length).toBeGreaterThan(0)
	})
})

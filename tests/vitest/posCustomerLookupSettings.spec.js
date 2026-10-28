// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's customer lookup settings as board PqKassaKlantzoeken draws them:
 * one card with the search fields, the history depth as a select (from the
 * last 5 transactions) and the two links to the contact, and a second card
 * that says where cashiers see this, with a way to the till.
 *
 * @spec openspec/changes/pos-customer-link/specs.md#REQ-PCL-006
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ getCustomerSettings: vi.fn(), updateCustomerSettings: vi.fn() }))
vi.mock('../../src/services/posCustomerApi.js', () => api)
vi.mock('@nextcloud/router', async (orig) => ({ ...(await orig()), generateUrl: (path) => '/index.php' + path }))

function stub (name) {
  return {
	name,
	props: ['name', 'description', 'modelValue', 'options', 'inputLabel', 'href', 'disabled', 'variant'],
	render() {
		return h('div', { 'data-stub': name, 'data-name': this.name, 'data-href': this.href }, this.$slots.default ? this.$slots.default() : [])
	},
}
}

const { default: PosCustomerSettings } = await import('../../src/views/admin/PosCustomerSettings.vue')

function mountSettings() {
	api.getCustomerSettings.mockResolvedValue({ customerSearchFields: ['name', 'phone'], customerHistoryDepth: 5, enablePipelinqSync: true, requireCustomerForOnAccount: false })
	api.updateCustomerSettings.mockReset()
	api.updateCustomerSettings.mockResolvedValue({})
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, k) => vars[k])
	return mount(PosCustomerSettings, {
		global: {
			mocks: { t: globalThis.t },
			stubs: {
				NcSettingsSection: stub('NcSettingsSection'),
				NcSelect: stub('NcSelect'),
				NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
				NcButton: stub('NcButton'),
				NcLoadingIcon: true,
			},
		},
	})
}

describe('the till customer lookup settings', () => {
	it('draws the settings card and the where-cashiers-see-this card with the way to the till', async () => {
		const w = mountSettings()
		await flushPromises()
		const cards = w.findAll('[data-stub="NcSettingsSection"]')
		expect(cards.map((c) => c.attributes('data-name'))).toEqual(['Customer lookup at the till', 'Where cashiers see this'])
		const till = w.find('[data-testid="customer-settings-open-till"]')
		expect(till.element.tagName).toBe('A')
		expect(till.attributes('href')).toBe('/index.php/apps/pipelinq/pos/new')
	})

	it('sets the two cards side by side, the settings wide and the note 300px, with a full-width depth select', () => {
		const source = readFileSync(resolve(__dirname, '../../src/views/admin/PosCustomerSettings.vue'), 'utf8')
		const rule = (selector) => (source.match(new RegExp(selector.replace(/[.]/g, '\\.') + ' \\{([^}]*)\\}')) || [])[1] || ''
		expect(rule('.pos-customer-settings-cards.pos-customer-settings-cards')).toMatch(/grid-template-columns: minmax\(0, 1fr\) 300px/)
		expect(rule('.pos-customer-settings__depth')).toMatch(/width: 100%/)
		expect(rule('.pos-customer-settings__actions')).toMatch(/flex-direction: row-reverse/)
	})

	it('offers the history depth from the last 5 transactions', async () => {
		const w = mountSettings()
		await flushPromises()
		expect(w.vm.depthOptions.map((o) => o.label)).toEqual(['Last 5 transactions', 'Last 10 transactions', 'Last 20 transactions', 'Last 50 transactions'])
		expect(w.vm.form.customerHistoryDepth).toBe(5)
	})

	it('saves nothing without a search field', async () => {
		const w = mountSettings()
		await flushPromises()
		w.vm.toggleField('name', false)
		w.vm.toggleField('phone', false)
		await w.vm.save()
		expect(api.updateCustomerSettings).not.toHaveBeenCalled()
	})
})

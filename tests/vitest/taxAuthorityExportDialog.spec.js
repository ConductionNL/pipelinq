// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tax authority export is a registered modal the audit log's header
 * action opens, so it downloads the package itself and closes when done.
 *
 * @spec openspec/changes/archive/2026-06-14-pos-kassakoppeling-audit/specs.md#req-audit-005-tax-authority-export
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const showError = vi.fn()
const showSuccess = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showError, showSuccess }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['variant', 'disabled'], emits: ['click'], render() { return h('button', { 'data-variant': this.variant, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', render() { return h('span') } },
}))

globalThis.t = (app, text) => text
globalThis.OC = { requestToken: 'token' }

const { default: TaxAuthorityExportDialog } = await import('../../src/dialogs/TaxAuthorityExportDialog.vue')

describe('the tax authority export dialog', () => {
	it('downloads the package for the chosen range and closes', async () => {
		globalThis.fetch = vi.fn(async () => ({
			ok: true,
			status: 200,
			blob: async () => new Blob(['<x/>']),
			headers: { get: () => 'attachment; filename="audit.xml"' },
		}))
		window.URL.createObjectURL = vi.fn(() => 'blob:x')
		window.URL.revokeObjectURL = vi.fn()
		const wrapper = mount(TaxAuthorityExportDialog, { global: { mocks: { t: globalThis.t } } })
		await wrapper.find('[data-variant="primary"]').trigger('click')
		await flushPromises()

		expect(globalThis.fetch.mock.calls[0][0]).toMatch(/\/apps\/pipelinq\/api\/kassakoppeling\/audit\/export\?from=\d{4}-\d{2}-\d{2}&to=\d{4}-\d{2}-\d{2}&format=xml$/)
		expect(showSuccess).toHaveBeenCalledWith('Tax authority export downloaded.')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('says a refusal and stays open', async () => {
		globalThis.fetch = vi.fn(async () => ({ ok: false, status: 403 }))
		const wrapper = mount(TaxAuthorityExportDialog, { global: { mocks: { t: globalThis.t } } })
		await wrapper.find('[data-variant="primary"]').trigger('click')
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('Only administrators may export for the tax authority.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

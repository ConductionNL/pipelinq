// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The tax authority export form: it refuses a missing or reversed period
 * before asking the server, sends the chosen period and format, names the
 * download after the period when the server names none, and says when the
 * request itself failed.
 *
 * @spec openspec/specs/pos-kassakoppeling-audit/spec.md#REQ-AUDIT-005
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => `/index.php${url}` }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['variant', 'disabled'], emits: ['click'], render() { return h('button', { 'data-variant': this.variant || 'default', onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'value'],
		emits: ['update:modelValue'],
		render() { return h('input', { type: 'radio', 'data-format': this.value, checked: this.modelValue === this.value, onChange: () => this.$emit('update:modelValue', this.value) }) },
	},
}))

globalThis.t = (app, text) => text
globalThis.OC = { requestToken: 'token' }

const { default: Dialog } = await import('../../src/dialogs/TaxAuthorityExportDialog.vue')

let clicked = []

beforeEach(() => {
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
	window.URL.createObjectURL = vi.fn(() => 'blob:x')
	window.URL.revokeObjectURL = vi.fn()
	clicked = []
	vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function() { clicked.push(this.download) })
})

afterEach(() => {
	vi.restoreAllMocks()
	delete globalThis.fetch
})

const mountDialog = () => mount(Dialog, { global: { mocks: { t: globalThis.t } } })
const download = (w) => w.find('[data-variant="primary"]').trigger('click')

describe('TaxAuthorityExportDialog form', () => {
	it('defaults to the first of this month up to today in XML', () => {
		const w = mountDialog()
		const today = new Date()
		const pad = (n) => String(n).padStart(2, '0')
		expect(w.find('#bd-from').element.value).toBe(`${today.getFullYear()}-${pad(today.getMonth() + 1)}-01`)
		expect(w.find('#bd-to').element.value).toBe(`${today.getFullYear()}-${pad(today.getMonth() + 1)}-${pad(today.getDate())}`)
		expect(w.find('[data-format="xml"]').element.checked).toBe(true)
	})

	it('asks for both dates before calling the server', async () => {
		globalThis.fetch = vi.fn()
		const w = mountDialog()
		await w.find('#bd-from').setValue('')
		await download(w)
		expect(w.find('.bd-export__error').text()).toBe('Fill in both dates (the tax authority requires them).')
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('refuses a from date after the to date', async () => {
		globalThis.fetch = vi.fn()
		const w = mountDialog()
		await w.find('#bd-from').setValue('2026-03-10')
		await w.find('#bd-to').setValue('2026-03-01')
		await download(w)
		expect(w.find('.bd-export__error').text()).toBe('The "from" date must be on or before the "to and including" date.')
		expect(globalThis.fetch).not.toHaveBeenCalled()
		expect(w.emitted('close')).toBeUndefined()
	})

	it('sends the chosen period and format and names the file after them', async () => {
		globalThis.fetch = vi.fn(async () => ({ ok: true, status: 200, blob: async () => new Blob(['{}']), headers: { get: () => null } }))
		const w = mountDialog()
		await w.find('#bd-from').setValue('2026-01-01')
		await w.find('#bd-to').setValue('2026-01-31')
		await w.find('[data-format="json"]').trigger('change')
		await download(w)
		await flushPromises()

		expect(globalThis.fetch).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/kassakoppeling/audit/export?from=2026-01-01&to=2026-01-31&format=json',
			{ method: 'GET', headers: { requesttoken: 'token' } },
		)
		expect(clicked).toEqual(['cash-register-audit-export-2026-01-01-to-2026-01-31.json'])
		expect(window.URL.revokeObjectURL).toHaveBeenCalledWith('blob:x')
		expect(w.emitted('close')).toHaveLength(1)
	})

	it('says the export failed when the request throws, and stays open', async () => {
		globalThis.fetch = vi.fn(async () => { throw new Error('offline') })
		const w = mountDialog()
		await download(w)
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Tax authority export failed.')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(w.emitted('close')).toBeUndefined()
	})

	it('names a server failure other than a refusal as a failed export', async () => {
		globalThis.fetch = vi.fn(async () => ({ ok: false, status: 500 }))
		const w = mountDialog()
		await download(w)
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Tax authority export failed.')
		expect(w.emitted('close')).toBeUndefined()
	})

	it('closes from Cancel and from the dialog itself without downloading', async () => {
		globalThis.fetch = vi.fn()
		const w = mountDialog()
		await w.find('[data-variant="default"]').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(w.emitted('close')).toHaveLength(2)
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})
})

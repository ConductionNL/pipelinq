// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The till's Email Receipt and Print Receipt modals: the templates they
 * offer, the preview they load, the request they send and what they say
 * about it.
 *
 * @spec openspec/specs/pos/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const templates = vi.hoisted(() => ({ loadActiveReceiptTemplates: vi.fn() }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('../../src/services/receiptTemplates.js', () => templates)
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', props: ['variant', 'disabled'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcSelect: { name: 'NcSelect', props: ['modelValue', 'options'], emits: ['update:modelValue'], template: '<div class="select" />' },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', props: ['name'], emits: ['closing'], template: '<div class="dialog" :data-name="name"><slot /><slot name="actions" /></div>' },
}))
vi.mock('../../src/components/pos/ReceiptPreviewPane.vue', () => ({
	default: { name: 'ReceiptPreviewPane', props: ['content', 'loading'], template: '<pre class="preview">{{ content }}</pre>' },
}))

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)
const mountOptions = { global: { mocks: { t: globalThis.t } } }

let EmailReceiptModal
let PrintReceiptModal

beforeAll(async () => {
	EmailReceiptModal = (await import('../../src/modals/EmailReceiptModal.vue')).default
	PrintReceiptModal = (await import('../../src/modals/PrintReceiptModal.vue')).default
}, 60000)

/**
 * Answer fetch calls from a list, one per call.
 *
 * @param {Array<object|Error>} answers `{ ok, body }` per call, or an Error to throw.
 * @return {object} The fetch mock.
 */
function answer(answers) {
	const queue = [...answers]
	const fetchMock = vi.fn(async () => {
		const next = queue.shift()
		if (next instanceof Error) throw next
		return { ok: next.ok, json: next.body === undefined ? async () => { throw new Error('no json') } : async () => next.body }
	})
	vi.stubGlobal('fetch', fetchMock)
	return fetchMock
}

beforeEach(() => {
	globalThis.OC = { requestToken: 'tok' }
	bus.emit.mockReset()
	templates.loadActiveReceiptTemplates.mockReset()
	templates.loadActiveReceiptTemplates.mockResolvedValue([{ id: 'loaded', name: 'Loaded' }, { id: 'bare' }])
})

afterEach(() => {
	vi.unstubAllGlobals()
})

const previewHeaders = { headers: { requesttoken: 'tok', 'OCS-APIREQUEST': 'true' } }
function postInit (body) {
  return {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' },
	body,
}
}

describe('EmailReceiptModal', () => {
	it('loads the active templates and the preview with the linked customer email', async () => {
		const fetchMock = answer([{ ok: true, body: { receipt: { text: 'RECEIPT', customerEmail: 'a@b.nl' } } }])
		const wrapper = mount(EmailReceiptModal, { props: { transactionId: 'tx 1' }, ...mountOptions })
		await flushPromises()
		expect(fetchMock).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/pos-transactions/tx 1/receipt/preview', previewHeaders)
		expect(wrapper.vm.templateOptions).toEqual([{ id: 'loaded', label: 'Loaded' }, { id: 'bare', label: 'bare' }])
		expect(wrapper.find('.preview').text()).toBe('RECEIPT')
		expect(wrapper.find('.receipt-modal__recipient strong').text()).toBe('a@b.nl')
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Email Receipt')
	})

	it('uses the given templates, previews the chosen one, and leaves the preview on a refusal', async () => {
		const fetchMock = answer([
			{ ok: true, body: { receipt: {} } },
			{ ok: false, body: { receipt: { text: 'no' } } },
			{ ok: true, body: undefined },
		])
		const wrapper = mount(EmailReceiptModal, { props: { transactionId: 'tx', templates: [{ id: 'g&1', name: 'Given' }] }, ...mountOptions })
		await flushPromises()
		expect(templates.loadActiveReceiptTemplates).not.toHaveBeenCalled()
		expect(wrapper.vm.templateOptions).toEqual([{ id: 'g&1', label: 'Given' }])
		expect(wrapper.find('.receipt-modal__recipient strong').text()).toBe('No customer email linked')
		expect(wrapper.find('button[disabled]').exists()).toBe(true)
		wrapper.vm.selectedTemplate = { id: 'g&1' }
		await wrapper.vm.loadPreview()
		expect(fetchMock).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/pos-transactions/tx/receipt/preview?template=g%261', previewHeaders)
		expect(wrapper.vm.previewText).toBe('')
		await wrapper.vm.loadPreview()
		expect(wrapper.vm.previewLoading).toBe(false)
	})

	it('sends with the chosen template, says so, refreshes the page and closes', async () => {
		const fetchMock = answer([
			{ ok: true, body: { receipt: { text: 'R', customerEmail: 'a@b.nl' } } },
			{ ok: true, body: { receipt: { status: 'sent', id: 'r1' } } },
		])
		const wrapper = mount(EmailReceiptModal, { props: { transactionId: 'tx' }, ...mountOptions })
		await flushPromises()
		wrapper.vm.selectedTemplate = { id: 't1' }
		await wrapper.vm.send()
		expect(fetchMock).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/pos-transactions/tx/receipt/email', postInit('{"template":"t1"}'))
		expect(wrapper.vm.statusType).toBe('success')
		expect(wrapper.vm.statusMessage).toBe('Receipt sent successfully')
		expect(wrapper.emitted('sent')).toEqual([[{ status: 'sent', id: 'r1' }]])
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.vm.sending).toBe(false)
	})

	it('says why a send failed: the refusal, a failed delivery, or the error', async () => {
		answer([
			{ ok: true, body: { receipt: { customerEmail: 'a@b.nl' } } },
			{ ok: false, body: { error: 'Nope' } },
			{ ok: false, body: undefined },
			{ ok: true, body: { receipt: { status: 'failed', error: 'SMTP down' } } },
			{ ok: true, body: { receipt: { status: 'failed' } } },
			new Error('offline'),
		])
		const wrapper = mount(EmailReceiptModal, { props: { transactionId: 'tx' }, ...mountOptions })
		await flushPromises()
		const said = []
		for (let i = 0; i < 5; i++) {
			await wrapper.vm.send()
			said.push([wrapper.vm.statusType, wrapper.vm.statusMessage])
		}
		expect(said).toEqual([
			['error', 'Nope'],
			['error', 'Error sending receipt: '],
			['error', 'SMTP down'],
			['error', 'Mail delivery failed (no SMTP relay configured).'],
			['error', 'Error sending receipt: offline'],
		])
		expect(wrapper.emitted('sent')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(bus.emit).not.toHaveBeenCalled()
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.receipt-modal__status').classes()).toContain('receipt-modal__status--error')
	})
})

describe('PrintReceiptModal', () => {
	it('loads the preview, prints without a template, shows the printer and closes', async () => {
		const fetchMock = answer([
			{ ok: true, body: { receipt: { text: 'R' } } },
			{ ok: true, body: { receipt: { printerDevice: 'Epson', escpos: 'xx' } } },
		])
		const wrapper = mount(PrintReceiptModal, { props: { transactionId: 'tx' }, ...mountOptions })
		await flushPromises()
		expect(wrapper.find('.dialog').attributes('data-name')).toBe('Print Receipt')
		expect(wrapper.find('.receipt-modal__device').exists()).toBe(false)
		await wrapper.vm.print()
		expect(fetchMock).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/pos-transactions/tx/receipt/print', postInit('{}'))
		expect(wrapper.vm.printerDevice).toBe('Epson')
		expect(wrapper.vm.statusMessage).toBe('Receipt sent to printer')
		expect(wrapper.vm.statusType).toBe('success')
		expect(wrapper.emitted('printed')).toEqual([[{ printerDevice: 'Epson', escpos: 'xx' }]])
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
		await wrapper.vm.$nextTick()
		expect(wrapper.find('.receipt-modal__device strong').text()).toBe('Epson')
		expect(wrapper.find('.receipt-modal__status').classes()).toContain('receipt-modal__status--success')
	})

	it('reads a receipt without a printer as none, and says why a print failed', async () => {
		answer([
			{ ok: true, body: undefined },
			{ ok: true, body: {} },
			{ ok: false, body: { error: 'Nope' } },
			{ ok: false, body: {} },
			new Error('offline'),
		])
		const wrapper = mount(PrintReceiptModal, { props: { transactionId: 'tx' }, ...mountOptions })
		await flushPromises()
		await wrapper.vm.print()
		expect(wrapper.vm.printerDevice).toBe('')
		expect(wrapper.emitted('printed')).toEqual([[undefined]])
		const said = []
		for (let i = 0; i < 3; i++) {
			await wrapper.vm.print()
			said.push([wrapper.vm.statusType, wrapper.vm.statusMessage])
		}
		expect(said).toEqual([
			['error', 'Nope'],
			['error', 'Error printing receipt: '],
			['error', 'Error printing receipt: offline'],
		])
		expect(wrapper.vm.printing).toBe(false)
	})
})

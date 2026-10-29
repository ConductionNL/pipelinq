// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Convert to case" asks first, in a dialog (board PqTicketOmzetten): the
 * section's button opens the dialog and posts nothing; the dialog names the
 * ticket and what goes with the case, and only its confirm button converts.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => url.replace('{id}', params?.id) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		props: ['name', 'eyebrow', 'subtitle', 'width'],
		render() {
			return h('div', { class: 'cn-dialog', 'data-eyebrow': this.eyebrow, 'data-subtitle': this.subtitle }, [this.name, this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	CnNoteCard: { props: ['text', 'type'], render() { return h('p', { class: 'cn-note', 'data-type': this.type }, this.text) } },
	CnResourceSelect: {
		props: ['modelValue', 'register', 'schema'],
		emits: ['update:modelValue'],
		render() {
			return h('select', { 'data-register': this.register, onChange: () => this.$emit('update:modelValue', 'b6c1f5a2-0d3e-4a7b-9c8d-2e1f0a9b8c7d') })
		},
	},
}))
vi.mock('@nextcloud/vue', () => {
	const button = {
		emits: ['click'],
		render() {
			return h('button', { onClick: () => this.$emit('click') }, [this.$slots.default?.()])
		},
	}
	const checkbox = {
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('input', { type: 'checkbox', onChange: () => this.$emit('update:modelValue', !this.modelValue) })
		},
	}
	return { NcButton: button, NcCheckboxRadioSwitch: checkbox, NcLoadingIcon: { render: () => h('span') }, NcNoteCard: { render() { return h('div', this.$slots.default?.()) } } }
})

let Dialog
let Section

beforeAll(async () => {
	// The section uses Nextcloud's global t(), as it does in the browser.
	globalThis.t = (app, text) => text
	Dialog = (await import('../../src/dialogs/ConvertToCaseDialog.vue')).default
	Section = (await import('../../src/views/requests/RequestConversionSection.vue')).default
})

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	store.fetchObject.mockReset()
})

const source = readFileSync(resolve(__dirname, '../../src/dialogs/ConvertToCaseDialog.vue'), 'utf8')
const global = { mixins: [{ methods: { t: (app, text) => text } }] }
const ticket = { reference: 'PQ-2026-0417', title: 'Vraag over vergunning straatfeest Parkstraat', description: 'Een straatfeest.', client: 'c1' }

describe('ConvertToCaseDialog', () => {
	it('names the ticket and what goes with the case', () => {
		const w = mount(Dialog, { props: { ticket, requester: 'Sanne de Vries' }, global })
		const dialog = w.find('.cn-dialog')
		expect(dialog.attributes('data-eyebrow')).toBe('Convert · PQ-2026-0417')
		expect(dialog.attributes('data-subtitle')).toBe('PQ-2026-0417 · Vraag over vergunning straatfeest Parkstraat')
		expect(w.text()).toContain('Sanne de Vries')
		expect(w.text()).toContain('Create case in dossiq')
	})

	it('asks for the case type from the source it is given, and passes the choice on', async () => {
		const w = mount(Dialog, { props: { ticket, caseTypeSource: { register: 'dossiq', schema: 'caseType' } }, global })
		expect(w.find('select').attributes('data-register')).toBe('dossiq')
		await w.find('select').trigger('change')
		await w.find('input[type="checkbox"]').trigger('change')
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		expect(w.emitted('confirm')[0][0]).toEqual({ caseType: 'b6c1f5a2-0d3e-4a7b-9c8d-2e1f0a9b8c7d', keepOpen: false, informCustomer: true })
	})

	// Board PqTicketOmzetten: "Klant informeren in Mijn Zuiddrecht / Sanne ziet het
	// zaaknummer en wie de zaak behandelt."
	it('offers to inform the customer in the named portal', async () => {
		const w = mount(Dialog, { props: { ticket, requester: 'Sanne de Vries', portalName: 'Mijn Zuiddrecht' }, global })
		expect(w.vm.informLabel).toBe('Inform the customer in Mijn Zuiddrecht')
		expect(w.text()).toContain('Sanne sees the case number and who handles the case.')
		await w.findAll('input[type="checkbox"]').at(-1).trigger('change')
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		expect(w.emitted('confirm')[0][0].informCustomer).toBe(false)
	})

	// Board PqTicketOmzetten (Ruben, 28 Oct): both options start ticked; the
	// handler unticks what does not apply.
	it('ticks keeping the ticket open and informing the customer by default', async () => {
		const w = mount(Dialog, { props: { ticket }, global })
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		expect(w.emitted('confirm')[0][0]).toMatchObject({ keepOpen: true, informCustomer: true })
	})

	// Board PqTicketOmzetten: a green strip with a check outline and the
	// sentence in bold green ink, no note card with its side bar.
	it('says dossiq is available in the board\'s green strip', () => {
		const w = mount(Dialog, { props: { ticket }, global })
		const strip = w.find('[data-testid="convert-to-case-available"]')
		expect(strip.exists()).toBe(true)
		expect(strip.attributes('role')).toBe('status')
		expect(strip.classes()).toContain('convert-to-case__available')
		expect(strip.find('.convert-to-case__available-icon').exists()).toBe(true)
		expect(strip.text()).toBe('dossiq is available and can take over this request as a case.')
		expect(w.find('.cn-note').exists()).toBe(false)
	})

	it('draws the strip on the success tint in bold success ink, and stacks each fact label over its value', () => {
		const style = source.slice(source.indexOf('<style'))
		expect(style).toMatch(/\.convert-to-case__available \{[^}]*background: var\(--cn-board-success-tint/)
		expect(style).toMatch(/\.convert-to-case__available \{[^}]*color: var\(--cn-board-success-ink/)
		expect(style).toMatch(/\.convert-to-case__available \{[^}]*font-weight: 600/)
		expect(style).toMatch(/\.convert-to-case__fact \{[^}]*flex-direction: column/)
		expect(style).toMatch(/\.convert-to-case__facts dt \{[^}]*text-align: start/)
		expect(style).toMatch(/\.convert-to-case__carried--block \{[^}]*background: var\(--cn-board-sunken-ground/)
		expect(style).toMatch(/\.convert-to-case__carried--block \{[^}]*border-radius: var\(--cn-board-control-radius, 8px\)/)
		expect(style).toMatch(/\.convert-to-case__heading \{[^}]*font-weight: 700/)
	})

	it('sets what goes with the case in a tinted block, each label over its value, the requester with the address', () => {
		const w = mount(Dialog, { props: { ticket, requester: 'Sanne de Vries', requesterAddress: 'Lindelaan 14' }, global })
		const block = w.find('.convert-to-case__carried')
		expect(block.classes()).toContain('convert-to-case__carried--block')
		expect(block.find('.convert-to-case__lock').exists()).toBe(true)
		expect(block.findAll('.convert-to-case__fact').length).toBeGreaterThanOrEqual(2)
		expect(block.text()).toContain('Sanne de Vries, Lindelaan 14')
	})

	it('puts each hint inside its checkbox row', () => {
		const w = mount(Dialog, { props: { ticket, requester: 'Sanne de Vries' }, global })
		expect(w.findAll('.convert-to-case__option .convert-to-case__hint')).toHaveLength(2)
	})

	it('emits confirm only from its confirm button', async () => {
		const w = mount(Dialog, { props: { ticket }, global })
		await w.find('[data-testid="convert-to-case-cancel"]').trigger('click')
		expect(w.emitted('confirm')).toBeUndefined()
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		expect(w.emitted('confirm')).toHaveLength(1)
	})
})

describe('RequestConversionSection', () => {
	it('opens the dialog and posts nothing until it is confirmed', async () => {
		http.get.mockResolvedValue({ data: { available: true, status: 'in_progress', canConvert: true, caseReference: '' } })
		http.post.mockResolvedValue({ data: { caseReference: 'case-1' } })
		store.fetchObject.mockImplementation((type) => Promise.resolve(type === 'ticket' ? ticket : { name: 'Sanne de Vries' }))
		const w = mount(Section, { props: { requestId: 't1' }, global })
		await flushPromises()
		await w.find('button').trigger('click')
		await flushPromises()
		expect(http.post).not.toHaveBeenCalled()
		expect(w.find('[data-testid="convert-to-case-dialog"]').exists() || w.find('.cn-dialog').exists()).toBe(true)
		await w.find('[data-testid="convert-to-case-confirm"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/handoff/request/t1/convert-to-case', { keepOpen: true, informCustomer: true })
	})

	it('hands the dialog the street line of the customer address', async () => {
		http.get.mockResolvedValue({ data: { available: true, status: 'in_progress', canConvert: true, caseReference: '' } })
		store.fetchObject.mockImplementation((type) => Promise.resolve(type === 'ticket' ? ticket : { name: 'Sanne de Vries', address: 'Lindelaan 14, 1234 AB Zuiddrecht' }))
		const w = mount(Section, { props: { requestId: 't1' }, global })
		await flushPromises()
		await w.find('button').trigger('click')
		await flushPromises()
		expect(w.find('.convert-to-case__carried').text()).toContain('Sanne de Vries, Lindelaan 14')
		expect(w.find('.convert-to-case__carried').text()).not.toContain('1234 AB')
	})
})

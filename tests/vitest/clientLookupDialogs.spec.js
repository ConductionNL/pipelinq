// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BRP and KVK lookups of the new request dialog: what each sends, and
 * what it hands to the form.
 *
 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => `/index.php${path}` }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text) => text }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name', 'subtitle', 'width'],
		setup: (props, { slots }) => () => h('div', [slots.default?.(), slots.actions?.()]),
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		setup: (props, { slots, emit, attrs }) => () => h('button', { ...attrs, disabled: props.disabled, onClick: () => emit('click') }, slots.default?.()),
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		setup: (props, { emit, attrs }) => () => h('input', { ...attrs, value: props.modelValue, onInput: (e) => emit('update:modelValue', e.target.value) }),
	},
}))

let BrpDialog
let KvkDialog

beforeAll(async () => {
	globalThis.t = (app, text) => text
	BrpDialog = (await import('../../src/dialogs/ClientBrpLookupDialog.vue')).default
	KvkDialog = (await import('../../src/dialogs/ClientKvkLookupDialog.vue')).default
}, 60000)

describe('ClientBrpLookupDialog', () => {
	it('asks the BRP with the reason and the purpose binding and hands the person on', async () => {
		const person = { givenNames: 'Sanne', surname: 'Vries' }
		const http = { post: vi.fn().mockResolvedValue({ data: { persoon: person } }) }
		const wrapper = mount(BrpDialog, { props: { http } })

		expect(wrapper.get('[data-testid="client-brp-lookup"]').attributes('disabled')).toBeDefined()
		await wrapper.get('[data-testid="client-brp-bsn"]').setValue('999993653')
		await wrapper.get('[data-testid="client-brp-lookup"]').trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/brp/lookup', {
			bsn: '999993653',
			verzoekreden: 'Reguliere verzoekbehandeling',
			purposeBinding: 'Publieke taak — Wet BRP art. 3.3',
		})
		expect(wrapper.emitted('found')).toEqual([[person]])
		expect(wrapper.vm.bsn).toBe('')
	})

	it('shows the server\'s refusal and hands nothing on', async () => {
		const http = { post: vi.fn().mockRejectedValue({ response: { data: { error: 'Forbidden' } } }) }
		const wrapper = mount(BrpDialog, { props: { http } })
		await wrapper.get('[data-testid="client-brp-bsn"]').setValue('999993653')
		await wrapper.vm.lookUp()

		expect(wrapper.get('[role="alert"]').text()).toBe('Forbidden')
		expect(wrapper.emitted('found')).toBeUndefined()
	})

	it('says so when the BRP found nobody', async () => {
		const http = { post: vi.fn().mockResolvedValue({ data: { errorMessage: 'BSN niet aangetroffen in BRP — controleer invoer.' } }) }
		const wrapper = mount(BrpDialog, { props: { http } })
		await wrapper.get('[data-testid="client-brp-bsn"]').setValue('999993653')
		await wrapper.vm.lookUp()

		expect(wrapper.get('[role="alert"]').text()).toBe('BSN niet aangetroffen in BRP — controleer invoer.')
		expect(wrapper.emitted('found')).toBeUndefined()
	})

	it('does not send a BSN that is not nine digits', async () => {
		const http = { post: vi.fn() }
		const wrapper = mount(BrpDialog, { props: { http } })
		await wrapper.get('[data-testid="client-brp-bsn"]').setValue('12345')
		await wrapper.vm.lookUp()

		expect(http.post).not.toHaveBeenCalled()
	})
})

describe('ClientKvkLookupDialog', () => {
	const company = { kvkNummer: '12345678', naam: 'Bakkerij De Graaf', rechtsvorm: 'Besloten Vennootschap', adres: { plaats: 'Utrecht' } }

	it('searches by the typed text and hands the picked company on', async () => {
		const http = { get: vi.fn().mockResolvedValue({ data: { results: [company, { naam: 'Without number' }] } }) }
		const wrapper = mount(KvkDialog, { props: { http } })

		await wrapper.get('[data-testid="client-kvk-query"]').setValue('  Bakkerij De Graaf ')
		await wrapper.get('[data-testid="client-kvk-search"]').trigger('click')
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/kvk/search', { params: { q: 'Bakkerij De Graaf' } })
		const results = wrapper.findAll('.client-kvk__result')
		expect(results).toHaveLength(1)
		expect(results[0].text()).toContain('12345678 · Besloten Vennootschap · Utrecht')

		await wrapper.get('[data-testid="client-kvk-result-12345678"]').trigger('click')
		expect(wrapper.emitted('picked')).toEqual([[company]])
	})

	it('says when no company was found, and shows a failed search', async () => {
		const http = { get: vi.fn().mockResolvedValueOnce({ data: { results: [] } }).mockRejectedValueOnce({ response: { data: { error: 'Type a company name or KVK number' } } }) }
		const wrapper = mount(KvkDialog, { props: { http } })
		wrapper.vm.query = 'Nobody'
		await wrapper.vm.search()
		expect(wrapper.text()).toContain('No company found.')

		await wrapper.vm.search()
		expect(wrapper.get('[role="alert"]').text()).toBe('Type a company name or KVK number')
	})

	it('does not search an empty text', async () => {
		const http = { get: vi.fn() }
		const wrapper = mount(KvkDialog, { props: { http } })
		wrapper.vm.query = '   '
		await wrapper.vm.search()
		expect(http.get).not.toHaveBeenCalled()
	})
})

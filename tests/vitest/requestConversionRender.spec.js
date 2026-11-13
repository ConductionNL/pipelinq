// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the request conversion section shows: nothing without a request,
 * a spinner while availability loads, the convert button when conversion is
 * possible, and the converted notice with a copyable case reference.
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => url.replace('{id}', params?.id) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({ fetchObject: vi.fn(async () => null) }) }))
vi.mock('../../src/dialogs/ConvertToCaseDialog.vue', () => ({
	default: { name: 'ConvertToCaseDialog', props: ['portalName', 'caseTypeSource', 'busy'], render() { return h('div', { class: 'convert-dialog', 'data-portal': this.portalName }) } },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		props: ['disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { render: () => h('span', { class: 'spinner' }) },
	NcNoteCard: { render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
}))

let Section

beforeAll(async () => {
	globalThis.t = (app, text) => text
	Section = (await import('../../src/views/requests/RequestConversionSection.vue')).default
}, 60000)

beforeEach(() => {
	http.get.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})

function mountSection(props = { requestId: 't1' }, provide = {}) {
	return mount(Section, { props, global: { mocks: { t: globalThis.t }, provide } })
}

describe('RequestConversionSection rendering', () => {
	it('renders nothing and asks nothing without a request id', async () => {
		const wrapper = mountSection({})
		await flushPromises()

		expect(http.get).not.toHaveBeenCalled()
		expect(wrapper.find('.request-conversion-section').exists()).toBe(false)
	})

	it('shows a spinner while availability loads, then the convert button', async () => {
		let release
		http.get.mockReturnValue(new Promise((resolve) => { release = resolve }))
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.find('.spinner').exists()).toBe(true)
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/handoff/request/t1/availability')

		release({ data: { available: true, status: 'in_progress', canConvert: true, portalName: 'Mijn Gemeente' } })
		await flushPromises()

		expect(wrapper.find('.spinner').exists()).toBe(false)
		expect(wrapper.find('button').text()).toBe('Convert to case')
		expect(wrapper.find('.note').exists()).toBe(false)

		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(wrapper.find('.convert-dialog').exists()).toBe(true)
	})

	it('a request that cannot be converted, or whose availability fails, shows nothing', async () => {
		http.get.mockResolvedValueOnce({ data: { available: true, canConvert: false } })
		const blocked = mountSection()
		await flushPromises()
		expect(blocked.find('.request-conversion-section').exists()).toBe(false)

		http.get.mockRejectedValueOnce(new Error('down'))
		const failed = mountSection({ requestId: 't2' })
		await flushPromises()
		expect(failed.find('.request-conversion-section').exists()).toBe(false)
		expect(failed.vm.availability.canConvert).toBe(false)
	})

	it('a case reference alone marks the request converted, and copies', async () => {
		const writeText = vi.fn(async () => {})
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		http.get.mockResolvedValue({ data: { status: 'in_progress', canConvert: true, caseReference: 'ZAAK-2026-0042' } })
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('This request has been converted to a case.')
		expect(wrapper.find('.request-conversion-section__reference-value').text()).toBe('ZAAK-2026-0042')
		expect(wrapper.findAll('button').map((b) => b.text())).toEqual(['Copy'])

		await wrapper.find('button').trigger('click')
		await flushPromises()
		expect(writeText).toHaveBeenCalledWith('ZAAK-2026-0042')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Case reference copied.')
	})

	it('a converted request without a reference shows only the notice; a failed copy is reported', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw new Error('denied') }) } })
		http.get.mockResolvedValue({ data: { status: 'converted' } })
		const wrapper = mountSection()
		await flushPromises()

		expect(wrapper.find('.note').exists()).toBe(true)
		expect(wrapper.find('.request-conversion-section__reference').exists()).toBe(false)

		await wrapper.vm.copyReference()
		expect(dialogs.showError).toHaveBeenCalledWith('Could not copy the case reference.')
	})

	it('reads the request id from the section context when no prop is given', async () => {
		http.get.mockResolvedValue({ data: { canConvert: true } })
		mountSection({}, { cnSectionContext: { objectId: 'ctx-7', object: { id: 'ctx-7' } } })
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/handoff/request/ctx-7/availability')
	})
})

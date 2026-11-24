// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The request-to-case section before and after a conversion: which request
 * it reads availability for, what it shows for each answer, what the dialog
 * is opened with, and copying the case reference.
 *
 * @spec openspec/specs/request-management/spec.md#requirement-request-to-case-conversion-v1
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const store = vi.hoisted(() => ({ fetchObject: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url, params) => url.replace('{id}', params?.id) }))
vi.mock('@nextcloud/l10n', () => ({ translate: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		emits: ['click'],
		render() {
			return h('button', { class: 'nc-button', onClick: () => this.$emit('click') }, [this.$slots.default?.()])
		},
	},
	NcLoadingIcon: { render: () => h('span', { class: 'nc-loading' }) },
	NcNoteCard: { render() { return h('div', { class: 'nc-note' }, this.$slots.default?.()) } },
}))

// The dialog's own rendering is covered by its spec; here only what it is handed matters.
const dialogStub = vi.hoisted(() => ({
	name: 'ConvertToCaseDialog',
	props: ['ticket', 'requester', 'requesterAddress', 'busy', 'caseTypeSource', 'portalName'],
	emits: ['close', 'confirm'],
	render: () => null,
}))
vi.mock('../../src/dialogs/ConvertToCaseDialog.vue', () => ({ default: dialogStub }))

let Section

beforeAll(async () => {
	// The section uses Nextcloud's global t(), as it does in the browser.
	globalThis.t = (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text)
	Section = (await import('../../src/views/requests/RequestConversionSection.vue')).default
})

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	store.fetchObject.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
})


/**
 * @param {object} options Mount options: props and an injected section context.
 * @return {Promise<object>} The mounted section, after its availability read.
 */
async function mountSection({ props = { requestId: 't1' }, context } = {}) {
	const w = mount(Section, {
		props,
		global: {
			mixins: [{ methods: { t: (app, text) => text } }],
			provide: context === undefined ? {} : { cnSectionContext: context },
		},
	})
	await flushPromises()
	return w
}

describe('RequestConversionSection availability', () => {
	it('reads the availability of the request prop and keeps every field it uses', async () => {
		http.get.mockResolvedValue({ data: { available: 1, status: 'in_progress', canConvert: 'yes', caseReference: '', caseTypeSource: { kind: 'x' } } })

		const w = await mountSection()

		expect(http.get).toHaveBeenCalledTimes(1)
		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/handoff/request/t1/availability')
		expect(w.vm.availability).toEqual({ available: true, status: 'in_progress', canConvert: true, caseReference: '', caseTypeSource: { kind: 'x' } })
		expect(w.vm.loading).toBe(false)
		expect(w.find('.nc-button').text()).toBe('Convert to case')
		expect(w.find('.nc-note').exists()).toBe(false)
	})

	it('falls back to the section context id when no prop is given', async () => {
		http.get.mockResolvedValue({ data: {} })

		await mountSection({ props: {}, context: { value: { objectId: 'ctx-7' } } })

		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/handoff/request/ctx-7/availability')
	})

	it('reads nothing and renders nothing without a request id', async () => {
		const w = await mountSection({ props: {} })

		expect(http.get).not.toHaveBeenCalled()
		expect(w.vm.availability).toEqual({ available: false, status: '', canConvert: false, caseReference: '', caseTypeSource: null })
		expect(w.html()).not.toContain('request-conversion-section')
	})

	it('defaults missing fields of the answer', async () => {
		http.get.mockResolvedValue({ data: { available: true, canConvert: false } })

		const w = await mountSection()

		expect(w.vm.availability).toEqual({ available: true, status: '', canConvert: false, caseReference: '', caseTypeSource: null })
		expect(w.html()).not.toContain('request-conversion-section')
	})

	it('treats a failed read as unavailable and stops loading', async () => {
		http.get.mockRejectedValue(new Error('down'))

		const w = await mountSection()

		expect(w.vm.availability).toEqual({ available: false, status: '', canConvert: false, caseReference: '', caseTypeSource: null })
		expect(w.vm.loading).toBe(false)
		expect(w.find('.nc-button').exists()).toBe(false)
	})

	it('shows the converted notice and reference instead of the button once linked to a case', async () => {
		http.get.mockResolvedValue({ data: { available: true, status: 'in_progress', canConvert: true, caseReference: 'case-1' } })

		const w = await mountSection()

		expect(w.vm.isConverted).toBe(true)
		expect(w.vm.showConvertButton).toBe(false)
		expect(w.find('.nc-note').text()).toBe('This request has been converted to a case.')
		expect(w.find('code').text()).toBe('case-1')
		expect(w.findAll('.nc-button').map(b => b.text())).toEqual(['Copy'])
	})

	it('shows the converted notice without a reference row when none is known', async () => {
		http.get.mockResolvedValue({ data: { status: 'converted' } })

		const w = await mountSection()

		expect(w.find('.nc-note').exists()).toBe(true)
		expect(w.find('code').exists()).toBe(false)
	})

	it('reads the availability again when the request changes', async () => {
		http.get.mockResolvedValue({ data: {} })
		const w = await mountSection()

		await w.setProps({ requestId: 't2' })
		await flushPromises()

		expect(http.get).toHaveBeenLastCalledWith('/apps/pipelinq/api/handoff/request/t2/availability')
		expect(http.get).toHaveBeenCalledTimes(2)
	})
})

describe('RequestConversionSection.openDialog', () => {
	/**
	 * @return {Promise<object>} A section that may convert.
	 */
	async function convertible() {
		http.get.mockResolvedValue({ data: { available: true, status: 'in_progress', canConvert: true, portalName: 'ignored' } })
		return mountSection()
	}

	it('opens with the ticket, its client name and the first line of the address', async () => {
		const w = await convertible()
		store.fetchObject.mockImplementation(async (type, id) => (type === 'ticket'
			? { id, client: 'c-1', title: 'Leak' }
			: { name: 'Jansen', address: ' Dorpsstraat 1 , 1234 AB Dorp' }))

		await w.find('.nc-button').trigger('click')
		await flushPromises()

		expect(store.fetchObject).toHaveBeenNthCalledWith(1, 'ticket', 't1')
		expect(store.fetchObject).toHaveBeenNthCalledWith(2, 'client', 'c-1')
		expect(w.vm.dialogOpen).toBe(true)
		const dialog = w.findComponent(dialogStub)
		expect(dialog.props('ticket')).toEqual({ id: 't1', client: 'c-1', title: 'Leak' })
		expect(dialog.props('requester')).toBe('Jansen')
		expect(dialog.props('requesterAddress')).toBe('Dorpsstraat 1')
		expect(dialog.props('caseTypeSource')).toBeNull()
	})

	it('leaves the requester empty for a client without name or text address', async () => {
		const w = await convertible()
		store.fetchObject.mockImplementation(async type => (type === 'ticket' ? { client: 'c-2' } : { address: { street: 'x' } }))

		await w.vm.openDialog()

		expect(w.vm.requester).toBe('')
		expect(w.vm.requesterAddress).toBe('')
	})

	it('does not read a client when the ticket has none', async () => {
		const w = await convertible()
		store.fetchObject.mockResolvedValue({ client: '' })

		await w.vm.openDialog()

		expect(store.fetchObject).toHaveBeenCalledTimes(1)
		expect(w.vm.ticket).toEqual({ client: '' })
	})

	it('reads a missing ticket as empty', async () => {
		const w = await convertible()
		store.fetchObject.mockResolvedValue(null)

		await w.vm.openDialog()

		expect(w.vm.ticket).toEqual({})
		expect(w.vm.dialogOpen).toBe(true)
	})

	it('still opens with an empty ticket when reading fails', async () => {
		const w = await convertible()
		store.fetchObject.mockRejectedValue(new Error('gone'))

		await w.vm.openDialog()

		expect(w.vm.ticket).toEqual({})
		expect(w.vm.dialogOpen).toBe(true)
	})

	it('closes when the dialog says so', async () => {
		const w = await convertible()
		store.fetchObject.mockResolvedValue({})
		await w.vm.openDialog()
		await flushPromises()

		w.findComponent(dialogStub).vm.$emit('close')
		await flushPromises()

		expect(w.vm.dialogOpen).toBe(false)
		expect(w.findComponent(dialogStub).exists()).toBe(false)
	})
})

describe('RequestConversionSection.copyReference', () => {
	/**
	 * @return {Promise<object>} A converted section.
	 */
	async function converted() {
		http.get.mockResolvedValue({ data: { status: 'converted', caseReference: 'case-42' } })
		return mountSection()
	}

	it('copies the reference and says so', async () => {
		const writeText = vi.fn().mockResolvedValue(undefined)
		vi.stubGlobal('navigator', { clipboard: { writeText } })
		const w = await converted()

		await w.find('.nc-button').trigger('click')
		await flushPromises()

		expect(writeText).toHaveBeenCalledWith('case-42')
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Case reference copied.')
		expect(dialogs.showError).not.toHaveBeenCalled()
	})

	it('says when the clipboard refuses', async () => {
		vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) } })
		const w = await converted()

		await w.vm.copyReference()

		expect(dialogs.showError).toHaveBeenCalledWith('Could not copy the case reference.')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
	})
})

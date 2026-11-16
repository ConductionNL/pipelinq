// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The organisation enrichment dialog when things go wrong, and its download:
 * a failed lookup or write says so and keeps the dialog open, a malformed
 * answer proposes nothing, and Download writes every proposal as CSV.
 *
 * @spec openspec/specs/client-management/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
	CnStatusBadge: { props: ['label'], render() { return h('span', { class: 'pill' }, this.label) } },
}))
vi.mock('@nextcloud/vue', () => {
	const tag = (name) => ({ props: ['disabled', 'variant'], emits: ['click'], render() { return h(name, { onClick: () => this.$emit('click') }, this.$slots.default?.()) } })
	return { NcButton: tag('button'), NcLoadingIcon: tag('span'), NcNoteCard: tag('div') }
})

let Dialog
beforeAll(async () => {
	Dialog = (await import('../../src/dialogs/OrganisationEnrichDialog.vue')).default
})

const PROPOSALS = {
	proposals: [
		{ clientId: 'c1', name: 'Bakkerij "De Molen"', field: 'kvkNumber', current: '', found: '00001008', source: 'KVK', differs: false },
		{ clientId: 'c2', name: 'Dagblad B.V.', field: 'website', current: null, found: 'https://example.nl', source: 'KVK', differs: true },
	],
	notFound: [],
}

const global = { mixins: [{ methods: { t: (app, text) => text, n: (app, one, many, count) => (count === 1 ? one : many).replace('%n', count) } }] }

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('OrganisationEnrichDialog failures and download', () => {
	it('says the lookup failed and proposes nothing', async () => {
		http.post.mockRejectedValue(new Error('offline'))
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1'] }, global })
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/organisations/enrich/proposals', { ids: ['c1'] })
		expect(dialogs.showError).toHaveBeenCalledWith('Could not look the organisations up.')
		expect(wrapper.vm.proposals).toEqual([])
		expect(wrapper.vm.loading).toBe(false)
	})

	it('treats a malformed answer as nothing found', async () => {
		http.post.mockResolvedValue({ data: { proposals: 'none', notFound: null } })
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1'] }, global })
		await flushPromises()

		expect(wrapper.vm.proposals).toEqual([])
		expect(wrapper.vm.notFound).toEqual([])
		expect(wrapper.vm.differing).toBe(0)
		expect(wrapper.vm.acceptedCount).toBe(0)
	})

	it('keeps the dialog open when the write fails', async () => {
		http.post.mockImplementation((url) => (url.endsWith('/proposals')
			? Promise.resolve({ data: PROPOSALS })
			: Promise.reject(new Error('500'))))
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1', 'c2'] }, global })
		await flushPromises()

		await wrapper.vm.apply()

		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/organisations/enrich/apply', {
			changes: [{ clientId: 'c1', field: 'kvkNumber', value: '00001008' }],
		})
		expect(dialogs.showError).toHaveBeenCalledWith('Could not fill in the details.')
		expect(bus.emit).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(wrapper.vm.saving).toBe(false)
	})

	it('counts no fields when the server does not say how many it filled in', async () => {
		http.post.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/proposals') ? PROPOSALS : {} }))
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1'] }, global })
		await flushPromises()

		await wrapper.vm.apply()

		expect(dialogs.showSuccess).toHaveBeenCalledWith('0 fields filled in.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('downloads every proposal as quoted CSV, with known fields labelled', async () => {
		http.post.mockResolvedValue({ data: PROPOSALS })
		const wrapper = mount(Dialog, { props: { selectedIds: ['c1', 'c2'] }, global })
		await flushPromises()
		let blob = null
		vi.stubGlobal('URL', { createObjectURL: vi.fn((b) => { blob = b; return 'blob:1' }), revokeObjectURL: vi.fn() })
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

		wrapper.vm.download()

		expect(click).toHaveBeenCalledTimes(1)
		expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:1')
		expect(blob.type).toBe('text/csv')
		expect(await blob.text()).toBe(
			'"Organisation","Field","Now","Found","Source"\n'
			+ '"Bakkerij ""De Molen""","KvK number","","00001008","KVK"\n'
			+ '"Dagblad B.V.","website","","https://example.nl","KVK"\n',
		)
	})
})

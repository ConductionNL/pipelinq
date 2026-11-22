// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Fill in from KvK dialog mounted for real: Preview asks OpenRegister for a
 * previewed job of pipelinq:fill-from-kvk on the selection, the counts become
 * badges, Confirm commits that job and refreshes the list, and a refusal shows
 * with Preview back. HTTP, toasts and the event bus are mocked.
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/specs/client-registry-numbers/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
}))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
const toasts = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/dialogs', () => toasts)
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('vue-material-design-icons/Check.vue', async () => {
	const { defineComponent, h } = await import('vue')
	return { default: defineComponent({ name: 'Check', render: () => h('i') }) }
})

/**
 * A stand-in that keeps its string attributes, renders its slots and emits
 * `click` unless disabled.
 *
 * @param {string} name Component name.
 * @return {Promise<object>} The component.
 */
async function standIn(name) {
	const { defineComponent, h } = await import('vue')
	return defineComponent({
		name,
		inheritAttrs: false,
		props: { disabled: { type: Boolean, default: false } },
		emits: ['click', 'closing'],
		render() {
			const attrs = { class: name, onClick: () => !this.disabled && this.$emit('click') }
			for (const [key, value] of Object.entries(this.$attrs)) {
				if (typeof value === 'string') {
					attrs[key] = value
				}
			}
			return h('div', attrs, Object.values(this.$slots).map((slot) => slot()))
		},
	})
}

vi.mock('@nextcloud/vue', async () => ({
	NcButton: await standIn('NcButton'),
	NcLoadingIcon: await standIn('NcLoadingIcon'),
	NcNoteCard: await standIn('NcNoteCard'),
}))
vi.mock('@conduction/nextcloud-vue', async () => ({
	CnDialog: await standIn('CnDialog'),
	CnStatusBadge: await standIn('CnStatusBadge'),
}))

const BulkFillFromKvkModal = (await import('../../src/modals/BulkFillFromKvkModal.vue')).default

const t = (app, text) => text
const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

/**
 * The stand-in with the given test id.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} name The stand-in's component name.
 * @param {string} testId Its data-testid.
 * @return {object|undefined} The component wrapper.
 */
function byTestId(wrapper, name, testId) {
	return wrapper.findAllComponents({ name }).find((c) => c.attributes('data-testid') === testId)
}

/**
 * Mount the dialog over twenty selected organisations.
 *
 * @return {object} The wrapper.
 */
function open() {
	return mount(BulkFillFromKvkModal, {
		props: { selectedIds: Array.from({ length: 20 }, (v, i) => `c${i}`), register: 'pipelinq', schema: 'client' },
		global: { mocks: { t, n } },
	})
}

beforeEach(() => {
	vi.clearAllMocks()
	http.get.mockResolvedValue({ data: { '@self': { register: '5', schema: '7' } } })
})

describe('BulkFillFromKvkModal', () => {
	it('previews a fill-from-kvk job on the selection and shows its counts', async () => {
		http.post.mockResolvedValue({ data: { id: 41, processed: { counts: { applied: 8, skipped: 12 } } } })
		const wrapper = open()

		byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-preview').vm.$emit('click')
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/apps/openregister/api/objects/pipelinq/client/c0')
		expect(http.post).toHaveBeenCalledWith('/apps/openregister/api/bulk-jobs', {
			action: 'pipelinq:fill-from-kvk',
			parameters: {},
			selection: { ids: wrapper.props('selectedIds') },
			register: 5,
			schema: 7,
		})
		const badges = wrapper.findAllComponents({ name: 'CnStatusBadge' }).map((b) => b.attributes('label'))
		expect(badges).toEqual(['8 records will change', '12 are skipped: they already have these values or have no KvK number'])
		expect(byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-preview')).toBeUndefined()
	})

	it('commits the previewed job, refreshes the list and closes', async () => {
		http.post.mockResolvedValueOnce({ data: { id: 41, processed: { counts: { applied: 8, skipped: 12 } } } })
		http.post.mockResolvedValueOnce({ data: { id: 41, status: 'done' } })
		const wrapper = open()

		byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-preview').vm.$emit('click')
		await flushPromises()
		byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-confirm').vm.$emit('click')
		await flushPromises()

		expect(http.post).toHaveBeenLastCalledWith('/apps/openregister/api/bulk-jobs/41/commit')
		expect(toasts.showSuccess).toHaveBeenCalledWith('8 records changed.')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	it('cannot confirm a preview that changes nothing', async () => {
		http.post.mockResolvedValue({ data: { id: 42, processed: { counts: { applied: 0, skipped: 20 } } } })
		const wrapper = open()

		byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-preview').vm.$emit('click')
		await flushPromises()
		const confirm = byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-confirm')
		expect(confirm.props('disabled')).toBe(true)
		await confirm.trigger('click')
		await flushPromises()

		expect(http.post).toHaveBeenCalledTimes(1)
	})

	it('shows a refusal and offers Preview again', async () => {
		http.post.mockRejectedValue({ response: { data: { reason: 'The selection exceeds the ceiling of 500 records.' } } })
		const wrapper = open()

		byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-preview').vm.$emit('click')
		await flushPromises()

		expect(byTestId(wrapper, 'NcNoteCard', 'bulk-fill-kvk-refusal').text()).toBe('The selection exceeds the ceiling of 500 records.')
		expect(byTestId(wrapper, 'NcButton', 'bulk-fill-kvk-preview')).toBeDefined()
	})
})

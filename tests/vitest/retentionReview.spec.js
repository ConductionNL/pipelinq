// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Retention review lists only the client and contact entries of OpenRegister's
 * destruction lists, counts the tickets and leads that still point at each
 * client, and approves or rejects a list through OpenRegister's archival
 * routes after a confirm dialog.
 *
 * @spec openspec/changes/platform-client-retention/specs/client-retention/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

/**
 * A stub that renders its slot and forwards clicks.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The stub.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['name', 'description', 'variant', 'disabled', 'label', 'modelValue'],
		emits: ['click', 'update:modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-name': this.name, disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcTextArea: stub('NcTextArea', 'textarea'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name'],
		render() {
			return h('div', { 'data-dialog': this.name }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
const showSuccess = vi.fn()
const showError = vi.fn()
vi.mock('@nextcloud/dialogs', () => ({ showSuccess, showError }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('../../src/services/requestHeaders.js', () => ({ jsonRequestHeaders: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({
	initializeStores: async () => ({ settingsStore: { config: { client_schema: '12', contact_schema: '13' } } }),
}))

globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
globalThis.n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', String(count))

const { default: RetentionReview } = await import('../../src/views/settings/RetentionReview.vue')
const { default: DestructionListDecisionModal } = await import('../../src/modals/DestructionListDecisionModal.vue')

const LISTS = '/apps/openregister/api/archival/destruction-lists'

/**
 * A fetch double answering by path; records every call.
 *
 * @param {Record<string, object>} answers JSON per path; an `httpStatus` key sets the HTTP status.
 * @return {Function} The fetch double.
 */
function fakeFetch(answers) {
	return vi.fn(async (path) => {
		const answer = answers[path] ?? { httpStatus: 404 }
		const status = answer.httpStatus ?? 200
		return { ok: status < 400, status, statusText: String(status), json: async () => answer }
	})
}

const lists = {
	[LISTS]: { results: [{ uuid: 'L1', status: 'in_review', createdAt: '2026-11-01' }, { uuid: 'L2', status: 'in_review' }], configured: true },
	[`${LISTS}/L1`]: {
		status: 'in_review',
		objects: [
			{ uuid: 'c-1', title: 'Acme', schema: 12, archiefactiedatum: '2026-10-01' },
			{ uuid: 'p-1', title: 'Jan Jansen', schema: '13' },
			{ uuid: 'z-1', title: 'A case file', schema: 40 },
		],
	},
	[`${LISTS}/L2`]: { status: 'in_review', objects: [{ uuid: 'z-2', title: 'Only another app', schema: 40 }] },
	'/apps/openregister/api/objects/pipelinq/ticket?client=c-1&_limit=1': { total: 3 },
	'/apps/openregister/api/objects/pipelinq/lead?client=c-1&_limit=1': { total: 2 },
	[`${LISTS}/L1/approve`]: { status: 'approved' },
	[`${LISTS}/L1/reject`]: { status: 'rejected' },
}

/**
 * Mount the page over these answers and wait for it to load.
 *
 * @param {Record<string, object>} answers JSON per path.
 * @return {Promise<object>} The wrapper.
 */
async function mountPage(answers) {
	globalThis.fetch = fakeFetch(answers)
	const wrapper = mount(RetentionReview, { global: { mocks: { t: globalThis.t, n: globalThis.n } } })
	await flushPromises()
	return wrapper
}

/**
 * The button with this text.
 *
 * @param {object} wrapper The wrapper.
 * @param {string} text The label.
 * @return {object} The button.
 */
function button(wrapper, text) {
	return wrapper.findAll('button').find((candidate) => candidate.text() === text)
}

describe('Retention review', () => {
	beforeEach(() => {
		showSuccess.mockClear()
		showError.mockClear()
	})

	it('shows only the client and contact entries, and only lists that hold them', async () => {
		const wrapper = await mountPage(lists)

		expect(wrapper.findAll('[data-list]').map((s) => s.attributes('data-list'))).toEqual(['L1'])
		expect(wrapper.findAll('[data-entry]').map((r) => r.attributes('data-entry'))).toEqual(['c-1', 'p-1'])
		const client = wrapper.find('[data-entry="c-1"]').findAll('td').map((c) => c.text())
		expect(client[0]).toBe('Acme')
		expect(client[1]).toBe('Client')
		expect(client.slice(3)).toEqual(['3', '2'])
		const contact = wrapper.find('[data-entry="p-1"]').findAll('td').map((c) => c.text())
		expect(contact[1]).toBe('Contact person')
		expect(contact.slice(3)).toEqual(['', ''])
		expect(globalThis.fetch).not.toHaveBeenCalledWith(expect.stringContaining('client=p-1'), expect.anything())
	})

	it('approves a list only after the confirm dialog, then reloads', async () => {
		const wrapper = await mountPage(lists)
		await button(wrapper, 'Approve').trigger('click')
		expect(globalThis.fetch).not.toHaveBeenCalledWith(`${LISTS}/L1/approve`, expect.anything())

		const modal = wrapper.findComponent(DestructionListDecisionModal)
		expect(modal.text()).toContain('destroy 2 records on this list')
		modal.vm.$emit('confirm', {})
		await flushPromises()

		const call = globalThis.fetch.mock.calls.find(([path]) => path === `${LISTS}/L1/approve`)
		expect(call[1].method).toBe('POST')
		expect(JSON.parse(call[1].body)).toEqual({ action: 'approve_all' })
		expect(showSuccess).toHaveBeenCalledWith('Destruction list approved')
		expect(wrapper.findComponent(DestructionListDecisionModal).exists()).toBe(false)
	})

	it('rejects with the reason given in the dialog', async () => {
		const wrapper = await mountPage(lists)
		await button(wrapper, 'Reject').trigger('click')
		wrapper.findComponent(DestructionListDecisionModal).vm.$emit('confirm', { reason: 'Client still active' })
		await flushPromises()

		const call = globalThis.fetch.mock.calls.find(([path]) => path === `${LISTS}/L1/reject`)
		expect(JSON.parse(call[1].body)).toEqual({ reason: 'Client still active' })
		expect(showSuccess).toHaveBeenCalledWith('Destruction list rejected')
	})

	it('reports a refused decision and keeps the dialog open', async () => {
		const wrapper = await mountPage({ ...lists, [`${LISTS}/L1/approve`]: { httpStatus: 403, error: 'Archivist role required' } })
		await button(wrapper, 'Approve').trigger('click')
		wrapper.findComponent(DestructionListDecisionModal).vm.$emit('confirm', {})
		await flushPromises()

		expect(showError).toHaveBeenCalledWith('The decision could not be saved: Archivist role required')
		expect(wrapper.findComponent(DestructionListDecisionModal).exists()).toBe(true)
	})

	it('offers no decision on a list that is already decided', async () => {
		const wrapper = await mountPage({ ...lists, [`${LISTS}/L1`]: { ...lists[`${LISTS}/L1`], status: 'approved' } })

		expect(wrapper.find('[data-list="L1"]').text()).toContain('Approved')
		expect(button(wrapper, 'Approve')).toBeUndefined()
	})

	it('says when destruction lists are not set up, and when the lists cannot be read', async () => {
		const unset = await mountPage({ [LISTS]: { results: [], total: 0, configured: false } })
		expect(unset.find('[data-stub="NcEmptyContent"]').attributes('data-name')).toBe('Destruction lists are not set up')

		const empty = await mountPage({ [LISTS]: { results: [], configured: true } })
		expect(empty.find('[data-stub="NcEmptyContent"]').attributes('data-name')).toBe('Nothing to review')

		const failed = await mountPage({ [LISTS]: { httpStatus: 500, message: 'boom' } })
		expect(failed.find('[role="alert"]').text()).toBe('The destruction lists could not be loaded: boom')
	})
})

describe('the decision dialog', () => {
	const mountModal = (props) => mount(DestructionListDecisionModal, { props, global: { mocks: { t: globalThis.t, n: globalThis.n } } })

	it('needs a reason before it rejects', async () => {
		const wrapper = mountModal({ decision: 'reject', entryCount: 1 })
		const confirm = button(wrapper, 'Reject')
		expect(confirm.attributes('disabled')).toBeDefined()

		await wrapper.setData({ reason: '  Not yet  ' })
		await button(wrapper, 'Reject').trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[{ reason: 'Not yet' }]])
	})

	it('approves without a reason and names a single record in the singular', async () => {
		const wrapper = mountModal({ decision: 'approve', entryCount: 1 })
		expect(wrapper.text()).toContain('destroy 1 record on this list')
		await button(wrapper, 'Approve').trigger('click')
		expect(wrapper.emitted('confirm')).toEqual([[{}]])
	})
})

describe('the page wiring', () => {
	it('is a custom page in the settings section', () => {
		const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.d/91-client-retention.json'), 'utf8'))
		expect(fragment.pages[0]).toMatchObject({ id: 'RetentionReview', route: '/retention-review', type: 'custom', component: 'RetentionReviewView' })
		const layout = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.json'), 'utf8'))
		expect(layout.settingsSection).toContain('RetentionReview')
	})
})

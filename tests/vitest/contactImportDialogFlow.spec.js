// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * ContactImportDialog's search and import, mounted with fetch doubled: the
 * debounced search and its two-character floor, a failing or refused search,
 * the empty state, and importing the marked cards for a client or as clients.
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		emits: ['closing'],
		render() {
			return h('div', { class: 'cn-dialog' }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => {
	const tag = (name, cls) => ({ props: ['disabled', 'variant', 'modelValue', 'label'], emits: ['click', 'update:modelValue', 'trailingButtonClick'], render() { return h(name, { class: cls, disabled: this.disabled ? '' : undefined, onClick: () => this.$emit('click') }, [this.$slots.icon?.(), this.$slots.default?.()]) } })
	return { NcButton: tag('button', 'nc-button'), NcLoadingIcon: tag('span', 'nc-loading'), NcTextField: tag('div', 'nc-text-field') }
})

const { default: ContactImportDialog } = await import('../../src/dialogs/ContactImportDialog.vue')

const CARDS = [
	{ uid: 'a', name: 'Anouk Verhoef', email: 'a@example.nl', phone: '0612', addressBookKey: 'ab-1', alreadyLinked: false },
	{ uid: 'b', name: 'Anna de Vries', addressBookKey: 'ab-1', alreadyLinked: false },
	{ uid: 'c', name: '', addressBookKey: 'ab-2', alreadyLinked: true },
]

/**
 * A fetch Response double.
 *
 * @param {boolean} ok Whether the response is ok.
 * @param {object} body The JSON body.
 * @return {object} The response.
 */
const response = (ok, body = {}) => ({ ok, json: async () => body })

let fetchMock

beforeEach(() => {
	vi.useFakeTimers()
	fetchMock = vi.fn(async (url) => (url.includes('/search') ? response(true, { results: CARDS.map((card) => ({ ...card })) }) : response(true, { object: { id: 'new' } })))
	vi.stubGlobal('fetch', fetchMock)
	vi.stubGlobal('OC', { requestToken: 'token-1' })
})

afterEach(() => {
	vi.useRealTimers()
	vi.unstubAllGlobals()
})

/**
 * Type into the search box and let the debounce and the fetch settle.
 *
 * @param {object} wrapper The dialog.
 * @param {string} value What is typed.
 * @return {Promise<void>}
 */
async function search(wrapper, value) {
	await wrapper.findComponent('.nc-text-field').vm.$emit('update:modelValue', value)
	vi.advanceTimersByTime(300)
	await flushPromises()
}

/**
 * The action button whose text starts with the given label.
 *
 * @param {object} wrapper The dialog.
 * @param {string} label The label.
 * @return {object} The button.
 */
const actionButton = (wrapper, label) => wrapper.findAll('button.nc-button').find((b) => b.text().startsWith(label))

describe('ContactImportDialog search', () => {
	it('searches 300 ms after typing, encoded, and lists the cards with their meta line', async () => {
		const wrapper = mount(ContactImportDialog)

		await wrapper.findComponent('.nc-text-field').vm.$emit('update:modelValue', 'an co/1')
		vi.advanceTimersByTime(299)
		expect(fetchMock).not.toHaveBeenCalled()
		vi.advanceTimersByTime(1)
		await flushPromises()

		expect(fetchMock.mock.calls[0]).toEqual(['/apps/pipelinq/api/contacts-sync/search?q=an%20co%2F1', { headers: { 'Content-Type': 'application/json', requesttoken: 'token-1', 'OCS-APIREQUEST': 'true' } }])
		expect(wrapper.find('.import-dialog__count').text()).toBe('3 cards in your Nextcloud address books match "an co/1"')
		expect(wrapper.findAll('.import-result__name').map((el) => el.text())).toEqual(['Anouk Verhoef', 'Anna de Vries', 'Unknown'])
		expect(wrapper.findAll('.import-result__meta').map((el) => el.text())).toEqual(['a@example.nl · 0612', 'Unknown', 'Unknown'])
		expect(wrapper.findAll('[data-testid="import-result-toggle"]')).toHaveLength(2)
		expect(wrapper.find('.import-result__linked').text()).toBe('Already linked')
	})

	it('only the last of several quick keystrokes searches', async () => {
		const wrapper = mount(ContactImportDialog)
		const field = wrapper.findComponent('.nc-text-field')

		await field.vm.$emit('update:modelValue', 'an')
		vi.advanceTimersByTime(200)
		await field.vm.$emit('update:modelValue', 'ann')
		vi.advanceTimersByTime(300)
		await flushPromises()

		expect(fetchMock.mock.calls.map((call) => call[0])).toEqual(['/apps/pipelinq/api/contacts-sync/search?q=ann'])
	})

	it('under two characters clears the results without searching', async () => {
		const wrapper = mount(ContactImportDialog)
		await search(wrapper, 'an')
		fetchMock.mockClear()

		await search(wrapper, 'a')

		expect(fetchMock).not.toHaveBeenCalled()
		expect(wrapper.findAll('.import-result')).toHaveLength(0)
		expect(wrapper.text()).not.toContain('No contacts found')
	})

	it('clearing the box empties the query and the results', async () => {
		const wrapper = mount(ContactImportDialog)
		await search(wrapper, 'an')

		await wrapper.findComponent('.nc-text-field').vm.$emit('trailingButtonClick')

		expect(wrapper.findAll('.import-result')).toHaveLength(0)
		expect(wrapper.vm.query).toBe('')
	})

	it('a refused search keeps the old results; a failing one shows none found', async () => {
		const wrapper = mount(ContactImportDialog)
		await search(wrapper, 'an')

		fetchMock.mockResolvedValueOnce(response(false))
		await search(wrapper, 'anna')
		expect(wrapper.findAll('.import-result')).toHaveLength(3)

		fetchMock.mockRejectedValueOnce(new Error('offline'))
		await search(wrapper, 'anne')
		expect(wrapper.findAll('.import-result')).toHaveLength(0)
		expect(wrapper.find('.import-dialog__count').text()).toBe('No contacts found')
		expect(wrapper.find('.nc-loading').exists()).toBe(false)
	})
})

describe('ContactImportDialog import', () => {
	it('imports the marked cards as clients, links them and closes', async () => {
		const wrapper = mount(ContactImportDialog)
		await search(wrapper, 'an')
		const importAll = () => actionButton(wrapper, 'Import ')
		expect(importAll().attributes('disabled')).toBe('')

		const toggles = wrapper.findAll('[data-testid="import-result-toggle"]')
		await toggles[0].trigger('click')
		await toggles[1].trigger('click')
		expect(importAll().text()).toBe('Import 2 contacts')
		await importAll().trigger('click')
		await flushPromises()

		const posts = fetchMock.mock.calls.filter((call) => call[0] === '/apps/pipelinq/api/contacts-sync/import')
		expect(posts.map((call) => [call[1].method, JSON.parse(call[1].body)])).toEqual([
			['POST', { uid: 'a', addressBookKey: 'ab-1', type: 'client' }],
			['POST', { uid: 'b', addressBookKey: 'ab-1', type: 'client' }],
		])
		expect(wrapper.emitted('imported')).toEqual([[{ id: 'new' }], [{ id: 'new' }]])
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.findAll('.import-result__linked')).toHaveLength(3)
		expect(wrapper.vm.selected).toEqual([])
	})

	it('imports as contacts of a client, and a failed card stays unlinked', async () => {
		const wrapper = mount(ContactImportDialog, { props: { importType: 'contact', clientId: 'client-9' } })
		await search(wrapper, 'an')
		fetchMock.mockImplementation(async (url, init) => (JSON.parse(init.body).uid === 'a' ? response(false) : Promise.reject(new Error('offline'))))

		const toggles = wrapper.findAll('[data-testid="import-result-toggle"]')
		await toggles[0].trigger('click')
		await toggles[1].trigger('click')
		await actionButton(wrapper, 'Import ').trigger('click')
		await flushPromises()

		expect(fetchMock.mock.calls.filter((call) => call[1].body).map((call) => JSON.parse(call[1].body))).toEqual([
			{ uid: 'a', addressBookKey: 'ab-1', type: 'contact', clientId: 'client-9' },
			{ uid: 'b', addressBookKey: 'ab-1', type: 'contact', clientId: 'client-9' },
		])
		expect(wrapper.emitted('imported')).toBeUndefined()
		expect(wrapper.emitted('close')).toHaveLength(1)
		expect(wrapper.findAll('.import-result__linked')).toHaveLength(1)
	})

	it('cancel and closing the dialog both ask to close', async () => {
		const wrapper = mount(ContactImportDialog)

		await actionButton(wrapper, 'Cancel').trigger('click')
		await wrapper.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})

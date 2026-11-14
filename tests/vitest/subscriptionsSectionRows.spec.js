// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The subscriptions table's own behaviour: the source, ground and confirmed
 * columns filter and sort on what they show, a filter option toggles off
 * again, a person's view names the list per row and reloads after a change in
 * the manage modal, a failed load says so, and a date that cannot be read is
 * shown as written.
 *
 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-lists-subscribers-read-by-name
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ fetchListSubscriptions: vi.fn(), fetchContactSubscriptions: vi.fn() }))

vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), getLanguage: () => 'nl' }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: {
		name: 'CnDetailCard',
		props: ['title', 'subtitle'],
		template: '<section class="card"><h2>{{ title }}</h2><div class="actions"><slot name="actions" /></div><slot /></section>',
	},
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label'], template: '<span>{{ label }}</span>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcActions: { name: 'NcActions', template: '<div class="actions-menu" v-bind="$attrs"><slot name="icon" /><slot /></div>' },
	NcActionCheckbox: { name: 'NcActionCheckbox', props: ['modelValue'], emits: ['update:modelValue'], template: '<label class="filter-option"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>' },
	NcButton: { name: 'NcButton', template: '<button v-bind="$attrs"><slot name="icon" /><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
}))
vi.mock('../../src/dialogs/ListSignupsManageDialog.vue', () => ({ default: { name: 'ListSignupsManageDialog', emits: ['close', 'changed'], template: '<div class="signups-modal" />' } }))
vi.mock('../../src/modals/SubscriptionManageModal.vue', () => ({ default: { name: 'SubscriptionManageModal', emits: ['close', 'changed'], template: '<div class="manage-modal" />' } }))
vi.mock('../../src/services/mailingListApi.js', () => api)

const { default: SubscriptionsSection } = await import('../../src/components/marketing/SubscriptionsSection.vue')

const ROWS = [
	{ id: 's1', email: 'a@bakkerij.example', name: 'Bakkerij', state: 'confirmed', source: 'website', lawfulBasis: 'consent', confirmedAt: '2026-10-02T14:40:00Z' },
	{ id: 's2', email: 'b@sport.example', state: 'pending', lawfulBasis: 'contract' },
	{ id: 's3', email: 'c@garage.example', state: 'confirmed', source: 'import', lawfulBasis: 'soft-opt-in', confirmedAt: '2026-09-01T08:00:00Z' },
]

/**
 * Mount the section and wait for its rows.
 *
 * @param {object} props The section's props.
 * @return {Promise<object>} The wrapper.
 */
async function mounted(props) {
	const wrapper = mount(SubscriptionsSection, { props, global: { mocks: { t: (_app, s) => s } } })
	await flushPromises()
	return wrapper
}

const emails = (wrapper) => wrapper.findAll('tbody .subscriptions-section__who').map((who) => who.text())

beforeEach(() => {
	api.fetchListSubscriptions.mockReset().mockResolvedValue({ data: ROWS })
	api.fetchContactSubscriptions.mockReset().mockResolvedValue([])
})

describe('the subscriptions table', () => {
	it('filters on the source as shown, a missing source as a dash, and drops the filter again', async () => {
		const wrapper = await mounted({ listId: 'l-1' })
		const options = () => wrapper.find('[data-testid="subscriptions-filter-source"]').findAll('.filter-option')
		expect(options().map((option) => option.text())).toEqual(['import', 'website', '—'])

		await options()[2].find('input').setValue(true)
		expect(emails(wrapper)).toEqual(['b@sport.example'])

		await options()[0].find('input').setValue(true)
		expect(emails(wrapper)).toEqual(['b@sport.example', 'c@garage.example'])

		await options()[2].find('input').setValue(false)
		await options()[0].find('input').setValue(false)
		expect(wrapper.vm.filters.source).toEqual([])
		expect(emails(wrapper)).toHaveLength(3)
	})

	it('sorts on the ground by its words and on the confirmation date, unconfirmed first', async () => {
		const wrapper = await mounted({ listId: 'l-1' })

		await wrapper.find('[data-testid="subscriptions-sort-ground"]').trigger('click')
		expect(emails(wrapper)).toEqual(['Bakkerij', 'b@sport.example', 'c@garage.example'])

		await wrapper.find('[data-testid="subscriptions-sort-confirmed"]').trigger('click')
		expect(emails(wrapper)).toEqual(['b@sport.example', 'c@garage.example', 'Bakkerij'])

		await wrapper.find('[data-testid="subscriptions-sort-state"]').trigger('click')
		expect(emails(wrapper)[0]).toBe('Bakkerij')
	})

	it('names the list on each row of a person, and reloads after the manage modal changed something', async () => {
		api.fetchContactSubscriptions.mockResolvedValue([
			{ id: 'x1', listId: 'nieuwsbrief', state: 'confirmed' },
			{ id: 'x2', state: 'pending' },
		])
		const wrapper = await mounted({ contactId: 'c-1' })
		expect(wrapper.find('caption').text()).toBe('Mailing lists this person is on')
		expect(emails(wrapper)).toEqual(['nieuwsbrief', '—'])

		await wrapper.find('[data-testid="subscriptions-manage"]').trigger('click')
		expect(wrapper.find('.manage-modal').exists()).toBe(true)
		wrapper.findComponent({ name: 'SubscriptionManageModal' }).vm.$emit('changed')
		await flushPromises()

		expect(wrapper.find('.manage-modal').exists()).toBe(false)
		expect(api.fetchContactSubscriptions).toHaveBeenCalledTimes(2)
	})

	it('closes the sign-ups dialog of a list card', async () => {
		const wrapper = await mounted({ listId: 'l-1', card: true })
		await wrapper.find('[data-testid="subscriptions-manage-signups"]').trigger('click')
		expect(wrapper.find('.signups-modal').exists()).toBe(true)

		wrapper.findComponent({ name: 'ListSignupsManageDialog' }).vm.$emit('close')
		await flushPromises()
		expect(wrapper.find('.signups-modal').exists()).toBe(false)
	})

	it('says so when the subscriptions cannot be loaded', async () => {
		api.fetchListSubscriptions.mockRejectedValue(new Error('500'))
		const wrapper = await mounted({ listId: 'l-1' })

		expect(wrapper.find('.note[data-type="error"]').text()).toBe('The subscriptions could not be loaded.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('shows a date it cannot read as written, and a dash for none', async () => {
		const wrapper = await mounted({ listId: 'l-1' })

		expect(wrapper.vm.formatDate('2026-13-45 later')).toBe('2026-13-45')
		expect(wrapper.vm.formatDate('')).toBe('—')
	})
})

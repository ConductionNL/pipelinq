// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mailing list page as board PqMailinglijst draws it: every list detail in
 * the Lijstgegevens card (none behind "Show all", none edited inline), the
 * side column with History on top and two titled cards under it, and the
 * Subscribers card with its subtitle and Refresh in the header, each row the
 * name above the address, dates in the reader's language.
 *
 * @spec openspec/changes/mailing-list-page-as-board/specs/marketing-lists/spec.md#requirement-the-mailing-list-page-is-the-board
 * @spec openspec/changes/mailing-list-page-as-board/specs/marketing-lists/spec.md#requirement-a-mailing-lists-subscribers-read-by-name
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), getLanguage: () => 'nl' }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: {
		name: 'CnDetailCard',
		props: ['title', 'subtitle'],
		template: '<section class="card"><h2>{{ title }}</h2><span class="sub">{{ subtitle }}</span><div class="actions"><slot name="actions" /></div><slot /></section>',
	},
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label'], template: '<span>{{ label }}</span>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcActions: { name: 'NcActions', template: '<div class="actions-menu" v-bind="$attrs"><slot /></div>' },
	NcActionCheckbox: { name: 'NcActionCheckbox', props: ['modelValue'], emits: ['update:modelValue'], template: '<label class="filter-option"><input type="checkbox" :checked="modelValue" @change="$emit(\'update:modelValue\', $event.target.checked)"><slot /></label>' },
	NcButton: { name: 'NcButton', template: '<button v-bind="$attrs"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div><slot /></div>' },
}))
vi.mock('../../src/dialogs/ListSignupsManageDialog.vue', () => ({ default: { name: 'ListSignupsManageDialog', template: '<div class="signups-modal" />' } }))
vi.mock('../../src/modals/SubscriptionManageModal.vue', () => ({ default: { name: 'SubscriptionManageModal', template: '<div />' } }))
vi.mock('../../src/services/mailingListApi.js', () => ({
	fetchListSubscriptions: vi.fn(async () => ({
		data: [
			{ id: 's1', email: 'info@bakkerijdegraaf.example', name: 'Bakkerij De Graaf', state: 'confirmed', lawfulBasis: 'consent', confirmedAt: '2026-10-02T14:40:00Z' },
			{ id: 's2', email: 'balie@sportcentrum.example', state: 'pending', lawfulBasis: 'consent' },
		],
	})),
	fetchContactSubscriptions: vi.fn(async () => []),
}))

const { default: SubscriptionsSection } = await import('../../src/components/marketing/SubscriptionsSection.vue')

const ROOT = path.resolve(__dirname, '../..')
const part = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.d/76-marketing-lists.json'), 'utf8'))
const page = part.pages.find((p) => p.id === 'MailingListDetail')
const widget = (id) => page.config.widgets.find((w) => w.id === id)
const registry = fs.readFileSync(path.join(ROOT, 'src/registry.js'), 'utf8')

describe('the mailing list page as the board', () => {
	it('shows every list detail, none behind Show all and none edited inline', () => {
		const data = widget('mailing-list-data').content
		expect(data.collapsedFields).toBe(data.include.length)
		expect(data.editable).toBe(false)
	})

	it('keeps History on top of the side column, three changes long', () => {
		expect(page.config.sideColumnOrder).toBe('declared')
		expect(page.config.sideColumn[0]).toBe('list-side-history')
		expect(widget('list-side-history').content.maxDisplay).toBe(3)
	})

	it('draws both History cards as the Dq history: stacked rows, compact, in words', () => {
		for (const id of ['list-side-history', 'list-history']) {
			const history = widget(id)
			expect(history.type).toBe('audit-trail')
			expect(history.content.layout).toBe('stacked')
			expect(history.content.density).toBe('compact')
			expect(history.content.excludeActions).toEqual(['read'])
			expect(history.content.actionLabels).toEqual({ create: 'Created by {actor}', update: 'Updated' })
		}
	})

	it('draws the sign-up form and the subscriber preview as titled cards', () => {
		expect(registry).toMatch(/sectionWidget\('ListSignupFormWidget',[^\n]*\{ card: true \}\)/)
		expect(registry).toMatch(/sectionWidget\('ListSubscriberViewWidget',[^\n]*\{ card: true \}\)/)
	})

	it('lets the Subscribers section draw its own card', () => {
		const body = page.config.bodyWidgets.find((w) => w.id === 'list-subscriptions')
		expect(body.card).toBe(false)
		expect(body.props.card).toBe(true)
	})

	it('heads the Subscribers card with its subtitle and Refresh, rows read by name', async () => {
		const wrapper = mount(SubscriptionsSection, {
			props: { listId: 'l-1', card: true },
			global: { mocks: { t: (_app, s) => s } },
		})
		await flushPromises()
		expect(wrapper.find('h2').text()).toBe('Subscribers')
		expect(wrapper.find('.sub').text()).toBe('Subscribers on this list')
		expect(wrapper.find('.actions [data-testid="subscriptions-refresh"]').exists()).toBe(true)
		expect(wrapper.find('.subscriptions-section__head').exists()).toBe(false)
		const first = wrapper.findAll('tbody tr')[0]
		expect(first.find('.subscriptions-section__who').text()).toBe('Bakkerij De Graaf')
		expect(first.find('.subscriptions-section__email').text()).toBe('info@bakkerijdegraaf.example')
		expect(first.text()).toContain('2 okt 2026')
		expect(wrapper.findAll('tbody tr')[1].find('.subscriptions-section__who').text()).toBe('balie@sportcentrum.example')
	})
})

describe('the Subscribers table and header line', () => {
	it('heads the page with the confirmed count, who made the list and when', () => {
		expect(page.config.headerMeta).toMatchObject({ count: 'confirmed', other: expect.stringContaining('{count:confirmed} confirmed subscribers · created by {createdBy|user} on {createdAt|longdate}') })
		expect(page.config.headerMetaCounts.confirmed).toEqual({ register: 'pipelinq', schema: 'subscription', filter: { listId: '@objectId', state: 'confirmed' } })
	})

	it('sorts on a column, the same column again turns the order', async () => {
		const wrapper = mount(SubscriptionsSection, { props: { listId: 'l-1', card: true }, global: { mocks: { t: (_app, s) => s } } })
		await flushPromises()
		const names = () => wrapper.findAll('tbody .subscriptions-section__who').map((el) => el.text())
		await wrapper.find('[data-testid="subscriptions-sort-subscriber"]').trigger('click')
		expect(names()).toEqual(['Bakkerij De Graaf', 'balie@sportcentrum.example'])
		await wrapper.find('[data-testid="subscriptions-sort-subscriber"]').trigger('click')
		expect(names()).toEqual(['balie@sportcentrum.example', 'Bakkerij De Graaf'])
	})

	it('filters a column on the values its rows hold', async () => {
		const wrapper = mount(SubscriptionsSection, { props: { listId: 'l-1', card: true }, global: { mocks: { t: (_app, s) => s } } })
		await flushPromises()
		const status = wrapper.find('[data-testid="subscriptions-filter-state"]')
		const options = status.findAll('.filter-option')
		expect(options.map((o) => o.text())).toEqual(['Confirmed', 'Waiting for confirmation'])
		await options[1].find('input').setValue(true)
		expect(wrapper.findAll('tbody tr')).toHaveLength(1)
		expect(wrapper.find('tbody .subscriptions-section__who').text()).toBe('balie@sportcentrum.example')
	})

	it('opens Manage sign-ups from the card header', async () => {
		const wrapper = mount(SubscriptionsSection, { props: { listId: 'l-1', card: true }, global: { mocks: { t: (_app, s) => s } } })
		await flushPromises()
		await wrapper.find('.actions [data-testid="subscriptions-manage-signups"]').trigger('click')
		expect(wrapper.find('.signups-modal').exists()).toBe(true)
	})
})

/**
 * @spec openspec/changes/mailing-list-subscribers-card-table/specs/marketing-lists/spec.md#requirement-the-subscribers-table-fits-its-card
 */
describe('the PqMailinglijst board look (pl4)', () => {
	const sfc = fs.readFileSync(path.join(ROOT, 'src/components/marketing/SubscriptionsSection.vue'), 'utf8')
	const style = sfc.slice(sfc.indexOf('<style'))

	it('lays the list details out in the two columns the board draws', () => {
		const data = widget('mailing-list-data').content
		expect(data.columns).toBe(2)
		expect(data.fixedColumns).toBe(true)
	})

	it('puts no second Subscribers heading above the card', () => {
		const body = page.config.bodyWidgets.find((w) => w.id === 'list-subscriptions')
		expect(body.title).toBeUndefined()
	})

	it('runs the Subscribers table to the card edges, heads on the card ground', () => {
		expect(style).toMatch(/\.subscriptions-section--card \.subscriptions-section__table \{[^}]*margin-inline: calc\(-1 \* var\(--cn-board-card-inline-padding, 0px\)\)/)
		expect(style).toMatch(/--nldesign-component-table-header-background-color: var\(--color-main-background\)/)
		expect(style).toMatch(/\.subscriptions-section--card \.subscriptions-section__table th:first-child,\s*\.subscriptions-section--card \.subscriptions-section__table td:first-child \{\s*padding-inline-start: 18px;/)
	})

	it('wraps a long cell instead of pushing the last column out of the card', () => {
		expect(style).toMatch(/\.subscriptions-section--card \.subscriptions-section__table td \{[^}]*white-space: normal;/)
	})

	it('draws a head as its bare label and a 22px funnel, not two 34px buttons', () => {
		expect(style).toMatch(/\.subscriptions-section__sort \{[^}]*min-height: 0;[^}]*border-radius: 0;/)
		expect(style).toMatch(/\.subscriptions-section__th \{[^}]*--default-clickable-area: 22px;[^}]*--border-radius-element: 4px;/)
	})
})

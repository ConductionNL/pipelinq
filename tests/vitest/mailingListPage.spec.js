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
	NcButton: { name: 'NcButton', template: '<button v-bind="$attrs"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
	NcNoteCard: { name: 'NcNoteCard', template: '<div><slot /></div>' },
}))
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
		expect(widget('list-side-history').content.limit).toBe(3)
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

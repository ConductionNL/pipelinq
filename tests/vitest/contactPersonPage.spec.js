// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact page as board PqContactpersoon draws it: one strip with every
 * list and panel the person has (History last), Overview as one card of
 * headed sections (details, deals, relationships, channels), and a short side
 * column (open deals, related, the three newest changes).
 *
 * @spec openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-is-the-board
 */

import { mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: { name: 'CnDetailCard', props: ['title'], template: '<section class="card"><slot /></section>' },
}))

const { sectionWidget } = await import('../../src/components/widgets/sectionWidget.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'ContactDetail')
const widget = (id) => page.config.widgets.find((w) => w.id === id)

describe('the contact page as the board', () => {
	it('draws the board strip, History last', () => {
		const tabs = widget('contact-panels').content.tabs
		expect(tabs.map((t) => t.label)).toEqual([
			'Overview', 'Deals', 'Requests', 'Contact moments', 'Bookings', 'Relationships', 'Subscriptions',
			'BSN and BRP', 'Channels', 'Mailbox', 'Messages', 'Appointments', 'Files', 'History',
		])
		for (const tab of tabs) {
			expect(widget(tab.widgetId), tab.label).toBeTruthy()
		}
	})

	it('holds Overview as headed sections: details, deals, relationships, channels', () => {
		const sections = widget('contact-overview').content.sections
		expect(widget('contact-overview').type).toBe('sections')
		expect(sections.map((s) => s.title)).toEqual(['Contact details', 'Deals', 'Relationships', 'Channels'])
		expect(sections[2].widget.content.card).toBe(false)
		expect(sections[3].widget.content.card).toBe(false)
		expect(widget('contact-data').content.include).toHaveLength(widget('contact-data').content.collapsedFields)
	})

	it('keeps the side column short: open deals, related, three changes', () => {
		expect(page.config.sideColumn).toEqual(['contact-kpis-deals', 'contact-related', 'contact-side-history'])
		expect(widget('contact-side-history').content).toMatchObject({ auditTrail: true, order: 'desc', limit: 3 })
	})

	it('draws a section widget bare when its content says card: false', () => {
		const Inner = { template: '<p class="inner">x</p>' }
		const Widget = sectionWidget('W', Inner, () => ({}), { card: true })
		expect(mount(Widget, { props: { objectId: 'c-1', content: { title: 'T' } } }).find('.card').exists()).toBe(true)
		expect(mount(Widget, { props: { objectId: 'c-1', content: { card: false } } }).find('.card').exists()).toBe(false)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqLead: the header names the owner, logs a contact first, and the
 * "what now" card's button makes the quote in the qualified and proposal
 * stages; the Deal card holds eight facts in two columns; the win chance
 * reads as a percentage.
 *
 * @spec openspec/changes/lead-page-as-pqlead/specs/lead-management/spec.md#requirement-the-lead-page-reads-as-the-board
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'LeadDetail').config
const widget = (id) => config.widgets.find((w) => w.id === id)
const action = (id) => config.headerActions.find((a) => a.id === id)

describe('the lead page (board PqLead)', () => {
	it('names the owner by name in the header line', () => {
		expect(config.headerMeta).toContain('{assignee|user}')
	})

	it('logs a contact first, and makes the quote from the what-now card', () => {
		expect(config.quickActions[0]).toBe('log-contact')
		expect(action('log-contact')).toMatchObject({ type: 'open-form', schema: 'ticket', props: { lead: '@objectId', ticketType: 'interaction' } })
		expect(action('make-quote')).toMatchObject({ type: 'open-form', schema: 'salesQuote', props: { lead: '@objectId', status: 'draft' } })
		expect(config.primaryActionByStage).toEqual({ Gekwalificeerd: 'make-quote', Voorstel: 'make-quote' })
	})

	it('draws the eight deal facts in two columns, in the board order', () => {
		const deal = widget('lead-deal').content
		expect(deal.include).toEqual(['value', 'qualificationScore', 'expectedCloseDate', 'priority', 'source', 'category', 'assignee', 'stageEnteredAt'])
		expect(deal.columns).toBe(2)
		expect(deal.fixedColumns).toBe(true)
		expect(deal.include.map((key) => deal.overrides[key].order)).toEqual([1, 2, 3, 4, 5, 6, 7, 8])
	})

	it('reads the win chance as a percentage', () => {
		expect(widget('side-win-chance').content.fields[0]).toMatchObject({ as: 'title', path: 'qualificationScore', suffix: '%' })
	})

	it('puts the header buttons under the title', () => {
		expect(config.headerActionsBelow).toBe(true)
	})

	it('has the board tabs, Quotes before History', () => {
		const tabs = widget('lead-panels').content.tabs.map((tab) => tab.label)
		expect(tabs).toEqual(['Overview', 'Contacts', 'Contact moments', 'Tasks', 'Appointments', 'Mail', 'Notes', 'Files', 'Decisions', 'Quotes', 'History'])
		expect(widget('lead-quotes').content).toMatchObject({ schema: 'salesQuote', filter: { lead: '@objectId' } })
	})

	it('names the lead\'s contact person on the client card', () => {
		expect(widget('side-client').content.fields[1]).toMatchObject({ label: 'Contact', path: 'contact', from: 'object', resolve: { schema: 'contact', labelField: 'name' } })
	})

	it('explains the win chance in one line and draws the products as a plain table', () => {
		expect(widget('side-win-chance').content.fields[1].template).toContain('{stage}')
		expect(widget('lead-lines').content).toMatchObject({ rowTitle: 'text', filterable: true })
	})

	it('starts the Related card with the board\'s three link rows, keeping the related objects', () => {
		const related = widget('side-related')
		expect(related.type).toBe('related')
		expect(related.content.links.map((link) => link.label)).toEqual(['Prospect data from the KVK', '{earlier}', 'Hours on this lead: {hours}'])
		expect(related.content.links[1].endpoint).toBe('/apps/pipelinq/api/leads/@objectId/related-summary')
	})
})

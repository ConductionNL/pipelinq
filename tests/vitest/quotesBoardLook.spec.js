/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The quote a salesperson makes from a lead (PqOfferte): its schemas and
 * its page.
 *
 * @spec openspec/specs/product-catalog-quoting/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return JSON.parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
}
const register = read('lib/Settings/register.d/97-quotes.json')
const pages = read('src/manifest.d/82-quotes.json').pages
const detail = pages.find((p) => p.id === 'QuoteDetail')

describe('quote schemas', () => {
	const { schemas, registers } = register.components

	it('uses slugs no other app owns, and joins the pipelinq register', () => {
		expect(Object.keys(schemas)).toEqual(['salesQuote', 'salesQuoteLine'])
		expect(registers.pipelinq.schemas).toEqual(['salesQuote', 'salesQuoteLine'])
	})

	it('moves a quote from draft through sent to accepted or rejected', () => {
		const lifecycle =
			schemas.salesQuote.configuration['x-openregister-lifecycle']
		expect(lifecycle.initial).toBe('draft')
		expect(lifecycle.transitions.send).toEqual(
			expect.objectContaining({ from: ['draft'], to: 'sent' }),
		)
		expect(lifecycle.transitions.accept.from).toEqual(['sent'])
		expect(lifecycle.transitions.toOrder.from).toEqual(['accepted'])
	})

	it('hangs a line on its quote and optionally on a catalogue product', () => {
		const line = schemas.salesQuoteLine
		expect(line.properties.quote.$ref).toBe('salesQuote')
		expect(line.properties.product.$ref).toBe('product')
		expect(line.required).toEqual(['quote', 'description'])
	})
})

describe('quote page', () => {
	it('edits the quote, lists its lines with their totals, and ends with notes', () => {
		expect(detail.config.layout.map((item) => item.widgetId)).toEqual([
			'quote-facts',
			'quote-lines',
			'quote-notes',
		])
		const lines = detail.config.widgets.find((w) => w.id === 'quote-lines')
		expect(lines.content).toMatchObject({
			schema: 'salesQuoteLine',
			filter: { quote: '@objectId' },
			allowCreate: true,
			allowEdit: true,
		})
	})

	it('sends a draft to the client through the lifecycle', () => {
		const send = detail.config.headerActions.find((a) => a.id === 'send-quote')
		expect(send.payload).toEqual({ action: 'send' })
		expect(detail.config.primaryActionByStage).toEqual({ draft: 'send-quote' })
		expect(detail.config.sideColumn).toEqual([
			'side-status',
			'side-client',
			'side-history',
		])
	})
})

describe('quote page as the PqOfferte board draws it', () => {
	const widget = (id) => detail.config.widgets.find((w) => w.id === id)

	it('names the quote by its number and title', () => {
		expect(detail.config.titleTemplate).toBe('{number} {title}')
	})

	it('makes Send to client the primary button of a draft, beside no Edit or Follow', () => {
		expect(detail.config.quickActions || []).not.toContain('send-quote')
		expect(detail.config.showEditAction).toBe(false)
		expect(detail.config.follow).toBe(false)
	})

	it('ends the lines with the catalogue note the list widget reads', () => {
		const lines = widget('quote-lines').content
		expect(lines.footnote).toBe('Products come from the catalogue at their current price. A line of your own is for work the catalogue does not hold.')
		expect(lines).not.toHaveProperty('footerNote')
	})

	it('lists what changed in the history, not who read the quote', () => {
		expect(widget('side-history').content).toMatchObject({
			excludeActions: ['read'],
			layout: 'stacked',
		})
	})
})

describe('quote page parts as the PqOfferte board draws them', () => {
	const widget = (id) => detail.config.widgets.find((w) => w.id === id)
	const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

	it('puts the status pill first, then the version pill', () => {
		expect(detail.config.typePill).toMatchObject({ field: 'status' })
		expect(detail.config.statusPill).toEqual({ text: 'Version {version}', variant: 'primary' })
		expect(nl['Version {version}']).toBe('Versie {version}')
	})

	it('says a draft is not sent yet, and when a sent quote went out', () => {
		expect(detail.config.headerMeta).toBe('prices excl. VAT')
		expect(detail.config.headerMetaWhen).toEqual([
			{ when: { field: 'status', value: 'draft' }, text: 'not sent yet · prices excl. VAT' },
			{ when: { field: 'sentAt', op: 'notEmpty' }, text: 'sent {sentAt|date} · prices excl. VAT' },
		])
		expect(nl['not sent yet · prices excl. VAT']).toBe('nog niet verstuurd · prijzen excl. btw')
	})

	it('lists the stages in the Status card, the current one marked', () => {
		const status = widget('side-status')
		expect(status.type).toBe('sections')
		const stages = detail.config.widgets.find((w) => w.id === status.content.sections[0].widgetId)
		expect(stages.type).toBe('stages')
		expect(stages.content).toMatchObject({ currentField: 'status', variant: 'list', transition: false })
		expect(stages.content.stages.map((s) => s.id)).toEqual(['draft', 'sent', 'accepted', 'rejected', 'expired'])
		expect(stages.content.stages[4].hint).toBe('after 30 days')
		expect(nl['after 30 days']).toBe('na 30 dagen')
		expect(nl['you are here']).toBeUndefined()
	})

	it('offers converting to an order once accepted, below the stages', () => {
		const order = detail.config.widgets.find((w) => w.id === widget('side-status').content.sections[1].widgetId)
		expect(order.content.actions[0]).toMatchObject({ type: 'api-call', payload: { action: 'toOrder' }, visibleWhen: { field: 'status', value: 'accepted' } })
		expect(order.content.footnote).toBe('Convert to order is available once the client accepts the quote.')
	})

	it('runs the lines edge to edge with a second add and the totals under them', () => {
		const lines = widget('quote-lines').content
		expect(lines).toMatchObject({ tableBleed: true, rowTitle: 'strong', createDefaults: { kind: 'catalogue' } })
		expect(lines.addActions).toEqual([{ label: 'Add own line', icon: 'Pencil', createDefaults: { kind: 'own' } }])
		expect(lines.totals.map((t) => t.path)).toEqual(['subtotal', 'vatAmount', 'total'])
		expect(lines.totals[2].emphasis).toBe(true)
		expect(detail.config.layout.map((item) => item.widgetId)).not.toContain('quote-totals')
	})
})

describe('the quote PDF', () => {
	const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations

	it('offers "Voorbeeld pdf" beside the header actions while a template can make it', () => {
		const action = detail.config.headerActions.find((a) => a.id === 'quote-pdf')
		expect(action).toMatchObject({
			type: 'navigate',
			label: 'Preview PDF',
			target: '/index.php/apps/pipelinq/api/records/salesQuote/@objectId/document',
			visibleWhen: { endpoint: '/apps/pipelinq/api/records/salesQuote/document-template', field: 'available', op: 'eq', value: true },
		})
		expect(detail.config.quickActions).toContain('quote-pdf')
		expect(nl['Preview PDF']).toBe('Voorbeeld pdf')
	})

	it('routes the document endpoints', () => {
		const routes = readFileSync(resolve(__dirname, '../../appinfo/routes.php'), 'utf8')
		expect(routes).toContain("'url' => '/api/records/{schema}/{id}/document'")
		expect(routes).toContain("'url' => '/api/records/{schema}/document-template'")
	})
})

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The party warnings are reachable, and the panel reads and writes the real
 * shapes (pipelinq#2036).
 *
 * The indicator model, its resolver and its acknowledgement route all shipped
 * with pipelinq#1973, and no page rendered any of it: a colleague could not
 * record an aggression incident and the next colleague was never warned. The
 * wiring tests below read the manifest and the registry the app boots from,
 * so removing the panel from either page fails here.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const get = vi.fn()
const post = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: {
		get: (...args) => get(...args),
		post: (...args) => post(...args),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) =>
		url.replace(/\{(\w+)\}/g, (_, key) => params[key]),
}))

const {
	acknowledgeIndicator,
	addIndicatorValue,
	buildIndicatorValue,
	fetchPartyIndicators,
	needsAcknowledgement,
	sortBySeverity,
} = await import('../../src/services/partyIndicators.js')

const root = resolve(__dirname, '../..')
const manifest = JSON.parse(readFileSync(resolve(root, 'src/manifest.json'), 'utf8'))
const registry = readFileSync(resolve(root, 'src/registry.js'), 'utf8')

describe('party warnings are mounted', () => {
	it('ClientDetail renders them in the contact strip, first in the band across the page after the lock notice', () => {
		const page = manifest.pages.find((p) => p.id === 'ClientDetail')
		const widget = page.config.widgets.find((w) => w.type === 'PartyContactStripWidget')
		expect(page.config.band[0].widgetId).toBe('client-locked')
		expect(page.config.band[1].widgetId).toBe(widget.id)
		expect(registry).toContain("'PartyContactStripWidget',\n\t\t\tPartyContactStrip,")
		expect(readFileSync(resolve(root, 'src/components/PartyContactStrip.vue'), 'utf8')).toContain('<PartyIndicatorPanel')
	})

	it.each(['ContactDetail'])(
		'%s renders the panel before the body',
		(pageId) => {
			const page = manifest.pages.find((p) => p.id === pageId)
			expect(page, `${pageId} exists`).toBeTruthy()
			const widget = (page.config.bodyWidgets || []).find(
				(w) => w.component === 'PartyIndicatorPanel',
			)
			expect(widget, `${pageId} mounts PartyIndicatorPanel`).toBeTruthy()
			expect(widget.placement).toBe('before-body')
			// Board PqContactpersoon: one framed line with the shield, "No
			// warnings on this contact person." and the Add a warning link.
			expect(widget.props).toEqual({ partyId: '@objectId', compact: true, framed: true, partyKind: 'contact' })
		},
	)

	it('names a contact person in the none line and frames the strip on its own', () => {
		const panel = readFileSync(resolve(root, 'src/components/PartyIndicatorPanel.vue'), 'utf8')
		expect(panel).toContain("t('pipelinq', 'No warnings on this contact person.')")
		expect(panel).toMatch(/'party-indicators--framed': compact && framed/)
		const nl = JSON.parse(readFileSync(resolve(root, 'l10n/nl.json'), 'utf8')).translations
		expect(nl['No warnings on this contact person.']).toBe('Geen waarschuwingen bij deze contactpersoon.')
		expect(nl.Call).toBe('Bellen')
	})

	it('registers the panel as a section component', () => {
		expect(registry).toMatch(
			/PartyIndicatorPanel: \{\s*kind: 'section',\s*component: PartyIndicatorPanel,/,
		)
	})
})

describe('party warnings service', () => {
	beforeEach(() => {
		get.mockReset()
		post.mockReset()
	})

	it('orders critical before warning before info', () => {
		const sorted = sortBySeverity([
			{ label: 'b', severity: 'info' },
			{ label: 'a', severity: 'warning' },
			{ label: 'z', severity: 'critical' },
		])
		expect(sorted.map((i) => i.severity)).toEqual([
			'critical',
			'warning',
			'info',
		])
	})

	it('asks for an acknowledgement only while none is recorded', () => {
		expect(needsAcknowledgement({ requiresAcknowledgement: true })).toBe(true)
		expect(
			needsAcknowledgement({
				requiresAcknowledgement: true,
				acknowledgedAt: '2026-09-27T10:00:00Z',
			}),
		).toBe(false)
		expect(needsAcknowledgement({ requiresAcknowledgement: false })).toBe(false)
	})

	it('reads the indicators through the party leaf', async () => {
		get.mockResolvedValue({
			data: {
				indicators: [
					{ id: 'v1', code: 'agressie-registratie', severity: 'critical' },
				],
			},
		})
		const indicators = await fetchPartyIndicators('client-1')
		expect(get).toHaveBeenCalledWith('/apps/pipelinq/api/leaves/party/client-1')
		expect(indicators[0].code).toBe('agressie-registratie')
	})

	it('acknowledges through the leaf route', async () => {
		post.mockResolvedValue({
			data: { indicatorValue: { acknowledgedBy: 'admin' } },
		})
		await acknowledgeIndicator('v1')
		expect(post).toHaveBeenCalledWith(
			'/apps/pipelinq/api/party-indicator-values/v1/acknowledge',
		)
	})

	it('stores a new warning in the shape the schema requires', async () => {
		post.mockResolvedValue({ data: {} })
		await addIndicatorValue('client-1', {
			indicator: 'agressie-registratie',
			validFrom: '2026-09-27',
			validUntil: '',
			source: 'KCC supervisor',
		})
		expect(post).toHaveBeenCalledWith(
			'/apps/openregister/api/objects/pipelinq/partyIndicatorValue',
			{
				party: 'client-1',
				indicator: 'agressie-registratie',
				validFrom: '2026-09-27',
				source: 'KCC supervisor',
			},
		)
		// party, indicator and validFrom are the schema's required fields.
		const value = buildIndicatorValue('c', { indicator: 'x' })
		expect(Object.keys(value)).toEqual(['party', 'indicator', 'validFrom'])
	})
})

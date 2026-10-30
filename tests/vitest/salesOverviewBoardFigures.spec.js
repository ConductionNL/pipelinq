/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The sales overview's figures as board PqVerkoopoverzicht draws them: a
 * meta line under each KPI, amounts as toned pills, won against open as two
 * figures, the pipeline cover as a multiple, matching prospects, and the
 * stage, source and customer charts as labelled bars.
 *
 * @spec openspec/specs/dashboard/spec.md#requirement-the-sales-overview-says-what-its-figures-are-made-of
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const nl = JSON.parse(readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8')).translations
const dashboard = manifest.pages.find((p) => p.id === 'Dashboard')
const widget = (id) => dashboard.config.widgets.find((w) => w.id === id)
const placed = (id) => dashboard.config.layout.find((item) => item.widgetId === id)

describe('sales overview figures (PqVerkoopoverzicht)', () => {
	it('gives every KPI tile a meta line built from the overview', () => {
		expect(widget('revenue').content.caption).toBe('till and won deals')
		expect(widget('won-value').content.caption).toBe('{wonCount} won')
		expect(widget('win-rate').content.caption).toBe('{wonCount} won, {lostCount} lost')
		expect(widget('avg-deal-size').content.caption).toBe('{wonCount} won')
		expect(widget('weighted-forecast').content.caption).toBe('of {openPipelineValue|currency} open, {scoredOpenCount} of {openCount} leads scored')
		expect(widget('mrr').content.caption).toBe('per month, from running contracts')
	})

	it('keeps the meta line of a deal when its owner or stage is empty', () => {
		expect(widget('closing-soon').content.meta).toEqual({ parts: ['{stage}', '{expectedCloseDate|date}', '{assignee|user}'] })
		expect(widget('recently-won-lost').content.meta).toEqual({ parts: ['{status} {expectedCloseDate|date}', '{assignee|user}'] })
	})

	it('draws a won or lost deal\'s amount as a green or red pill', () => {
		const content = widget('recently-won-lost').content
		expect(content.pill.cases).toEqual([
			{ when: { field: 'status', op: 'eq', value: 'won' }, label: '{value|currency}', variant: 'success' },
			{ when: { field: 'status', op: 'eq', value: 'lost' }, label: '{value|currency}', variant: 'error' },
		])
		expect(content.trailing).toBeUndefined()
	})

	it('draws won against open as two figures and the share', () => {
		const card = widget('won-vs-open')
		expect(card.type).toBe('stat-grid')
		expect(card.title).toBe('Won against open')
		expect(card.content.endpointSource.url).toBe('/apps/pipelinq/api/analytics/commercial')
		expect(card.content.cells.map((cell) => [cell.label, cell.valueField])).toEqual([['Won', 'wonValue'], ['Open', 'openPipelineValue']])
		expect(card.content.cells[0].caption).toBe('Won is {wonShareOfOpen}% of open.')
	})

	it('draws the pipeline cover as a multiple of what is still to come in', () => {
		const card = widget('pipeline-coverage')
		expect(card.type).toBe('stat')
		expect(card.title).toBe('Pipeline cover')
		expect(card.content.valueField).toBe('pipelineCover')
		expect(card.content.format).toMatchObject({ style: 'decimal', decimals: 1, suffix: ' x' })
		expect(card.content.caption).toBe('Open pipeline against the target still to come in. Healthy from 3 x.')
		expect(card.content.emptyText).toBe('Set a pipeline target')
	})

	it('lists the two best matching prospects with their fit', () => {
		const card = widget('matching-prospects')
		expect(card.type).toBe('record-list')
		expect(card.content.endpointSource).toMatchObject({ url: '/apps/pipelinq/api/prospects', responsePath: 'prospects' })
		expect(card.content.limit).toBe(2)
		expect(card.content.headerNote).toBe('From the KVK, scored on your profile')
		expect(card.content.footerLink).toMatchObject({ label: 'Find more prospects', route: 'Prospects' })
		expect(card.content.pill.cases[0]).toMatchObject({ label: 'Fit {fitScore}', variant: 'success' })
		expect(placed('matching-prospects')).toBeTruthy()
	})

	it('draws the stage, source and customer charts as labelled bars with short figures', () => {
		for (const id of ['pipeline-by-stage', 'revenue-by-category', 'top-customers']) {
			expect(widget(id).content.chartKind).toBe('bar-list')
			expect(widget(id).content.valueFormat).toBe('compact')
		}
	})

	it('places no two cards on the same grid cell', () => {
		const taken = new Set()
		for (const item of dashboard.config.layout) {
			for (let x = item.gridX; x < item.gridX + item.gridWidth; x++) {
				for (let y = item.gridY; y < item.gridY + item.gridHeight; y++) {
					const cell = `${x},${y}`
					expect(taken.has(cell), `${item.widgetId} overlaps at ${cell}`).toBe(false)
					taken.add(cell)
				}
			}
		}
	})

	it('has Dutch for every new string', () => {
		const strings = [
			'till and won deals', '{wonCount} won', '{wonCount} won, {lostCount} lost',
			'of {openPipelineValue|currency} open, {scoredOpenCount} of {openCount} leads scored',
			'per month, from running contracts', 'Won is {wonShareOfOpen}% of open.',
			'Open pipeline against the target still to come in. Healthy from 3 x.', 'Set a pipeline target',
			'Matching prospects', 'From the KVK, scored on your profile', 'Find more prospects', 'Fit {fitScore}',
			'{employeeCount} employees',
		]
		for (const key of strings) {
			expect(nl[key], key).toBeTruthy()
		}
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqPipelines: the pipelines list opens as cards, one per pipeline,
 * with its description, its number of stages and its stages as chips.
 *
 * @spec openspec/changes/pipelines-as-cards/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'Pipelines')

describe('the pipelines list', () => {
	it('opens as rows that open to their stages, without a view switch or search', () => {
		expect(page.config.viewMode).toBe('list')
		expect(page.config.showViewToggle).toBe(false)
		expect(page.config.search).toBe(false)
		const rows = page.config.expandableRows
		expect(rows.open).toBe('first')
		expect(rows.badge.label).toBe('Default')
		expect(rows.table.columns.map((c) => c.as || 'text')).toEqual(['order', 'text', 'bar', 'check', 'yesNo', 'pill'])
		expect(rows.chips.tones.map((t) => t.variant)).toEqual(['success', 'error', 'primary'])
		expect(rows.buttons).toEqual([{ action: 'edit', label: 'Edit' }])
	})

	it('draws each pipeline with its stage count and its stages as chips', () => {
		expect(page.config.cardLayout).toMatchObject({
			title: 'title',
			description: 'description',
			meta: [{ template: '{stages|count} stages' }, { template: 'created {@self.created|date}' }],
			chips: [{ field: 'stages', each: 'name' }],
		})
	})

	it('colours a stage chip as the board does: won green, closed red, the first stage blue', () => {
		const tones = page.config.cardLayout.chips[0].tones
		expect(tones.map((t) => [t.when.field, t.variant])).toEqual([
			['isWon', 'success'],
			['isClosed', 'error'],
			['isDefault', 'primary'],
		])
	})

	it('ends every stage with the board pencil, named for the stage', () => {
		expect(page.config.expandableRows.table.entryAction).toEqual({ id: 'edit', label: 'Edit stage', icon: 'Pencil' })
	})
})

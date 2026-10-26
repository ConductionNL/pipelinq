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
	it('opens as cards, with the table one switch away', () => {
		expect(page.config.viewMode).toBe('cards')
		expect(page.config.viewModes).toEqual(['cards', 'table'])
	})

	it('draws each pipeline with its stage count and its stages as chips', () => {
		expect(page.config.cardLayout).toMatchObject({
			title: 'title',
			description: 'description',
			meta: [{ template: '{stages|count} stages' }, { template: 'created {@self.created|date}' }],
			chips: [{ field: 'stages', each: 'name' }],
		})
	})
})

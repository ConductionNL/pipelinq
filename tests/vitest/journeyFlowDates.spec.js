// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The journey's Flow card names the day the journey became a flow, as the
 * PqJourney board writes it ("Op 2 okt omgezet naar een flow").
 *
 * @spec openspec/specs/marketing-integrated-campaigns/spec.md
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/dist/esm/utils/visibleWhen.js'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))

describe('journey flow dates', () => {
	const manifest = read('src/manifest.d/79-marketing-journeys.json')
	const widget = manifest.pages
		.flatMap((p) => p.config?.widgets || [])
		.find((w) => w.id === 'journey-flow')
	const [dated, plain] = widget.content.fields

	it('writes the conversion date once the journey is a flow', () => {
		expect(dated.template).toContain('{compiledAt:date}')
		expect(evaluateVisibleWhenLocal(dated.when, { flowStatus: 'compiled', compiledAt: '2026-10-02T09:00:00Z' })).toBe(true)
		expect(evaluateVisibleWhenLocal(plain.when, { flowStatus: 'compiled', compiledAt: '2026-10-02T09:00:00Z' })).toBe(false)
	})

	it('keeps the plain status line without a date or a flow', () => {
		expect(evaluateVisibleWhenLocal(dated.when, { flowStatus: 'compiled' })).toBe(false)
		expect(evaluateVisibleWhenLocal(plain.when, { flowStatus: 'compiled' })).toBe(true)
		expect(evaluateVisibleWhenLocal(plain.when, { flowStatus: 'refused', compiledAt: '2026-10-02' })).toBe(true)
	})

	it('declares both dates on the journey', () => {
		const schema = read('lib/Settings/register.d/99-zz-journey-flow-dates.json').components.schemas.journey
		expect(schema.version).toBe('1.1.0')
		expect(schema.properties.compiledAt.format).toBe('date-time')
		expect(schema.properties.activatedAt.format).toBe('date-time')
	})
})

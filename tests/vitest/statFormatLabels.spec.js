/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A stat tile's value suffix goes through the app's translate. The library
 * appends `format.suffix` as written, so My work's average answer time read
 * "0,0 days" for a Dutch user (Zuiddrecht live test, 14 Oct).
 *
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { translateStatFormatLabels } from '../../src/utils/statFormatLabels.js'

const nl = { ' days': ' dagen', ' hours': ' uur' }
const translate = (text) => nl[text] ?? text

describe('translateStatFormatLabels', () => {
	it('translates the suffix of a stat and of a stat inside a stats-block grid', () => {
		const manifest = {
			pages: [
				{
					type: 'dashboard',
					config: {
						widgets: [
							{
								id: 'a',
								type: 'stat',
								content: {
									format: { style: 'decimal', suffix: ' days' },
								},
							},
							{
								id: 'b',
								type: 'stats-block',
								content: {
									layout: 'grid',
									stats: [{ format: { suffix: ' hours' } }],
								},
							},
						],
					},
				},
			],
		}
		translateStatFormatLabels(manifest, translate)
		expect(manifest.pages[0].config.widgets[0].content.format.suffix).toBe(
			' dagen',
		)
		expect(
			manifest.pages[0].config.widgets[1].content.stats[0].format.suffix,
		).toBe(' uur')
	})

	it('leaves a config token, and pages that are not dashboards, alone', () => {
		const manifest = {
			pages: [
				{
					type: 'dashboard',
					config: {
						widgets: [
							{
								content: {
									format: {
										prefix: '@config.currency',
										suffix: ' days',
									},
								},
							},
						],
					},
				},
				{
					type: 'index',
					config: {
						widgets: [{ content: { format: { suffix: ' days' } } }],
					},
				},
			],
		}
		translateStatFormatLabels(manifest, translate)
		expect(manifest.pages[0].config.widgets[0].content.format.prefix).toBe(
			'@config.currency',
		)
		expect(manifest.pages[1].config.widgets[0].content.format.suffix).toBe(
			' days',
		)
	})

	it('is wired into the app entry, and My work has a suffix the catalogue translates', () => {
		const main = readFileSync(resolve(__dirname, '../../src/main.js'), 'utf8')
		expect(main).toMatch(/translateStatFormatLabels\(mergedManifest/)
		const catalogue = JSON.parse(
			readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
		).translations
		expect(catalogue[' days']).toBe(' dagen')
	})
})

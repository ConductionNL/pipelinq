/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * PqProduct: the figures and the tab strip are as tall as their content.
 * pl6 on :8080 held the tab strip at 18 grid rows, so the Overview was cut
 * off under "Gebruikt op deals" and "Images & spec sheets" never showed, and
 * the figures at two 80px rows stood 32px taller than drawn.
 *
 * @spec openspec/changes/product-panels-fit-content/specs/product-detail/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const config = manifest.pages.find((page) => page.id === 'ProductDetail').config

describe('ProductDetail grid heights', () => {
	it.each(['product-panels', 'product-kpi-deals', 'product-kpi-value', 'product-kpi-counter'])('sizes %s to its content', (widgetId) => {
		expect(config.layout.find((item) => item.widgetId === widgetId).sizeToContent).toBe(true)
	})

	it('packs the grid, so a fitted cell ends at its content', () => {
		expect(config.gridFloat).toBe(false)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * 'Requests by category' names each category code by the ticketCategory
 * vocabulary (pl2 PqOperationeel drew raw slugs such as afval). Needs nc-vue
 * groupBy.reference.keyField (24t2l). Read from the manifest.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')
const chart = page.config.widgets.find((w) => w.id === 'requests-by-category').content

describe('requests by category', () => {
	it('names category codes by the ticketCategory vocabulary', () => {
		expect(chart.dataSource.groupBy.reference).toEqual({ schema: 'ticketCategory', keyField: 'key', labelField: 'name' })
		expect(chart.dataSource.groupBy.limit).toBe(3)
		expect(chart.dataSource.groupBy.otherBucket).toBe(true)
	})
})

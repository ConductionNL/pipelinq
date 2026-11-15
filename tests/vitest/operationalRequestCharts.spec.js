/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The operational dashboard's request charts as the board draws them (pl2
 * PqOperationeel): requests by status as a bar list of named statuses, and
 * requests by category as the three largest plus "Other". Needs nc-vue's
 * groupBy labels/otherBucket (24t2l). Read from the manifest.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')
const widget = (id) => page.config.widgets.find((w) => w.id === id).content

describe('the operational dashboard request charts', () => {
	it('draws requests by status as a bar list of named statuses', () => {
		const status = widget('requests-by-status')
		expect(status.chartKind).toBe('bar-list')
		expect(status.dataSource.groupBy.labels).toMatchObject({ new: 'New', in_progress: 'In progress', awaiting_customer: 'Waiting for customer' })
	})

	it('draws requests by category as the three largest and Other', () => {
		expect(widget('requests-by-category').dataSource.groupBy).toMatchObject({ limit: 3, otherBucket: true })
	})
})

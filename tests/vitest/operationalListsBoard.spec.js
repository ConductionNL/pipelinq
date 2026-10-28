/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The operational dashboard's lists as the board draws them (pl2
 * PqOperationeel): the complaints table with sort and filter in its column
 * heads, and the client overview's "View all clients" under the table, not
 * in the card header. Read from the manifest.
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const manifest = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8'))
const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')
const widget = (id) => page.config.widgets.find((w) => w.id === id)

describe('the operational dashboard lists', () => {
	it('gives the complaints table sortable, filterable column heads', () => {
		const content = widget('complaints').content
		expect(content.sortable).toBe(true)
		expect(content.filterable).toBe(true)
	})

	it('draws the client overview view-all link under the table', () => {
		const content = widget('client-overview').content
		expect(content.viewAllPlacement).toBe('footer')
		expect(content.viewAllLabel).toBe('View all clients')
		expect(widget('client-overview').headerLink).toBeUndefined()
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The list boards' page sizes and row shape: PqCampagnes and PqContacten
 * show 5 rows a page, PqAfspraken and PqOrganisaties 8, PqSjablonen lists
 * its templates by name, and PqKassadiensten draws a row actions button on
 * every shift.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import { describe, expect, it } from 'vitest'
import manifest from '../../src/manifest.json'
import simple from '../../src/menu-layout.simple.json'
import campaigns from '../../src/manifest.d/78-marketing-campaigns.json'
import bookings from '../../src/manifest.d/80-appointment-booking-admin.json'
import blasts from '../../src/manifest.d/75-marketing-blasts.json'

const page = (source, id) => source.pages.find((p) => p.id === id)
const simpleConfig = (id) => (page(simple, id) || {}).config || {}

describe('list page sizes', () => {
	it.each([
		['Campaigns', campaigns, 5],
		['Contacts', manifest, 5],
		['Bookings', bookings, 8],
		['Clients', manifest, 8],
	])('%s shows the board page size', (id, source, limit) => {
		expect(page(source, id).config.pagination).toEqual({ limit })
		expect(simpleConfig(id).pagination).toBeUndefined()
	})

	it('lists templates by name', () => {
		const config = page(blasts, 'Templates').config
		expect([config.sortKey, config.sortOrder]).toEqual(['name', 'asc'])
	})

	it('gives every cash shift a row actions button that opens it', () => {
		const config = page(manifest, 'CashShifts').config
		expect(config.actions).toEqual([{ id: 'view', label: 'View', icon: 'EyeOutline', handler: 'navigate', route: 'CashShiftDetail' }])
		expect(simpleConfig('CashShifts').actions).toBeUndefined()
	})
})

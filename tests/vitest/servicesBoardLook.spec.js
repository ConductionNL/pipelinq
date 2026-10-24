/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The service list and service page as the PqServices and PqService boards
 * draw them.
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const fragment = JSON.parse(
	readFileSync(
		resolve(__dirname, '../../src/manifest.d/80-appointment-booking-admin.json'),
		'utf8',
	),
)
const source = readFileSync(
	resolve(__dirname, '../../src/views/bookings/ServiceDetail.vue'),
	'utf8',
)

describe('service list', () => {
	const list = fragment.pages.find((p) => p.id === 'Services')

	it('filters on online booking and status, with counts', () => {
		expect(list.config.quickFilters.map((f) => f.label)).toEqual([
			'All',
			'Online to book',
			'Draft',
			'Archived',
		])
		expect(list.config.quickFilters.every((f) => f.showCount)).toBe(true)
	})

	it('keeps New service as the primary header button', () => {
		const button = list.config.headerButtons.find((b) => b.action === 'create')
		expect(button.variant).toBe('primary')
		expect(list.config.headerActions[0].id).toBe('create')
	})
})

describe('service page', () => {
	it('draws the status pill, breadcrumb and the two side cards', () => {
		expect(source).toMatch(/:statusPill="boardHeader\.statusPill"/)
		expect(source).toMatch(/:sideColumn="sideColumn"/)
		expect(source).toMatch(/id: 'service-bookings'/)
		expect(source).toMatch(/id: 'service-resources'/)
	})

	it('keeps Delete, now in the More menu', () => {
		expect(source).toMatch(/id: 'delete-service'/)
		expect(source).toMatch(/<DeleteServiceDialog/)
	})
})

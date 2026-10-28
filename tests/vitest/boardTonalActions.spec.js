/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The boards draw these card actions as the light-blue tonal pill, and the
 * library's `cn-button--tonal` draws it (nextcloud-vue board-tonal-button).
 * Each action carries the class; the booking side cards' own headings carry
 * `cn-side-heading`, so they read as every side card title. Read from the
 * sources, since the look itself is the library's stylesheet.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/client-management/spec.md
 */
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')

/**
 * The opening tag of the element whose content holds the label.
 *
 * @param {string} source A component source.
 * @param {string} label The translated label's source string.
 * @return {string} The nearest opening NcButton or NcActions tag before it.
 */
function tagBefore(source, label) {
	const at = source.indexOf(`t('pipelinq', '${label}')`)
	expect(at, label).toBeGreaterThan(-1)
	const before = source.slice(0, at)
	const start = Math.max(before.lastIndexOf('<NcButton'), before.lastIndexOf('<NcActions'))
	return source.slice(start, source.indexOf('>', start + 1) + 1)
}

const ACTIONS = [
	['src/components/bookings/BookingDetailSection.vue', 'Send reminder'],
	['src/views/settings/PosTenderTypeManager.vue', 'New tender type'],
	['src/views/settings/PosRoleManager.vue', 'New role'],
	['src/views/settings/PosStaffManager.vue', 'New staff member'],
	['src/views/settings/DeliverabilitySettings.vue', 'Refresh'],
	['src/components/marketing/CampaignLandingPageSection.vue', 'Open campaign report'],
	['src/components/ContactChannelsSection.vue', 'Add channel'],
	['src/components/ContactsSyncSection.vue', 'Update in Contacts'],
	['src/views/forecast/ForecastDashboard.vue', 'Export CSV'],
]

describe('the board tonal card actions', () => {
	for (const [file, label] of ACTIONS) {
		it(`draws "${label}" as the tonal pill`, () => {
			expect(tagBefore(read(file), label)).toContain('cn-button--tonal')
		})
	}

	it('plans a callback from the contact moment as the tonal pill', () => {
		const layout = JSON.parse(read('src', 'menu-layout.simple.json'))
		const found = []
		JSON.stringify(layout, (key, value) => {
			if (value && value.footerAction && value.footerAction.label === 'Plan a callback') {
				found.push(value.footerAction)
			}
			return value
		})
		expect(found.length).toBeGreaterThan(0)
		for (const action of found) {
			expect(action.variant).toBe('tonal')
		}
	})

	it('titles the booking side cards with the side heading', () => {
		const source = read('src/components/bookings/BookingDetailSection.vue')
		const titles = [...source.matchAll(/<h2 class="([^"]*)"/g)].map((m) => m[1])
		expect(titles.length).toBeGreaterThan(1)
		for (const classes of titles) {
			expect(classes).toContain('cn-side-heading')
		}
	})
})

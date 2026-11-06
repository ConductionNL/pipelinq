/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The detail pages that put their tab strip in a fixed-height grid cell
 * (PqContactpersoon, PqLead, PqContract, PqAfspraak, PqTicket, PqProject and
 * the POS, task and social pages): every cell is as tall as its content, so
 * a long tab is not cut off and a short one leaves no stretched card or gap.
 * pl6 drew PqContactpersoon's and PqLead's Overview as a card running far
 * past its last field. `gridFloat: false` gives the grid 1px rows, so a
 * fitted cell ends at its content and not on the next 80px row.
 *
 * @spec openspec/changes/detail-tabs-fit-content/specs/detail-pages/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const PAGES = {
	'src/manifest.json': ['TicketDetail', 'ContactDetail', 'LeadDetail', 'TaskDetail', 'CashShiftDetail', 'ZReportDetail', 'PosTransactionDetail'],
	'src/manifest.d/78-social-publishing.json': ['SocialPostDetail'],
	'src/manifest.d/80-appointment-booking-admin.json': ['BookingDetail'],
	'src/manifest.d/83-programmes.json': ['ProgrammeDetail'],
	'src/manifest.d/96-contracts.json': ['ContractDetail'],
}
const cases = Object.entries(PAGES).flatMap(([file, ids]) => ids.map((id) => [id, read(file).pages.find((page) => page.id === id).config]))

describe('detail page grid cells fit their content', () => {
	it.each(cases)('%s sizes every grid cell to its content on 1px rows', (_id, config) => {
		expect(config.gridFloat).toBe(false)
		expect(config.layout.length).toBeGreaterThan(0)
		config.layout.forEach((item) => expect(item.sizeToContent).toBe(true))
	})
})

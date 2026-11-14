// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An open lead past its expected close date is marked on its row. The row
 * class was computed but never handed to the list, so no row was ever marked.
 *
 * @spec openspec/specs/lead-management/spec.md
 */

import fs from 'fs'
import path from 'path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnIndexPage: {}, openRowTarget: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({}) }))

let list

beforeAll(async () => {
	globalThis.t = (app, text) => text
	list = (await import('../../src/views/leads/LeadList.vue')).default
})

describe('LeadList overdue row', () => {
	it('hands its row class to the index page', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/leads/LeadList.vue'), 'utf8')
		expect(source).toMatch(/:rowClass="rowClassFor"/)
	})

	it('marks an open lead past its close date, and not a closed one', () => {
		const ctx = { stages: [{ name: 'Won', isClosed: true }] }
		const rowClassFor = list.methods.rowClassFor.bind(ctx)
		expect(rowClassFor({ status: 'open', stage: 'New', expectedCloseDate: '2020-01-01' })).toBe('lead-overdue')
		expect(rowClassFor({ status: 'won', stage: 'Won', expectedCloseDate: '2020-01-01' })).toBe('')
		expect(rowClassFor({ status: 'open', stage: 'New', expectedCloseDate: '2999-01-01' })).toBe('')
	})
})

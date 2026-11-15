/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Bulk changes page: which jobs it lists, what a row says, when Undo is
 * offered (task 3.3: not past the window, which still shows its date), and
 * what the undo preview counts.
 *
 * @spec openspec/changes/clients-bulk-edit-and-undo/specs/client-bulk-changes/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { changedCount, changedProperties, clientJobs, undoState } from '../../src/utils/bulkJobs.js'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))

const NOW = new Date('2026-11-14T10:00:00Z')

function job (extra = {}) {
  return {
	id: 1,
	registerId: 3,
	schemaId: 11,
	state: 'completed',
	created: '2026-11-13T09:00:00+00:00',
	parameters: { properties: { industry: 'Energy' } },
	total: 3,
	counts: { applied: 3, skipped: 0, refused: 0, failed: 0 },
	reversible: true,
	reversibleUntil: '2026-11-20T09:00:00+00:00',
	reversesJobId: null,
	reversedByJobId: null,
	...extra,
}
}

describe('clientJobs', () => {
	it('keeps client and contact jobs of the pipelinq register, newest first, without reversal jobs', () => {
		const jobs = [
			job({ id: 1, created: '2026-11-10T09:00:00+00:00' }),
			job({ id: 2, schemaId: 12, created: '2026-11-12T09:00:00+00:00' }),
			job({ id: 3, schemaId: 99 }),
			job({ id: 4, registerId: 8 }),
			job({ id: 5, reversesJobId: 1, created: '2026-11-13T12:00:00+00:00' }),
		]

		expect(clientJobs(jobs, { register: '3', schemas: ['11', 12, ''] }).map((j) => j.id)).toEqual([2, 1])
	})

	it('answers nothing for a missing list or unset schemas', () => {
		expect(clientJobs(undefined, { register: 3, schemas: [11] })).toEqual([])
		expect(clientJobs([job()], { register: 3, schemas: [undefined, ''] })).toEqual([])
	})
})

describe('a row', () => {
	it('names the changed fields and counts applied records once the job ran', () => {
		expect(changedProperties(job({ parameters: { properties: { industry: 'x', size: 'y' } } }))).toEqual(['industry', 'size'])
		expect(changedProperties(job({ parameters: {} }))).toEqual([])
		expect(changedCount(job({ counts: { applied: 2 } }))).toBe(2)
		expect(changedCount(job({ state: 'running', total: 40, counts: { applied: 5 } }))).toBe(40)
	})
})

describe('undoState', () => {
	it('offers Undo for a completed reversible job inside its window', () => {
		expect(undoState(job(), NOW)).toEqual({ canUndo: true, until: '2026-11-20T09:00:00+00:00', undone: false })
	})

	it('past the window shows the date and no Undo', () => {
		expect(undoState(job({ reversibleUntil: '2026-11-07T09:00:00+00:00' }), NOW)).toEqual({ canUndo: false, until: '2026-11-07T09:00:00+00:00', undone: false })
	})

	it('offers nothing for an undone, unfinished or irreversible job', () => {
		expect(undoState(job({ reversedByJobId: 7 }), NOW)).toMatchObject({ canUndo: false, undone: true })
		expect(undoState(job({ state: 'running' }), NOW).canUndo).toBe(false)
		expect(undoState(job({ reversible: false, reversibleUntil: null }), NOW)).toEqual({ canUndo: false, until: null, undone: false })
	})
})

describe('the undo preview', () => {
	it('counts the records that get their old value back and those skipped', async () => {
		const dialog = (await import('../../src/dialogs/BulkUndoDialog.vue')).default
		const ctx = { reversal: { total: 5, counts: { skipped: 2 } } }
		expect(dialog.computed.skips.call(ctx)).toBe(2)
		expect(dialog.computed.writes.call({ ...ctx, skips: 2 })).toBe(3)
		expect(dialog.computed.writes.call({ reversal: {}, skips: 0 })).toBe(0)
	})
})

describe('wiring', () => {
	it('declares the page and its menu entry and registers the view', () => {
		const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../src/manifest.d/31-bulk-changes.json'), 'utf8'))
		expect(fragment.pages[0]).toMatchObject({ id: 'BulkChanges', route: '/bulk-changes', type: 'custom', component: 'BulkChangesView' })
		expect(fragment.menu[0]).toMatchObject({ id: 'BulkChanges', route: 'BulkChanges' })
		expect(readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')).toMatch(/BulkChangesView: \{\n\t\tkind: 'page',\n\t\tcomponent: BulkChangesView,/)
	})
})

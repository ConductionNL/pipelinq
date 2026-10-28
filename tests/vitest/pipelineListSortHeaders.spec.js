// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline list view's sort headers are buttons a keyboard reaches, and
 * each header says how the list is sorted (`aria-sort`): they were clickable
 * `<th>` cells with no role, no tab stop and no sort state (WCAG 2.1.1, 4.1.2).
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-33
 */

import fs from 'fs'
import path from 'path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnBuildiqEditButton: {}, CnPageHeader: {}, CnQuickFilterBar: {}, openRowTarget: vi.fn() }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: {} }))

let board

beforeAll(async () => {
	globalThis.t = (app, text) => text
	board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
})

describe('PipelineBoard list headers', () => {
	it('renders every sortable header as a button inside a th with aria-sort', () => {
		const source = fs.readFileSync(path.resolve(__dirname, '../../src/views/pipeline/PipelineBoard.vue'), 'utf8')
		const head = source.slice(source.indexOf('<thead>'), source.indexOf('</thead>'))
		expect(head).toMatch(/:aria-sort="ariaSortOf\(column\.key\)"/)
		expect(head).toMatch(/<button\s+type="button"/)
		expect(head).not.toMatch(/<th[^>]*@click/)
	})

	it('tells which column sorts the list, and which way', () => {
		const ctx = { sortBy: 'value', sortDir: 'desc' }
		expect(board.methods.ariaSortOf.call(ctx, 'value')).toBe('descending')
		expect(board.methods.ariaSortOf.call({ sortBy: 'value', sortDir: 'asc' }, 'value')).toBe('ascending')
		expect(board.methods.ariaSortOf.call(ctx, 'title')).toBe('none')
	})

	it('offers all nine columns', () => {
		const columns = board.computed.listColumns.call({ t: (app, text) => text })
		expect(columns.map((c) => c.key)).toEqual(['title', 'schemaSlug', 'stage', 'assignee', 'value', 'dueDate', 'priority', 'score', 'age'])
	})
})

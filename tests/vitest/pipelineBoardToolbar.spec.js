// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's toolbar in the board look (board PqPipeline): the
 * active filters it names, what removing one does, and Clear all.
 *
 * @spec openspec/specs/pipeline/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcActionButton: {},
	NcActionCaption: {},
	NcActionCheckbox: {},
	NcActions: {},
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextField: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnActionsBar: {},
	CnBuildiqEditButton: {},
	CnPageHeader: {},
	CnQuickFilterBar: {},
	openRowTarget: vi.fn(),
	useLook: () => ({ isBoard: true, lookClass: 'cn-look-board' }),
}))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: {} }))

let board

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)

beforeAll(async () => {
	globalThis.t = t
	board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
})

/**
 * A board context: two pipelines, Verkoop the one the board opens with.
 *
 * @param {object} state What differs from the default.
 * @return {object}
 */
function context(state = {}) {
	const ctx = {
		t,
		n: (app, one, more, count) => (count === 1 ? one : more).replace('%n', count),
		pipelines: [
			{ id: 'p-sales', title: 'Verkoop', isDefault: true },
			{ id: 'p-events', title: 'Evenementen' },
		],
		allLeads: [],
		selectedPipeline: { id: 'p-events', title: 'Evenementen' },
		selectedPipelineId: 'p-events',
		showFilter: 'all',
		showFilterOptions: [{ id: 'all', label: 'All' }, { id: 'lead', label: 'Leads' }],
		chipIndex: 0,
		chipTabs: [{ label: 'Sales pipeline' }, { label: 'My leads' }],
		searchQuery: '',
		onPipelineChange: vi.fn(),
		...state,
	}
	ctx.selectPipeline = (id) => board.methods.selectPipeline.call(ctx, id)
	return ctx
}

describe('the pipeline board toolbar', () => {
	it('names the pipeline, the type, the chip and the search as active filters', () => {
		const ctx = context({ showFilter: 'lead', chipIndex: 1, searchQuery: ' delta ' })
		expect(board.computed.activeFilterChips.call(ctx).map((chip) => chip.label)).toEqual([
			'Pipeline: Evenementen',
			'Type: Leads',
			'My leads',
			'Search: delta',
		])
	})

	it('counts the active filters in the Filter button name', () => {
		const ctx = context()
		ctx.activeFilterChips = [{ key: 'pipeline' }, { key: 'chip' }]
		expect(board.computed.filterMenuLabel.call(ctx)).toBe('Filter, 2 active')
	})

	it('takes the pipeline chip back to the pipeline the board opens with', async () => {
		const ctx = context()
		await board.methods.removeActiveFilter.call(ctx, { key: 'pipeline' })
		expect(ctx.selectedPipelineId).toBe('p-sales')
		expect(ctx.onPipelineChange).toHaveBeenCalledTimes(1)
	})

	it('clears every filter with Clear all', async () => {
		const ctx = context({ showFilter: 'lead', chipIndex: 1, searchQuery: 'delta' })
		await board.methods.clearActiveFilters.call(ctx)
		expect([ctx.showFilter, ctx.chipIndex, ctx.searchQuery, ctx.selectedPipelineId]).toEqual(['all', 0, '', 'p-sales'])
	})

	it('draws the board toolbar only in the board look, with its three rows', () => {
		const source = readFileSync(resolve(__dirname, '../../src/views/pipeline/PipelineBoard.vue'), 'utf8')
		expect(source).toMatch(/<CnActionsBar\s+v-if="isBoard"\s+layout="board"/)
		for (const id of ['pipeline-filter-menu', 'pipeline-select', 'pipeline-settings-link', 'pipeline-columns']) {
			expect(source).toContain(`data-testid="${id}"`)
		}
		expect(source).toContain('<div v-if="!isBoard" class="pipeline-board__header">')
	})
})

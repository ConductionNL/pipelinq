// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board opens where the open leads are, and names the leads no
 * board column shows (pipelinq review F1 and the empty default board).
 *
 * @spec openspec/changes/review-audit-fixes-b/specs/pipeline/spec.md
 */

import { beforeAll, describe, expect, it, vi } from 'vitest'
import {
	choosePipeline,
	findOrphanedLeads,
	openLeadCounts,
} from '../../src/services/pipelineOrphans.js'

vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/store.js', () => ({ initializeStores: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {},
	NcCheckboxRadioSwitch: {},
	NcLoadingIcon: {},
	NcNoteCard: {},
	NcSelect: {},
	NcTextField: {},
}))
vi.mock('@conduction/nextcloud-vue', () => ({ openRowTarget: vi.fn() }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: {} }))

const sales = {
	id: 'p-sales',
	title: 'Sales Pipeline',
	isDefault: true,
	stages: [{ name: 'New' }, { name: 'Won', isClosed: true }],
}
const verkoop = {
	id: 'p-verkoop',
	title: 'Verkoop (voorbeeld)',
	isDefault: false,
	stages: [{ name: 'Nieuw' }, { name: 'Voorstel' }],
}

describe('choosePipeline', () => {
	it('opens on the default pipeline when it has open leads', () => {
		const leads = [
			{ pipeline: 'p-sales', status: 'open' },
			{ pipeline: 'p-verkoop', status: 'open' },
			{ pipeline: 'p-verkoop', status: 'open' },
		]
		expect(choosePipeline([verkoop, sales], leads)).toBe('p-sales')
	})

	it('opens on the pipeline with the open leads when the default has none', () => {
		const leads = [
			{ pipeline: 'p-sales', status: 'won' },
			{ pipeline: 'p-verkoop', status: 'open' },
		]
		expect(choosePipeline([sales, verkoop], leads)).toBe('p-verkoop')
	})

	it('falls back to the default pipeline without open leads anywhere', () => {
		expect(choosePipeline([verkoop, sales], [])).toBe('p-sales')
		expect(choosePipeline([], [])).toBeNull()
	})

	it('counts open leads only', () => {
		const counts = openLeadCounts([
			{ pipeline: 'p-sales', status: 'open' },
			{ pipeline: 'p-sales', status: 'lost' },
			{ pipeline: 'p-sales' },
		])
		expect(counts.get('p-sales')).toBe(2)
	})
})

describe('findOrphanedLeads', () => {
	it('names leads on a deleted pipeline and leads in an unknown stage', () => {
		const leads = [
			{ id: 'a', pipeline: 'p-sales', stage: 'New' },
			{ id: 'b', pipeline: '44c997c9-gone', stage: 'Qualified' },
			{ id: 'c', pipeline: 'p-verkoop', stage: 'Qualified' },
			{ id: 'd', title: 'No pipeline yet' },
		]
		const found = findOrphanedLeads(leads, [sales, verkoop])
		expect(found.map((o) => [o.lead.id, o.reason])).toEqual([
			['b', 'pipeline'],
			['c', 'stage'],
		])
	})
})

describe('PipelineBoard opening', () => {
	let board

	beforeAll(async () => {
		globalThis.t = (app, text) => text
		board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
	})

	it('opens on the pipeline that holds the open leads, not an empty default', async () => {
		const ctx = {
			loading: false,
			pipelines: [sales, verkoop],
			selectedPipelineId: null,
			allLeads: [],
			objectStore: { fetchCollection: vi.fn() },
			fetchAllLeads: vi.fn().mockResolvedValue([
				{ id: 'l1', pipeline: 'p-verkoop', stage: 'Nieuw', status: 'open' },
				{
					id: 'l2',
					pipeline: 'p-verkoop',
					stage: 'Voorstel',
					status: 'open',
				},
			]),
			fetchPipelineItems: vi.fn(),
			syncLiveSubscriptions: vi.fn(),
		}

		await board.mounted.call(ctx)

		expect(ctx.selectedPipelineId).toBe('p-verkoop')
		expect(ctx.allLeads).toHaveLength(2)
		expect(ctx.fetchPipelineItems).toHaveBeenCalled()
	})
})

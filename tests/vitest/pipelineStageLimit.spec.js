// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A pipeline stage can carry a limit (pipeline-stage-limit): the editor offers
 * it on open stages only, the payload sends it, and the board header shows the
 * count against it with a full state.
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-a-stage-can-carry-a-limit-req-psl-001
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'
import { pipelineStagesPayload, stageLimit } from '../../src/services/pipelinePayload.js'

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
	CnPageHeader: {},
	CnBuildiqEditButton: {},
	CnQuickFilterBar: {},
	openRowTarget: vi.fn(),
	useLook: () => ({ isBoard: false, lookClass: '' }),
}))
vi.mock('../../src/dialogs/LeadCreateDialog.vue', () => ({ default: {} }))
vi.mock('../../src/components/leadScore/LeadScoreBadge.vue', () => ({ default: {} }))
vi.mock('../../src/dialogs/PipelineFormDialog.vue', () => ({ default: {} }))
vi.mock('../../src/views/pipeline/PipelineCard.vue', () => ({ default: {} }))

const root = resolve(__dirname, '../..')
let board

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)
	board = (await import('../../src/views/pipeline/PipelineBoard.vue')).default
})

describe('stage limit payload', () => {
	it('sends a limit on an open stage and drops it on a closed one', () => {
		const [open, closed, none] = pipelineStagesPayload([
			{ name: 'Proposal', order: 2, maxItems: '8' },
			{ name: 'Won', order: 3, isClosed: true, maxItems: 4 },
			{ name: 'New', order: 1, maxItems: null },
		])
		expect(open.maxItems).toBe(8)
		expect(closed).not.toHaveProperty('maxItems')
		expect(none).not.toHaveProperty('maxItems')
	})

	it('reads a stage limit only from an open stage with a whole number', () => {
		expect(stageLimit({ maxItems: 8 })).toBe(8)
		expect(stageLimit({ maxItems: 8, isClosed: true })).toBeNull()
		expect(stageLimit({ maxItems: 0 })).toBeNull()
		expect(stageLimit({})).toBeNull()
	})

	it('is declared on the stage in the pipeline schema', () => {
		const register = JSON.parse(readFileSync(resolve(root, 'lib/Settings/pipelinq_register.json'), 'utf8'))
		const stage = register.components.schemas.pipeline.properties.stages.items.properties
		expect(stage.maxItems).toMatchObject({ type: 'integer', minimum: 1 })
	})
})

describe('board column header', () => {
	const ctx = (count) => ({ getStageItems: () => Array.from({ length: count }, (v, i) => ({ id: i })) })

	it('shows the count against the limit and a full state', () => {
		const proposal = { name: 'Proposal', maxItems: 8 }
		expect(board.methods.stageCountLabel.call(ctx(3), proposal)).toBe('3 / 8')
		expect(board.methods.isStageFull.call(ctx(3), proposal)).toBe(false)
		expect(board.methods.isStageFull.call(ctx(8), proposal)).toBe(true)
		expect(board.methods.isStageFull.call(ctx(10), proposal)).toBe(true)
	})

	it('shows the bare count without a limit', () => {
		expect(board.methods.stageCountLabel.call(ctx(5), { name: 'New' })).toBe('5')
		expect(board.methods.isStageFull.call(ctx(5), { name: 'New' })).toBe(false)
	})
})

describe('pipeline editor', () => {
	it('offers the limit on open stages only', () => {
		const source = readFileSync(resolve(root, 'src/dialogs/PipelineFormDialog.vue'), 'utf8')
		expect(source).toMatch(/<NcTextField\s+v-if="!stage\.isClosed"\s+:modelValue="String\(stage\.maxItems \?\? ''\)"\s+:label="t\('pipelinq', 'Limit'\)"/)
	})
})

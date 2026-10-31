// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A card dropped on another column: the save re-reads the object, so a field
 * changed since the board loaded keeps its new value, and a failed save puts
 * the card back.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-17
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const dialogs = vi.hoisted(() => ({ showError: vi.fn() }))

vi.mock('@nextcloud/dialogs', () => dialogs)
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

let moveItemToStage

beforeAll(async () => {
	globalThis.t = (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (m, key) => vars[key] ?? m)
	const board = (await import('../../src/views/pipeline/PipelineBoard.vue'))
		.default
	moveItemToStage = board.methods.moveItemToStage
})

beforeEach(() => {
	dialogs.showError.mockReset()
})

/**
 * A board context around one lead card in the "New" column.
 *
 * @param {object} store The object store stand-in.
 * @return {{ctx: object, item: object}}
 */
function board(store) {
	const item = {
		id: 'lead-1',
		title: 'Ovens',
		assignee: 'pieter',
		stage: 'New',
		stageOrder: 0,
		_schemaSlug: 'lead',
		_entityType: 'lead',
	}
	const ctx = {
		objectStore: store,
		getColumnProperty: () => 'stage',
		refreshItems: vi.fn(),
	}
	return { ctx, item }
}

describe('moveItemToStage', () => {
	it('saves the freshly read object with only the stage changed', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({
				id: 'lead-1',
				title: 'Ovens',
				assignee: 'marieke',
				stage: 'New',
				stageOrder: 0,
			}),
			saveObject: vi.fn().mockResolvedValue({ id: 'lead-1' }),
		}
		const { ctx, item } = board(store)

		await moveItemToStage.call(ctx, item, { name: 'Won', order: 3 })

		expect(store.fetchObject).toHaveBeenCalledWith('lead', 'lead-1')
		const [, payload] = store.saveObject.mock.calls[0]
		expect(payload).toMatchObject({
			assignee: 'marieke',
			stage: 'Won',
			stageOrder: 3,
		})
		expect(payload).not.toHaveProperty('_schemaSlug')
		expect(ctx.refreshItems).toHaveBeenCalled()
	})

	it('puts the card back and says so when the save fails', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'lead-1', stage: 'New' }),
			saveObject: vi.fn().mockResolvedValue(null),
		}
		const { ctx, item } = board(store)

		await moveItemToStage.call(ctx, item, { name: 'Won', order: 3 })

		expect(item.stage).toBe('New')
		expect(item.stageOrder).toBe(0)
		expect(dialogs.showError).toHaveBeenCalledWith(
			'Could not move the card to Won.',
		)
	})

	it('shows the server refusal of a full stage and puts the card back', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue({ id: 'lead-1', stage: 'New' }),
			saveObject: vi.fn().mockResolvedValue(null),
			errors: { lead: { isValidation: true, message: 'Stage Proposal is full (8 of 8)' } },
		}
		const { ctx, item } = board(store)

		await moveItemToStage.call(ctx, item, { name: 'Proposal', order: 2 })

		expect(item.stage).toBe('New')
		expect(dialogs.showError).toHaveBeenCalledWith('Stage Proposal is full (8 of 8)')
	})

	it('does not save when the object cannot be re-read', async () => {
		const store = {
			fetchObject: vi.fn().mockResolvedValue(null),
			saveObject: vi.fn(),
		}
		const { ctx, item } = board(store)

		await moveItemToStage.call(ctx, item, { name: 'Won', order: 3 })

		expect(store.saveObject).not.toHaveBeenCalled()
		expect(item.stage).toBe('New')
		expect(dialogs.showError).toHaveBeenCalled()
	})
})

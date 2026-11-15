// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline form's per-stage checks: a name, a Won stage that is closed,
 * a probability within 0 to 100 and a limit of a whole number of 1 or more.
 *
 * @spec openspec/changes/2026-03-20-pipeline/tasks.md#task-2.2
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcSelect: {}, NcTextField: {}, NcTextArea: {} }))
vi.mock('vuedraggable', () => ({ default: {} }))
vi.mock('../../src/services/viewService.js', () => ({ getViews: vi.fn() }))

let dialog

beforeAll(async () => {
	globalThis.t = (app, text) => text
	dialog = (await import('../../src/dialogs/PipelineFormDialog.vue')).default
}, 60000)

describe('PipelineFormDialog.stageErrors', () => {
	it('names what is wrong with each stage, and null for a stage that is fine', () => {
		const stages = [
			{ name: 'Open' },
			{ name: '  ' },
			{},
			{ name: 'Won', isWon: true },
			{ name: 'Won', isWon: true, isClosed: true },
			{ name: 'P', probability: -1 },
			{ name: 'P', probability: 101 },
			{ name: 'P', probability: '' },
			{ name: 'P', probability: null },
			{ name: 'P', probability: '50' },
			{ name: 'L', maxItems: 0 },
			{ name: 'L', maxItems: 2.5 },
			{ name: 'L', maxItems: 'x' },
			{ name: 'L', maxItems: '' },
			{ name: 'L', maxItems: 3 },
			{ name: 'L', maxItems: 0, isClosed: true },
			{ isWon: true, probability: 200, maxItems: -1 },
		]
		expect(dialog.computed.stageErrors.call({ form: { stages } })).toEqual([
			null,
			{ name: 'Stage name is required' },
			{ name: 'Stage name is required' },
			{ isWon: 'A Won stage must also be marked as Closed' },
			null,
			{ probability: 'Probability must be between 0 and 100' },
			{ probability: 'Probability must be between 0 and 100' },
			null,
			null,
			null,
			{ maxItems: 'A limit is a whole number of 1 or more' },
			{ maxItems: 'A limit is a whole number of 1 or more' },
			{ maxItems: 'A limit is a whole number of 1 or more' },
			null,
			null,
			null,
			{ name: 'Stage name is required', isWon: 'A Won stage must also be marked as Closed', probability: 'Probability must be between 0 and 100', maxItems: 'A limit is a whole number of 1 or more' },
		])
	})
})

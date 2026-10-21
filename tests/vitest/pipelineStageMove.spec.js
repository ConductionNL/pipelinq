/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The pipeline board's move handler: a card dropped on another stage is
 * written to the record it stands for, re-read first, into the property the
 * pipeline maps, and a refused write is a refusal the board shows.
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 */

import { describe, expect, it, vi } from 'vitest'
import { createPipelineStageMove } from '../../src/services/pipelineStageMove.js'

const translate = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)

/**
 * A store double.
 *
 * @param {object|null} current What fetchObject answers.
 * @param {object|null} saved What saveObject answers.
 * @return {object}
 */
function store(current, saved) {
	return { fetchObject: vi.fn().mockResolvedValue(current), saveObject: vi.fn().mockResolvedValue(saved) }
}

describe('pipeline stage move', () => {
	it('writes the stage and its order on a lead, keeping what changed since the board loaded', async () => {
		const s = store({ id: 'l1', stage: 'New', value: 9000 }, { id: 'l1' })
		const move = createPipelineStageMove({ objectStore: () => s, translate })
		await move({ card: { id: 'l1', _objectType: 'lead', _columnProperty: 'stage', value: 100 }, toKey: 'Proposal', value: 'Proposal', target: { order: 3 } })
		expect(s.fetchObject).toHaveBeenCalledWith('lead', 'l1')
		expect(s.saveObject).toHaveBeenCalledWith('lead', { id: 'l1', stage: 'Proposal', stageOrder: 3, value: 9000 })
	})

	it('writes a ticket\'s mapped property and keeps its ticketType', async () => {
		const s = store({ id: 'c1', phase: 'Intake' }, { id: 'c1' })
		const move = createPipelineStageMove({ objectStore: () => s, translate })
		await move({ card: { id: 'c1', _objectType: 'ticket', _columnProperty: 'phase', ticketType: 'complaint' }, toKey: 'Review' })
		expect(s.saveObject).toHaveBeenCalledWith('ticket', { id: 'c1', phase: 'Review', ticketType: 'complaint' })
	})

	it('rejects with a sentence when the record cannot be read or the write is refused', async () => {
		const gone = createPipelineStageMove({ objectStore: () => store(null, null), translate })
		await expect(gone({ card: { id: 'x' }, toKey: 'Won' })).rejects.toThrow('Could not move the card to Won.')
		const refused = createPipelineStageMove({ objectStore: () => store({ id: 'x' }, null), translate })
		await expect(refused({ card: { id: 'x' }, toKey: 'Won' })).rejects.toThrow('Could not move the card to Won.')
	})
})

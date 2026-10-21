// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The pipeline board's move: a card dropped on another stage, written to the
 * record it stands for.
 *
 * A board card is a lead, a request, a complaint or a contact moment
 * (`_objectType`, `_columnProperty` come from GET /api/pipeline-items). The
 * move re-reads the record first, so a field a colleague changed since the
 * board loaded keeps its new value, writes the stage into the property the
 * pipeline maps for that kind (with the stage's `order` on a lead), and keeps
 * a ticket's `ticketType`. A refused write rejects with a sentence the board
 * shows as its refusal.
 *
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 */

/**
 * Build the registry handler the board's `moveHandler` names.
 *
 * @param {object} options The dependencies.
 * @param {() => object} options.objectStore Returns the object store.
 * @param {(app: string, text: string, vars?: object) => string} options.translate The translate function.
 * @return {(move: {card: object, toKey: string, value?: string, target?: object}) => Promise<object>} The handler.
 * @spec openspec/specs/pipeline/spec.md#requirement-kanban-board-view-mvp
 */
export function createPipelineStageMove({ objectStore, translate }) {
	return async ({ card, toKey, value, target }) => {
		const store = objectStore()
		const type = card?._objectType || 'lead'
		const property = card?._columnProperty || 'stage'
		const stage = value ?? toKey
		const refused = () => new Error(translate('pipelinq', 'Could not move the card to {stage}.', { stage }))

		const current = await store.fetchObject(type, card.id)
		if (!current) {
			throw refused()
		}
		const payload = { ...current, id: card.id, [property]: stage }
		if (property === 'stage' && typeof target?.order === 'number') {
			payload.stageOrder = target.order
		}
		if (type === 'ticket' && !payload.ticketType && card.ticketType) {
			payload.ticketType = card.ticketType
		}
		const saved = await store.saveObject(type, payload)
		if (!saved) {
			throw refused()
		}
		return saved
	}
}

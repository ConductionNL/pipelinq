/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The steps of dropping a board card on another stage: put the card there,
 * build the object to save, and read a refusal of the save.
 *
 * @module views/pipeline/stageMove
 */

/**
 * Put the card on the target stage, and say where it was.
 *
 * @param {{item: object, columnProp: string, targetStage: object}} move The move.
 * @return {object} The column value and stage order the card had.
 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-17
 */
export function placeOnStage(move) {
	const { item, columnProp, targetStage } = move
	const previous = {
		[columnProp]: item[columnProp],
		stageOrder: item.stageOrder,
	}
	item[columnProp] = targetStage.name
	if (columnProp === 'stage' && typeof targetStage.order === 'number') {
		item.stageOrder = targetStage.order
	}
	return previous
}

/**
 * The freshly read object with the card's new column value, its stage order
 * when that changed, and the ticket type a ticket must keep.
 *
 * @param {object} current The object as read before the save.
 * @param {{item: object, columnProp: string, previous: object, ticketType: ?string}} move The move.
 * @return {object} The object to save.
 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-17
 */
export function stagePayload(current, move) {
	const { item, columnProp, previous, ticketType } = move
	const payload = {
		...current,
		id: item.id,
		[columnProp]: item[columnProp],
	}
	if (item.stageOrder !== previous.stageOrder)
		payload.stageOrder = item.stageOrder
	if (ticketType && !payload.ticketType)
		payload.ticketType = ticketType
	return payload
}

/**
 * The server's own words for a refused save, when it gave a validation message.
 *
 * @param {?object} refusal The store's error for the object type.
 * @return {?string} The message, or null.
 * @spec openspec/changes/reverse-2026-05-26-fe-pipeline-ui/tasks.md#task-17
 */
export function validationMessage(refusal) {
	return refusal?.isValidation && refusal.message
		? refusal.message
		: null
}

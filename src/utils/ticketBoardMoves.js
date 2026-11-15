// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * The two named handlers the tickets board runs (manifest
 * `config.board.moveHandler` and `moveTargetsHandler`). A status move follows
 * the ticket lifecycle in `requestStatus.js`, the rules the ticket page uses,
 * and the board asks the same rules where a ticket may go.
 *
 * @spec openspec/changes/tickets-board-view/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-opens-as-a-board-by-status
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { getAllowedTransitions, getStatusLabel, isValidTransition } from '../services/requestStatus.js'

/**
 * Move a ticket to a status when the lifecycle allows it.
 *
 * Throws with the sentence the board shows when the move is not allowed; the
 * card then goes back and nothing is written.
 *
 * @param {object} move The board's move.
 * @param {object} move.card The ticket row.
 * @param {string} move.value The status the card was dropped on.
 * @return {Promise<object>} The write's answer.
 * @spec openspec/changes/tickets-board-view/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-opens-as-a-board-by-status
 */
export async function moveTicketStatus({ card, value }) {
	const from = String(card?.status ?? '')
	const to = String(value ?? '')
	if (from === '' || !isValidTransition(from, to)) {
		throw new Error(t('pipelinq', 'You cannot move this ticket from {from} to {to}.', {
			from: getStatusLabel(from),
			to: getStatusLabel(to),
		}))
	}
	const id = card?.id ?? card?.['@self']?.id
	return axios.patch(
		generateUrl(`/apps/openregister/api/objects/pipelinq/ticket/${encodeURIComponent(id)}`),
		{ status: to },
	)
}

/**
 * The statuses a ticket can reach from where it is.
 *
 * @param {object} query The board's question.
 * @param {object} query.card The ticket row.
 * @return {Promise<Array<string>>} The reachable statuses.
 * @spec openspec/changes/tickets-board-view/specs/unify-ticket-supertype/spec.md#requirement-the-tickets-list-opens-as-a-board-by-status
 */
export async function ticketStatusTargets({ card }) {
	return [...getAllowedTransitions(String(card?.status ?? ''))]
}

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The walk-in queue actions on the WalkInQueue page (PqInloop): call the
 * customer who has waited longest, call one customer, help a called one, or
 * mark one as left. A walk-in ticket is a plain OpenRegister object, so each
 * action writes the ticket's status (and, for Help and Left, when the
 * customer was served or left).
 *
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */

/**
 * The OpenRegister path of the walk-in tickets, or of one ticket.
 *
 * @param {string} [id] The ticket id.
 * @return {string} The app-relative path.
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
export function walkInPath(id = '') {
	const base = '/apps/openregister/api/objects/pipelinq/walkInTicket'
	return id ? `${base}/${encodeURIComponent(id)}` : base
}

/**
 * The ticket as a write body: the row with the new values, without the
 * metadata block the server owns.
 *
 * @param {object} ticket The ticket row.
 * @param {object} values The fields to change.
 * @return {object} The body.
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
export function ticketBody(ticket, values) {
	const body = { ...ticket, ...values }
	delete body['@self']
	return body
}

/**
 * Registry handlers for the walk-in queue, keyed by the manifest handler name.
 *
 * @param {object} deps Injected so the module stays testable without a DOM.
 * @param {(path: string) => string} deps.generateUrl Nextcloud's URL builder.
 * @param {{get: (url: string, config?: object) => Promise<object>, put: (url: string, body: object) => Promise<object>}} deps.http An axios-like client.
 * @param {(msg: string) => void} deps.showSuccess Success toast.
 * @param {(msg: string) => void} deps.showError Error toast.
 * @param {(app: string, text: string) => string} deps.translate The `t()` function.
 * @param {() => Date} [deps.now] The clock.
 * @return {Record<string, (scope?: {item?: object}) => Promise<boolean>>} The handlers.
 * @spec openspec/changes/screens-inloop-resource-board/specs/appointment-booking/spec.md
 */
export function createWalkInHandlers({ generateUrl, http, showSuccess, showError, translate, now = () => new Date() }) {
	/**
	 * Write new values onto a ticket.
	 *
	 * @param {object} ticket The ticket row.
	 * @param {object} values The fields to change.
	 * @param {string} done The success message.
	 * @return {Promise<boolean>} Whether the write succeeded.
	 */
	async function update(ticket, values, done) {
		const id = ticket?.id || ticket?.['@self']?.id
		if (!id) {
			return false
		}
		try {
			await http.put(generateUrl(walkInPath(id)), ticketBody(ticket, values))
		} catch {
			showError(translate('pipelinq', 'Could not update the ticket. Please try again.'))
			return false
		}
		showSuccess(done)
		return true
	}

	return {
		walkInCall: (scope = {}) => update(scope.item, { status: 'called' }, translate('pipelinq', 'Customer called.')),
		walkInServe: (scope = {}) => update(
			scope.item,
			{ status: 'served', actualServedAt: now().toISOString() },
			translate('pipelinq', 'Customer helped.'),
		),
		walkInAbandon: (scope = {}) => update(
			scope.item,
			{ status: 'abandoned', leftAt: now().toISOString() },
			translate('pipelinq', 'Marked as left.'),
		),
		walkInCallNext: async () => {
			let next
			try {
				const response = await http.get(generateUrl(walkInPath()), {
					params: { status: 'waiting', _order: { arrivedAt: 'asc' }, _limit: 1 },
				})
				next = response?.data?.results?.[0] || null
			} catch {
				showError(translate('pipelinq', 'Could not load the queue.'))
				return false
			}
			if (!next) {
				showError(translate('pipelinq', 'Nobody is waiting.'))
				return false
			}
			return update(next, { status: 'called' }, translate('pipelinq', 'Customer called.'))
		},
	}
}

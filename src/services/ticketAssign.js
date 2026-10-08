/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * "Assign to me" for a ticket row on the Queue.
 *
 * The Queue is every open ticket nobody has picked up. Taking one sets its
 * assignee to you, which moves it off the Queue and onto your My Work, and
 * then opens it, because the next thing you do with a ticket you picked up
 * is work on it. TicketDetail offers the same action as a header action.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
 */

/**
 * The endpoint that assigns a ticket to the signed-in user.
 *
 * @param {string} id The ticket id.
 * @return {string} The app-relative path.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
 */
export function assignToMePath(id) {
	return `/apps/pipelinq/api/tickets/${encodeURIComponent(id)}/assign-to-me`
}

/**
 * Registry handlers for ticket actions, keyed by the manifest handler name.
 *
 * @param {object} deps Injected so the module stays testable without a DOM.
 * @param {(path: string) => string} deps.generateUrl Nextcloud's URL builder.
 * @param {{post: (url: string) => Promise<object>}} deps.http An axios-like client.
 * @param {{push: (location: object) => unknown}} deps.router The app router.
 * @param {(msg: string) => void} deps.showSuccess Success toast.
 * @param {(msg: string) => void} deps.showError Error toast.
 * @param {(app: string, text: string) => string} deps.translate The `t()` function.
 * @return {Record<string, (scope: {item?: object}) => Promise<boolean>>} The handlers.
 *
 * @spec openspec/changes/detail-pages-read-at-a-glance/specs/my-work/spec.md
 */
export function createTicketHandlers({
	generateUrl,
	http,
	router,
	showSuccess,
	showError,
	translate,
}) {
	return {
		assignTicketToMe: async (scope = {}) => {
			const id = scope?.item?.id || scope?.item?.['@self']?.id
			if (!id) {
				return false
			}
			try {
				await http.post(generateUrl(assignToMePath(id)))
			} catch (error) {
				showError(
					error?.response?.data?.error
						|| translate(
							'pipelinq',
							'Could not assign this ticket to you.',
						),
				)
				return false
			}
			showSuccess(
				translate('pipelinq', 'Assigned to you. It is on your My Work now.'),
			)
			router.push({ name: 'TicketDetail', params: { id } })
			return true
		},
	}
}

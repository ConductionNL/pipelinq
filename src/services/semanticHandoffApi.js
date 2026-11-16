/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The semantic handoff endpoints a record section calls: whether the handoff
 * is available for a record, the handoff itself, and how a refusal reads.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * @param {{path: string, id: string}} target The route under `/api/handoff/`, with `{id}` for the record, and the record id.
 * @return {string} The URL.
 */
function handoffUrl(target) {
	return generateUrl('/apps/pipelinq/api/handoff/' + target.path, { id: target.id })
}

/**
 * Read whether a record's handoff is available.
 *
 * @param {string} kind The record kind in the route, such as `request` or `contract`.
 * @param {string} id The record id.
 * @return {Promise<object>} The availability body.
 * @spec exclude transport plumbing shared by the handoff sections, no behaviour of its own
 */
export async function handoffAvailability(kind, id) {
	const { data } = await axios.get(handoffUrl({ path: kind + '/{id}/availability', id }))
	return data
}

/**
 * Post a record's handoff.
 *
 * @param {{path: string, id: string}} target The route under `/api/handoff/`, such as `contract/{id}/send-to-invoicing`, and the record id.
 * @param {object} body The request body.
 * @return {Promise<object>} The response body.
 * @spec exclude transport plumbing shared by the handoff sections, no behaviour of its own
 */
export async function postHandoff(target, body) {
	const { data } = await axios.post(handoffUrl(target), body)
	return data
}

/**
 * @param {?object} err A failed handoff call.
 * @return {object} The refusal body, or an empty object.
 * @spec exclude transport plumbing shared by the handoff sections, no behaviour of its own
 */
export function handoffRefusal(err) {
	return (err && err.response && err.response.data) || {}
}

/**
 * @param {object} body A refusal body.
 * @return {boolean} Whether the handoff is no longer available for the record.
 * @spec exclude transport plumbing shared by the handoff sections, no behaviour of its own
 */
export function noLongerAvailable(body) {
	return body.status === 'invalid-status' || body.status === 'not-available'
}

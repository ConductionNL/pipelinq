// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.

import { generateUrl } from '@nextcloud/router'

/**
 * The route another app links to for the contact-centre agent panel. It is
 * a plain page route of pipelinq: a consuming app needs no pipelinq
 * component, no iframe and no build-time dependency to reach it.
 *
 * @type {string}
 */
export const AGENT_PANEL_ROUTE = '/apps/pipelinq/kcc/agent-panel'

/**
 * The longest call id the panel accepts from a link. Anything longer is not
 * a call id and the panel falls back to the agent's newest active call.
 *
 * @type {number}
 */
const MAX_CALL_ID_LENGTH = 255

/**
 * The URL of the agent panel, optionally focused on one call. This is the
 * same call a consuming app makes itself with `@nextcloud/router`: the call
 * id goes in through a `{callId}` placeholder, because `generateUrl()` only
 * fills placeholders and never appends a query of its own.
 *
 * @param {string|number|null} [callId] The call to focus; none links to the live queue.
 * @param {object} [options] Options handed to `generateUrl()` (e.g. `baseURL`).
 * @return {string} The panel URL.
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-another-app-links-in-with-a-plain-url
 */
export function agentPanelUrl(callId = null, options = undefined) {
	const id = normaliseCallId(callId)
	if (id === null) {
		return generateUrl(AGENT_PANEL_ROUTE, {}, options)
	}
	return generateUrl(AGENT_PANEL_ROUTE + '?callId={callId}', { callId: id }, options)
}

/**
 * The call a link asks the panel to focus, read from its query string. No
 * `callId`, an empty one or one that cannot be a call id is null: the panel
 * then shows the agent's newest active call.
 *
 * @param {string|URLSearchParams} search The query string (`?callId=42`) or its params.
 * @return {string|null} The call id, or null.
 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-another-app-links-in-with-a-plain-url
 */
export function focusedCallId(search) {
	const params = search instanceof URLSearchParams ? search : new URLSearchParams(String(search ?? ''))
	return normaliseCallId(params.get('callId'))
}

/**
 * A call id as text, or null when it is missing, blank or too long.
 *
 * @param {unknown} value The candidate call id.
 * @return {string|null} The trimmed call id, or null.
 */
function normaliseCallId(value) {
	if (typeof value !== 'string' && typeof value !== 'number') {
		return null
	}
	const id = String(value).trim()
	if (id === '' || id.length > MAX_CALL_ID_LENGTH) {
		return null
	}
	return id
}

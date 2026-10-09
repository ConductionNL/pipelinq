// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The public survey a satisfaction invitation links to
// (customer-satisfaction-closed-loop). The token in the link is the only
// authorisation: these calls carry no session. The server answers a token it
// will not take with a status and a `state` (`responded`, `expired`,
// `unknown`), which comes back here as `{ state }` rather than as a throw, so
// the page can say the survey is closed instead of showing an error.
//
// @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The API path for one invitation token.
 *
 * @param {string} token The invitation token.
 * @return {string} The url.
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
export function invitationUrl(token) {
	return generateUrl('/apps/pipelinq/survey/i/{token}', { token })
}

/**
 * Turn an axios failure into the state the server named, or rethrow.
 *
 * @param {Error} error The failure.
 * @return {{state: string}} The state.
 */
function refusal(error) {
	const data = error && error.response && error.response.data
	if (data && typeof data === 'object' && typeof data.state === 'string') {
		return { state: data.state }
	}
	throw error
}

/**
 * Read the survey behind a token.
 *
 * @param {string} token The invitation token.
 * @return {Promise<{state: string, survey?: object}>} The survey when open.
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
export async function fetchInvitation(token) {
	try {
		const { data } = await axios.get(invitationUrl(token))
		if (!data || typeof data !== 'object') {
			return { state: 'unknown' }
		}
		return { state: data.state || 'open', survey: data.survey || {} }
	} catch (error) {
		return refusal(error)
	}
}

/**
 * Send the answers for a token.
 *
 * @param {string} token The invitation token.
 * @param {object} answers The answers, keyed by question key.
 * @param {boolean} optOut Whether the respondent asked not to be asked again.
 * @return {Promise<{state: string}>} `answered`, or the state that stopped it.
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-tokenized-invitation-response-collection
 */
export async function submitInvitation(token, answers, optOut) {
	try {
		// Unticked is sent as no answer, not as "keep sending": the server
		// treats a missing optOut as a preference nobody stated.
		const payload = optOut === true ? { answers, optOut: true } : { answers }
		await axios.post(invitationUrl(token), payload)
		return { state: 'answered' }
	} catch (error) {
		return refusal(error)
	}
}

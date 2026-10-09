// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The satisfaction figures behind the Operational dashboard's response-rate
// widget and the client page's satisfaction panel
// (customer-satisfaction-closed-loop). Both endpoints live in
// lib/Controller/SatisfactionController.php.
//
// @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The response rate of the satisfaction invitations.
 *
 * @param {object} options The filters.
 * @param {number} [options.days] The period in days, 0 for all time.
 * @param {string} [options.surveyId] One survey, or every survey.
 * @return {Promise<object>} delivered, responded, rate, suppressed, failed, byChannel.
 */
export async function fetchResponseRate({ days = 0, surveyId = '' } = {}) {
	const params = {}
	if (days > 0) {
		params.days = days
	}
	if (surveyId) {
		params.surveyId = surveyId
	}
	const { data } = await axios.get(
		generateUrl('/apps/pipelinq/api/satisfaction/response-rate'),
		{ params },
	)
	return data && typeof data === 'object' ? data : {}
}

/**
 * One client's satisfaction panel.
 *
 * @param {string} clientId The client's uuid.
 * @return {Promise<object>} empty, responseCount, nps, averageRating, trend, verbatims.
 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
 */
export async function fetchClientSatisfaction(clientId) {
	const { data } = await axios.get(
		generateUrl('/apps/pipelinq/api/satisfaction/client/{clientId}', {
			clientId,
		}),
	)
	return data && typeof data === 'object' ? data : { empty: true }
}

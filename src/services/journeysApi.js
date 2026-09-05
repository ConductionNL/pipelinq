// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Thin client for the journey and weekly review endpoints.
 *
 * A journey is NOT written through the object store. Every write compiles
 * the journey into an OpenRegister flow, and a journey saved through the
 * generic object API would be stored and never compiled, which looks exactly
 * like a journey whose trigger has not fired yet.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Every journey, with the flow status of each.
 *
 * @return {Promise<Array<object>>} The journeys.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
export async function fetchJourneys() {
	const { data } = await axios.get(generateUrl('/apps/pipelinq/api/journeys'))
	return data?.results || []
}

/**
 * One journey.
 *
 * @param {string} journeyId The journey id.
 * @return {Promise<object>} The journey.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
export async function fetchJourney(journeyId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/journeys/${journeyId}`),
	)
	return data
}

/**
 * Write a journey and compile it in the same call.
 *
 * @param {object} payload The journey fields.
 * @param {string} journeyId The journey id when editing, empty when creating.
 * @return {Promise<object>} The stored journey, with its flow status.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-is-an-openregister-flow-and-pipelinq-ships-no-scheduler
 */
export async function saveJourney(payload, journeyId = '') {
	const url = journeyId
		? generateUrl(`/apps/pipelinq/api/journeys/${journeyId}`)
		: generateUrl('/apps/pipelinq/api/journeys')
	const { data } = journeyId
		? await axios.patch(url, payload)
		: await axios.post(url, payload)
	return data
}

/**
 * What one journey did, and who it refused.
 *
 * @param {string} journeyId The journey id.
 * @return {Promise<Array<object>>} The runs.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
 */
export async function fetchJourneyRuns(journeyId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/journeys/${journeyId}/runs`),
	)
	return data?.results || []
}

/**
 * The weekly review, in one request.
 *
 * @return {Promise<object>} The review.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */
export async function fetchWeeklyReview() {
	const { data } = await axios.get(generateUrl('/apps/pipelinq/api/weekly-review'))
	return data
}

/**
 * Compose the review again for one week.
 *
 * @param {string} weekStarting The Monday as `YYYY-MM-DD`, empty for last week.
 * @return {Promise<object>} The stored review.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-three-sources-and-names-the-one-it-cannot
 */
export async function generateWeeklyReview(weekStarting = '') {
	const { data } = await axios.post(
		generateUrl('/apps/pipelinq/api/weekly-review/generate'),
		{ weekStarting },
	)
	return data
}

/**
 * The derived segment fields, and whether the bookkeeping behind them reads.
 *
 * @return {Promise<object>} `{catalogue, availability}`.
 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-segment-builder-lists-the-signals-and-validates-a-rule-on-one
 */
export async function fetchSegmentSignals() {
	const { data } = await axios.get(
		generateUrl('/apps/pipelinq/api/segments/signals'),
	)
	return data
}

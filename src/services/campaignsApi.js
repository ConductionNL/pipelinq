// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Thin client for the two campaign endpoints the interface calls.
 *
 * Everything else about a campaign is an ordinary OpenRegister object and
 * goes through the object store; only the aggregate report and the
 * landing-page hand-off need an endpoint of their own.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * The whole campaign report, in one request.
 *
 * One call on purpose: the page paints from this response and never asks
 * again per lead, per blast or per touchpoint (pipelinq#1781).
 *
 * @param {string} campaignId The campaign id.
 * @param {object} window Optional `{from, to}` as `YYYY-MM-DD`.
 * @return {Promise<object>} The report.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
export async function fetchCampaignReport(campaignId, window = {}) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/campaigns/${campaignId}/report`),
		{ params: { from: window.from, to: window.to } },
	)
	return data
}

/**
 * Ask portaliq for a landing page with a lead-capture form.
 *
 * Portaliq's own failure code comes back in the body on every non-2xx, and
 * the caller shows it as it is: a duplicate route and an invalid form are
 * fixed in different places.
 *
 * @param {string} campaignId The campaign id.
 * @param {object} options Optional `{portal, route}`.
 * @return {Promise<object>} `{error, portal, route, pageId, publicUrl, formId}`.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
 */
export async function createCampaignLandingPage(campaignId, options = {}) {
	try {
		const { data } = await axios.post(
			generateUrl(`/apps/pipelinq/api/campaigns/${campaignId}/landing-page`),
			{ portal: options.portal || '', route: options.route || '' },
		)
		return data
	} catch (error) {
		const body = error?.response?.data
		if (body && typeof body === 'object' && body.error) {
			return body
		}
		throw error
	}
}

/**
 * Every campaign, newest first, through the generic object API.
 *
 * The report page needs a picker before it has an id, and the object store
 * is not mounted on a page that is not bound to a schema, so the read goes
 * straight to OpenRegister the way the search-queries page does.
 *
 * @param {number} limit Page size.
 * @return {Promise<Array<object>>} The campaigns.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
 */
export async function fetchCampaigns(limit = 100) {
	const { data } = await axios.get(
		generateUrl('/apps/openregister/api/objects/pipelinq/campaign'),
		{ params: { _limit: limit } },
	)
	return data?.results || data?.data || []
}

/**
 * The source and medium vocabularies an administrator maintains.
 *
 * @return {Promise<{sources: Array<string>, mediums: Array<string>}>} Both lists.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */
export async function fetchCampaignVocabularies() {
	const { data } = await axios.get(
		generateUrl('/apps/pipelinq/api/campaigns/vocabulary'),
	)
	return { sources: data?.sources || [], mediums: data?.mediums || [] }
}

/**
 * Create or update a campaign through Pipelinq, never straight through the
 * object API.
 *
 * 🔴 THE ROUTE MATTERS. A campaign written through OpenRegister carries
 * whatever `utmCampaign` the browser sent and accepts a source outside the
 * vocabulary. Minting and the vocabulary check live in `CampaignService`,
 * which only these two endpoints reach.
 *
 * @param {object} payload The campaign fields.
 * @param {string} id The campaign to update, empty to create.
 * @return {Promise<object>} `{campaign}` on success, `{error, value, allowed}` on a refusal.
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */
export async function saveCampaign(payload, id = '') {
	const url = id
		? generateUrl(`/apps/pipelinq/api/campaigns/${id}`)
		: generateUrl('/apps/pipelinq/api/campaigns')
	try {
		const { data } = id
			? await axios.patch(url, payload)
			: await axios.post(url, payload)
		return { campaign: data }
	} catch (error) {
		const body = error?.response?.data
		if (body && typeof body === 'object' && body.error) {
			return body
		}
		throw error
	}
}

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The template preview the blast wizard shows.
 *
 * Moved out of the retired article client when marketing articles moved to
 * portaliq (marketing-articles-move-to-portaliq): the preview is a template
 * endpoint and outlives pipelinq's own articles.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * A campaign template's bodies as they will be sent, with the `{{articles}}`
 * marker already expanded.
 *
 * @param {string} templateId The template id.
 * @return {Promise<{subject: string, bodyHtml: string, bodyText: string, articles: Array<object>}>} The preview.
 * @spec openspec/changes/marketing-articles-move-to-portaliq/specs/marketing-ui/spec.md#scenario-the-blast-preview-shows-the-embedded-articles
 */
export async function previewTemplate(templateId) {
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/templates/${templateId}/preview`),
	)
	return data
}

/**
 * How many recipients of an audience get no value for each variable a
 * template uses, counted on the recipients the send would queue.
 *
 * @param {string} templateId The template id.
 * @param {{segmentId?: string, listId?: string}} audience The chosen segment or mailing list.
 * @return {Promise<Array<{variable: string, count: number}>>} The gaps; empty when nothing is missing or no audience is chosen.
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */
export async function emptyVariables(templateId, audience = {}) {
	const segmentId = audience.segmentId || ''
	const listId = audience.listId || ''
	if (!templateId || (!segmentId && !listId)) {
		return []
	}
	const { data } = await axios.get(
		generateUrl(`/apps/pipelinq/api/templates/${templateId}/empty-variables`),
		{ params: { segmentId, listId } },
	)
	return Array.isArray(data?.gaps) ? data.gaps : []
}

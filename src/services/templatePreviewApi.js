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

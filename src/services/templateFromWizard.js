/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Save the content a marketer has in the newsletter wizard as a new template.
 *
 * The wizard's content is the template it uses, so a save reads that
 * template in full and posts its subject, bodies, sender and blocks back
 * under the name the marketer gives. The server validates and stores it
 * like any other new template.
 */

import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The template fields a copy carries over, as `POST /api/templates` takes them. */
const CONTENT_FIELDS = [
	'channel',
	'subject',
	'bodyHtml',
	'bodyText',
	'senderName',
	'senderEmail',
	'replyTo',
	'footerOverride',
	'editorMode',
	'blocks',
	'articleIds',
]

/**
 * The body that creates a new template from the wizard's content.
 *
 * @param {object} template The template the wizard uses, read in full.
 * @param {string} name The name the marketer gives the new template.
 * @return {object} The `POST /api/templates` body: the content fields present, under the trimmed name.
 *
 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-003
 */
export function templateCopyPayload(template, name) {
	const payload = { name: String(name ?? '').trim() }
	for (const field of CONTENT_FIELDS) {
		const value = template?.[field]
		if (value !== undefined && value !== null) {
			payload[field] = value
		}
	}
	return payload
}

/**
 * Store the wizard's content as a new template.
 *
 * @param {object} template The template the wizard uses (at least its id).
 * @param {string} name The name for the new template.
 * @return {Promise<object>} The template the server created.
 * @throws {Error} When the name is empty, with the message the wizard shows.
 *
 * @spec openspec/specs/marketing-mail-templates/spec.md#REQ-MMT-003
 */
export async function saveAsTemplate(template, name) {
	if (String(name ?? '').trim() === '') {
		throw new Error(t('pipelinq', 'Give the template a name'))
	}

	const { data: full } = await axios.get(
		generateUrl('/apps/pipelinq/api/templates/{id}', { id: template.id }),
	)
	const { data } = await axios.post(
		generateUrl('/apps/pipelinq/api/templates'),
		templateCopyPayload({ ...template, ...(full || {}) }, name),
	)
	return data
}

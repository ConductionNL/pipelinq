// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Client for pipelinq's letter endpoints (work-letter-from-filinq-template).
 *
 * The browser talks only to pipelinq. pipelinq reads the records as the user
 * and asks filinq to fill the template; filinq files a copy in the user's
 * Files and hands the PDF back.
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Whether filinq can make a letter, and pipelinq's templates.
 *
 * @return {Promise<{available: boolean, reason?: string, templates: Array<{id: string, name: string, description: string}>}>}
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
export async function fetchLetterTemplates() {
	const { data } = await axios.get(
		generateUrl('/apps/pipelinq/api/letters/templates'),
	)
	return {
		available: data?.available === true,
		reason: data?.reason || '',
		templates: Array.isArray(data?.templates) ? data.templates : [],
	}
}

/**
 * Make a letter for a client.
 *
 * @param {string} clientId The client.
 * @param {{templateId: string, contactId?: string, ticketId?: string}} input The choice.
 * @return {Promise<{filename: string, mimeType: string, content: string, fileId: ?number, path: ?string, warnings: string[], contactMomentId: ?string}>}
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
export async function makeLetter(clientId, input) {
	const body = { templateId: input.templateId }
	if (input.contactId) body.contactId = input.contactId
	if (input.ticketId) body.ticketId = input.ticketId
	const { data } = await axios.post(
		generateUrl(`/apps/pipelinq/api/clients/${encodeURIComponent(clientId)}/letters`),
		body,
	)
	return data
}

/**
 * The PDF bytes of a letter answer, as a Blob.
 *
 * @param {{content: string, mimeType?: string}} letter The answer of makeLetter().
 * @return {Blob}
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
export function letterBlob(letter) {
	const binary = atob(letter.content || '')
	const bytes = new Uint8Array(binary.length)
	for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i)
	return new Blob([bytes], { type: letter.mimeType || 'application/pdf' })
}

/**
 * Hand the letter to the browser as a download.
 *
 * @param {{content: string, mimeType?: string, filename?: string}} letter The answer of makeLetter().
 * @return {void}
 * @spec openspec/specs/client-letters/spec.md#requirement-a-user-makes-a-letter-for-a-client-from-a-filinq-template-req-wlt-001
 */
export function downloadLetter(letter) {
	const url = URL.createObjectURL(letterBlob(letter))
	const link = document.createElement('a')
	link.href = url
	link.download = letter.filename || 'letter.pdf'
	document.body.appendChild(link)
	link.click()
	link.remove()
	setTimeout(() => URL.revokeObjectURL(url), 0)
}

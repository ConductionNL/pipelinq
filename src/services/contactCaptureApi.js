// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Frontend API client for contact capture (Paste or scan).
// Endpoints documented in lib/Controller/ContactCaptureController.php.
// Neither stores anything: they answer a proposal the create form is filled with.
//
// @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Whether photos can be read on this server.
 *
 * @return {Promise<{photo: boolean, maxPhotoBytes: number}>} The status; photos off when it cannot be read.
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#scenario-no-recognition-provider
 */
export async function captureStatus() {
	try {
		const { data } = await axios.get(base('/api/contact-capture/status'))
		return { photo: data?.photo === true, maxPhotoBytes: Number(data?.maxPhotoBytes) || 0 }
	} catch {
		return { photo: false, maxPhotoBytes: 0 }
	}
}

/**
 * Propose contact fields for pasted text.
 *
 * @param {string} text The pasted signature or card text.
 * @return {Promise<object>} The proposal: fields, emails, phones, unassigned.
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-pasted-text-becomes-proposed-contact-fields-req-ccc-001
 */
export async function parseText(text) {
	const { data } = await axios.post(base('/api/contact-capture/parse'), { text })
	return data
}

/**
 * Propose contact fields for the text on a card photo. The server reads the
 * photo and deletes it; the answer also carries the recognised `text`.
 *
 * @param {File} file The photo.
 * @return {Promise<object>} The proposal with the recognised text.
 * @spec openspec/changes/clients-contact-capture/specs/client-contact-capture/spec.md#requirement-a-business-card-photo-is-read-into-the-same-fields-req-ccc-002
 */
export async function parsePhoto(file) {
	const form = new FormData()
	form.append('file', file)
	const { data } = await axios.post(base('/api/contact-capture/parse'), form)
	return data
}

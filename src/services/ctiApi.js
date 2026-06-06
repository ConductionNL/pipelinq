// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin frontend API client for the CTI screen-pop / click-to-dial adapter.
// All endpoints are documented in lib/Controller/CtiController.php.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

export async function screenPop(fromNumber) {
	const { data } = await axios.post(base('/api/cti/screen-pop'), { fromNumber })
	return data
}

export async function clickToDial(targetNumber, extension) {
	const { data } = await axios.post(base('/api/cti/click-to-dial'), {
		targetNumber,
		extension,
	})
	return data
}

export async function submitDisposition(contactmomentId, { subject, outcome, notes }) {
	const { data } = await axios.post(
		base('/api/cti/contactmoment/' + encodeURIComponent(contactmomentId) + '/disposition'),
		{ subject, outcome, notes },
	)
	return data
}

export async function attachRecording(contactmomentId, recordingUrl, expiresAt) {
	const { data } = await axios.post(
		base('/api/cti/contactmoment/' + encodeURIComponent(contactmomentId) + '/recording'),
		{ recordingUrl, expiresAt },
	)
	return data
}

export async function getConfig() {
	const { data } = await axios.get(base('/api/cti/config'))
	return data
}

export async function updateConfig(config) {
	const { data } = await axios.put(base('/api/cti/config'), config)
	return data
}

export async function testConnection() {
	const { data } = await axios.get(base('/api/cti/test-connection'))
	return data
}

export async function getEventLog(filters = {}) {
	const { data } = await axios.get(base('/api/cti/event-log'), { params: filters })
	return data
}

export default {
	screenPop,
	clickToDial,
	submitDisposition,
	attachRecording,
	getConfig,
	updateConfig,
	testConnection,
	getEventLog,
}

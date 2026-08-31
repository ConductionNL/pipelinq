// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Thin frontend API client for the CTI screen-pop / click-to-dial adapter.
// All endpoints are documented in lib/Controller/CtiController.php.

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/pipelinq' + path)

/**
 * Resolve the caller behind an incoming number so the agent's screen can pop
 * the matching client or contact.
 *
 * @param {string} fromNumber The caller's number, as reported by the PBX.
 * @return {Promise<object>} The match result from CtiController.
 */
export async function screenPop(fromNumber) {
	const { data } = await axios.post(base('/api/cti/screen-pop'), { fromNumber })
	return data
}

/**
 * Ask the PBX to place a call from the agent's extension to a number.
 *
 * @param {string} targetNumber The number to dial.
 * @param {string} extension The agent's own extension to originate from.
 * @return {Promise<object>} The dial result from CtiController.
 */
export async function clickToDial(targetNumber, extension) {
	const { data } = await axios.post(base('/api/cti/click-to-dial'), {
		targetNumber,
		extension,
	})
	return data
}

/**
 * Record how a call ended against its contactmoment.
 *
 * @param {string} contactmomentId The contactmoment the call belongs to.
 * @param {object} disposition The wrap-up the agent entered.
 * @param {string} disposition.subject Short subject line for the interaction.
 * @param {string} disposition.outcome Outcome code (resolved, transferred, …).
 * @param {string} disposition.notes Free-text notes.
 * @return {Promise<object>} The saved disposition.
 */
export async function submitDisposition(
	contactmomentId,
	{ subject, outcome, notes },
) {
	const { data } = await axios.post(
		base(
			'/api/cti/contactmoment/'
				+ encodeURIComponent(contactmomentId)
				+ '/disposition',
		),
		{ subject, outcome, notes },
	)
	return data
}

/**
 * Link a call recording to its contactmoment.
 *
 * @param {string} contactmomentId The contactmoment to attach to.
 * @param {string} recordingUrl Location of the recording in the PBX.
 * @param {string} expiresAt ISO-8601 instant the recording is purged, so the
 *   retention rule travels with the link rather than being assumed.
 * @return {Promise<object>} The saved attachment.
 */
export async function attachRecording(contactmomentId, recordingUrl, expiresAt) {
	const { data } = await axios.post(
		base(
			'/api/cti/contactmoment/'
				+ encodeURIComponent(contactmomentId)
				+ '/recording',
		),
		{ recordingUrl, expiresAt },
	)
	return data
}

/**
 * Read the CTI adapter configuration.
 *
 * @return {Promise<object>} The stored configuration.
 */
export async function getConfig() {
	const { data } = await axios.get(base('/api/cti/config'))
	return data
}

/**
 * Replace the CTI adapter configuration.
 *
 * @param {object} config The full configuration to store.
 * @return {Promise<object>} The stored configuration.
 */
export async function updateConfig(config) {
	const { data } = await axios.put(base('/api/cti/config'), config)
	return data
}

/**
 * Probe the configured PBX and report whether it answers.
 *
 * @return {Promise<object>} The probe result.
 */
export async function testConnection() {
	const { data } = await axios.get(base('/api/cti/test-connection'))
	return data
}

/**
 * Read the CTI event log.
 *
 * @param {object} [filters] Query filters forwarded as request params.
 * @return {Promise<object>} The matching log entries.
 */
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

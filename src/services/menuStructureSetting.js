// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { MODULES_SETTING } from '../utils/menuModules.js'
import {
	resolveStructureProfile,
	STRUCTURE_SETTING,
} from '../utils/structureProfile.js'

/**
 * Save which structure the app shows, and which modules join the simple menu.
 *
 * It goes through pipelinq's own settings write, which carries
 * `#[AuthorizedAdminSetting]`, and it names the keys from the two utility
 * modules so the admin section, the boot code and the PHP side cannot spell
 * them three ways.
 *
 * `fetch` resolves on a 403 as readily as on a 200, so the status is checked
 * here: a save an administrator was not allowed to make must not read as done.
 *
 * @param {{ structure: string, modules: Array<string> }} wanted What to store.
 * @param {{ url: string, requestToken: string, fetchImpl?: typeof fetch }} options
 *   Where to send it, the CSRF token, and the fetch to use (a test passes its own).
 * @return {Promise<{ structure: string, modules: Array<string> }>} What the server stored.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
 */
export async function saveMenuStructure(
	{ structure, modules },
	{ url, requestToken, fetchImpl },
) {
	const send = fetchImpl || fetch
	const wanted = {
		[STRUCTURE_SETTING]: resolveStructureProfile(structure),
		[MODULES_SETTING]: (Array.isArray(modules) ? modules : []).join(','),
	}
	const response = await putSettings({ url, requestToken, send }, wanted)
	await assertStored(response, wanted)
	const wantedModules = wanted[MODULES_SETTING]
	return {
		structure: wanted[STRUCTURE_SETTING],
		modules: wantedModules === '' ? [] : wantedModules.split(','),
	}
}

/**
 * Write the settings in one PUT.
 *
 * @param {{url: string, requestToken: string, send: typeof fetch}} request Where and how to write.
 * @param {{[key: string]: string}} wanted The settings to write.
 * @return {Promise<Response>} The answer.
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
 */
function putSettings(request, wanted) {
	// Called unbound: window.fetch refuses to run as another object's method.
	const send = request.send
	return send(request.url, {
		method: 'PUT',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: request.requestToken,
		},
		body: JSON.stringify(wanted),
	})
}

/**
 * Refuse a write that failed, or that the endpoint answered as a success
 * without storing both settings.
 *
 * @param {Response} response The write's answer.
 * @param {{[key: string]: string}} wanted The settings written.
 * @return {Promise<void>} Resolves when both settings are stored.
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-101
 */
async function assertStored(response, wanted) {
	if (!response.ok) {
		throw new Error(`settings write answered ${response.status}`)
	}
	const body = await response.json()
	const stored = body?.config || {}
	if (
		stored[STRUCTURE_SETTING] !== wanted[STRUCTURE_SETTING]
		|| stored[MODULES_SETTING] !== wanted[MODULES_SETTING]
	) {
		// The endpoint answers success for a key it does not know. Reading the
		// stored values back is the only way to tell a save from a no-op.
		throw new Error('settings write did not store the menu structure')
	}
}

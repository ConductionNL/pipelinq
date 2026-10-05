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
	const wantedStructure = resolveStructureProfile(structure)
	const wantedModules = (Array.isArray(modules) ? modules : []).join(',')
	const response = await send(url, {
		method: 'PUT',
		headers: {
			'Content-Type': 'application/json',
			requesttoken: requestToken,
		},
		body: JSON.stringify({
			[STRUCTURE_SETTING]: wantedStructure,
			[MODULES_SETTING]: wantedModules,
		}),
	})
	if (!response.ok) {
		throw new Error(`settings write answered ${response.status}`)
	}
	const body = await response.json()
	const stored = body?.config || {}
	if (
		stored[STRUCTURE_SETTING] !== wantedStructure
		|| stored[MODULES_SETTING] !== wantedModules
	) {
		// The endpoint answers success for a key it does not know. Reading the
		// stored values back is the only way to tell a save from a no-op.
		throw new Error('settings write did not store the menu structure')
	}
	return {
		structure: wantedStructure,
		modules: wantedModules === '' ? [] : wantedModules.split(','),
	}
}

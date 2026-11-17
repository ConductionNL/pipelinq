/**
 * The add-in's sign-in, kept in Outlook's roaming settings
 * (contact-moments-mail-add-in, D2).
 *
 * The pane holds a Nextcloud app password, never the account password.
 * Revoking that app password in Nextcloud's security settings signs the
 * add-in out: the next call answers 401 and the pane forgets it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-panes-calls-carry-their-own-credentials-req-mai-004
 */

/** The roaming-settings key the sign-in is stored under. */
export const SETTINGS_KEY = 'pipelinqSignIn'

/**
 * The stored sign-in, or null when the colleague has not signed in.
 *
 * @param {object} settings Office.js `roamingSettings`.
 * @return {{loginName: string, appPassword: string}|null}
 */
export function loadCredentials(settings) {
	const stored = settings?.get?.(SETTINGS_KEY)
	if (!stored || typeof stored.loginName !== 'string' || typeof stored.appPassword !== 'string'
		|| stored.loginName === '' || stored.appPassword === '') {
		return null
	}
	return { loginName: stored.loginName, appPassword: stored.appPassword }
}

/**
 * Persist a value (or a removal) in the roaming settings.
 *
 * @param {object} settings Office.js `roamingSettings`.
 * @return {Promise<void>}
 */
function persist(settings) {
	return new Promise((resolve, reject) => {
		settings.saveAsync((result) => {
			if (result?.status === 'failed') {
				reject(new Error(result.error?.message ?? 'roaming settings not saved'))
				return
			}
			resolve()
		})
	})
}

/**
 * Store a sign-in.
 *
 * @param {object} settings Office.js `roamingSettings`.
 * @param {{loginName: string, appPassword: string}} credentials The login flow's result.
 * @return {Promise<void>}
 */
export function saveCredentials(settings, credentials) {
	settings.set(SETTINGS_KEY, { loginName: credentials.loginName, appPassword: credentials.appPassword })
	return persist(settings)
}

/**
 * Forget the sign-in.
 *
 * @param {object} settings Office.js `roamingSettings`.
 * @return {Promise<void>}
 */
export function clearCredentials(settings) {
	settings.remove(SETTINGS_KEY)
	return persist(settings)
}

/**
 * The Basic `Authorization` header for a sign-in, UTF-8 safe.
 *
 * @param {{loginName: string, appPassword: string}} credentials The sign-in.
 * @return {string}
 */
export function authorizationHeader(credentials) {
	const bytes = new TextEncoder().encode(`${credentials.loginName}:${credentials.appPassword}`)
	let binary = ''
	for (const byte of bytes) {
		binary += String.fromCharCode(byte)
	}
	return 'Basic ' + btoa(binary)
}

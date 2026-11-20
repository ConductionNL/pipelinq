/**
 * Sign in through Nextcloud Login Flow v2 (contact-moments-mail-add-in, D2).
 *
 * The flow opens Nextcloud's own login page in a dialog; the colleague signs
 * in there (with their own two-factor, SSO, ...) and grants access. The pane
 * polls until Nextcloud hands it an app password, which then shows in the
 * colleague's security settings where they can revoke it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
 */

/** How long to wait between two polls, in ms. */
export const POLL_INTERVAL = 2000

/** How many polls before giving up (Nextcloud keeps a flow for 20 minutes). */
export const POLL_ATTEMPTS = 300

/**
 * Open the login page in an Office dialog.
 *
 * @param {object} office The Office.js global.
 * @param {string} url    The login page.
 * @return {() => void} Closes the dialog.
 */
export function openOfficeDialog(office, url) {
	let dialog = null
	office.context.ui.displayDialogAsync(url, { height: 70, width: 40 }, (result) => {
		if (result?.status === 'succeeded') {
			dialog = result.value
		}
	})
	return () => {
		try {
			dialog?.close()
		} catch {
			// The colleague closed it already.
		}
	}
}

/**
 * Run the login flow to its app password.
 *
 * @param {object}   options              The dependencies.
 * @param {typeof fetch} options.fetch        `fetch`.
 * @param {string}   options.loginFlowUrl Nextcloud's `/login/v2`.
 * @param {(url: string) => (() => void)} options.openLogin    Opens the login page; returns a closer.
 * @param {(ms: number) => Promise<void>} options.wait         Resolves after the given ms.
 * @param {number}   [options.attempts]   Polls before giving up.
 * @return {Promise<{loginName: string, appPassword: string}>}
 */
export async function signIn({ fetch, loginFlowUrl, openLogin, wait, attempts = POLL_ATTEMPTS }) {
	const started = await fetch(loginFlowUrl, { method: 'POST', headers: { Accept: 'application/json' } })
	if (!started.ok) {
		throw new Error(`login flow did not start (${started.status})`)
	}
	const { poll, login } = await started.json()
	if (!poll?.endpoint || !poll?.token || !login) {
		throw new Error('login flow answered without a poll endpoint')
	}

	const closeLogin = openLogin(login)
	try {
		for (let attempt = 0; attempt < attempts; attempt++) {
			await wait(POLL_INTERVAL)
			const answer = await fetch(poll.endpoint, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
				body: 'token=' + encodeURIComponent(poll.token),
			})
			if (answer.status === 200) {
				const granted = await answer.json()
				if (!granted?.loginName || !granted?.appPassword) {
					throw new Error('login flow answered without an app password')
				}
				return { loginName: granted.loginName, appPassword: granted.appPassword }
			}
			if (answer.status !== 404) {
				throw new Error(`login flow poll failed (${answer.status})`)
			}
		}
		throw new Error('login flow timed out')
	} finally {
		closeLogin?.()
	}
}

/**
 * The pane's three calls (contact-moments-mail-add-in, D3).
 *
 * Every call carries the app password in an `Authorization` header: the
 * routes skip the CSRF token and refuse a call without its own credentials.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-the-panes-calls-carry-their-own-credentials-req-mai-004
 */

import { authorizationHeader } from './credentials.js'

/** Thrown when Nextcloud no longer accepts the stored app password. */
export class SignedOutError extends Error {

	constructor() {
		super('signed out')
		this.name = 'SignedOutError'
	}

}

/**
 * The calls, bound to one sign-in.
 *
 * @param {object}   options             The dependencies.
 * @param {typeof fetch} options.fetch       `fetch`.
 * @param {object}   options.urls        `lookupUrl`, `contactUrl`, `logUrl`.
 * @param {object}   options.credentials The sign-in.
 * @return {{lookup: (email: string) => Promise<object>, addContact: (form: object) => Promise<object>, logMail: (mail: object) => Promise<object>}}
 */
export function createApi({ fetch, urls, credentials }) {
	const authorization = authorizationHeader(credentials)

	/**
	 * POST JSON and return the parsed answer.
	 *
	 * @param {string} url  The route.
	 * @param {object} body The payload.
	 * @return {Promise<object>}
	 */
	async function post(url, body) {
		const response = await fetch(url, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				Accept: 'application/json',
				Authorization: authorization,
			},
			body: JSON.stringify(body),
		})
		if (response.status === 401) {
			throw new SignedOutError()
		}
		let data
		try {
			data = await response.json()
		} catch {
			data = {}
		}
		if (!response.ok) {
			const error = new Error(data?.error ?? `HTTP ${response.status}`)
			error.status = response.status
			throw error
		}
		return data
	}

	return {
		lookup: (email) => post(urls.lookupUrl, { email }),
		addContact: ({ name, email, client = '' }) => post(urls.contactUrl, { name, email, client }),
		logMail: ({ entityType, entityId, subject, sentAt, preview }) => post(urls.logUrl, { entityType, entityId, subject, sentAt, preview }),
	}
}

/**
 * The id of an object OpenRegister returned.
 *
 * @param {object} object The object.
 * @return {string}
 */
export function idOf(object) {
	return String(object?.uuid ?? object?.id ?? object?.['@self']?.id ?? '')
}

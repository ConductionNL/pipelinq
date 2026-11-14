// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The headers of a JSON request to a Nextcloud app endpoint: the JSON content
 * type, the CSRF request token, and the OCS API marker. Built per request, so
 * the token is the one the page holds at that moment.
 *
 * @return {object} The headers.
 * @spec exclude request plumbing shared by every fetch-based caller, no behaviour of its own.
 */
export function jsonRequestHeaders() {
	return {
		'Content-Type': 'application/json',
		requesttoken: OC.requestToken,
		'OCS-APIREQUEST': 'true',
	}
}

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Integrations page's two formatters and its Add integration handler.
 *
 * The rows on that page are integriq's `app_connection` objects (hydra change
 * connection-registry, design D8). Each adopting app carries these seven lines
 * until nextcloud-vue ships the formatters as built-ins in a version this app
 * installs. nextcloud-vue#1163 made them built-ins after 2.53.1 was released,
 * and this app pins ^2.37.0, so the copy stays. The names are the contract's,
 * so the copies stay interchangeable.
 *
 * Pure: the translator, the URL builder and the navigation are passed in, so
 * the module runs under vitest's node environment with nothing mocked.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
 */

/**
 * Where Add integration lands: integriq's Connections overview, preset to this
 * app and opening the link-a-source dialog (hydra connection-registry D9).
 */
export const INTEGRIQ_CONNECTIONS_PATH = '/apps/integriq/connections?app=pipelinq&link=1'

/**
 * The English label for each of the six registry statuses.
 *
 * `limited` came with hydra#673: the connection works in part, such as a
 * preview social network that connects and may refuse a post.
 */
export const CONNECTION_STATUS_LABELS = Object.freeze({
	configured: 'Configured',
	limited: 'Limited',
	unconfigured: 'Not configured',
	simulated: 'Simulated',
	unavailable: 'Not available',
	error: 'Error',
})

/**
 * Build the two connection formatters around a translator.
 *
 * @param {function(string): string} translate Translates an English source string for this app.
 * @return {{connectionStatus: function(unknown): string, connectionSettingsLabel: function(unknown): string}} The formatters, keyed by their manifest names.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
 */
export function createConnectionFormatters(translate) {
	return {
		/**
		 * The label for a status. An unknown value renders itself, because a
		 * status the app cannot name is still a status the admin should see.
		 *
		 * @param {unknown} value The row's `status`.
		 * @return {string} The label, the raw value, or '' when missing.
		 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
		 */
		connectionStatus(value) {
			const source = typeof value === 'string' && Object.hasOwn(CONNECTION_STATUS_LABELS, value)
				? CONNECTION_STATUS_LABELS[value]
				: null
			return source ? translate(source) : String(value ?? '')
		},

		/**
		 * The Open settings link text, or '' when the row has nowhere to send a
		 * reader. An empty text makes the link cell fall through to plain text.
		 *
		 * @param {unknown} value The row's `settingsUrl`.
		 * @return {string} The link text, or ''.
		 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
		 */
		connectionSettingsLabel(value) {
			return typeof value === 'string' && value.length > 0 ? translate('Open settings') : ''
		},
	}
}

/**
 * Build the Add integration header-action handler.
 *
 * A FUNCTION handler because a header action's `navigate` keyword only pushes
 * a route inside this app's router, which cannot leave the app.
 *
 * @param {{generateUrl: function(string): string, assign: function(string): void}} deps Builds the instance URL and navigates to it.
 * @return {{openIntegriqConnections: function(): void}} The handler, keyed by its manifest name.
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
 */
export function createConnectionHandlers({ generateUrl, assign }) {
	return {
		/**
		 * Leave for integriq's Connections overview, preset and linking.
		 *
		 * @return {void}
		 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
		 */
		openIntegriqConnections() {
			assign(generateUrl(INTEGRIQ_CONNECTIONS_PATH))
		},
	}
}

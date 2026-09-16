// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Integrations page's Add integration handler.
 *
 * The rows on that page are integriq's `app_connection` objects (hydra change
 * connection-registry, design D8). The page's two formatters,
 * `connectionStatus` and `connectionSettingsLabel`, are built-ins of
 * @conduction/nextcloud-vue since 3.2.0, so this app no longer carries a copy.
 *
 * Pure: the URL builder and the navigation are passed in, so the module runs
 * under vitest's node environment with nothing mocked.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
 */

/**
 * Where Add integration lands: integriq's Connections overview, preset to this
 * app and opening the link-a-source dialog (hydra connection-registry D9).
 */
export const INTEGRIQ_CONNECTIONS_PATH =
	'/apps/integriq/connections?app=pipelinq&link=1'

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

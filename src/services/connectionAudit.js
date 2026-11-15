// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The connection audit page (board PqVerbindingscontrole): the verdict
 * formatter its two follow columns use, and its Check again handler.
 *
 * Pure: the URL builder, the HTTP client, the toasts and the translate
 * function are passed in, so the module runs under vitest's node environment
 * with nothing mocked.
 *
 * @spec openspec/changes/advanced-hub-and-connection-audit-board/specs/board-look/spec.md#requirement-the-connection-audit-reads-as-its-board
 */

import { translate as t } from '@nextcloud/l10n'

/** The endpoint that re-runs the audit and returns the stored rows. */
export const CONNECTION_AUDIT_PATH = '/apps/pipelinq/api/marketing/connection-audit'

/**
 * A follow verdict as the words the page shows. Only `yes` and `no` are
 * answers; anything else, an empty value included, is the network not
 * saying, which is never printed as a no.
 *
 * @param {unknown} value The stored verdict: yes, no or unknown.
 * @return {string} Yes, No or Cannot be checked.
 * @spec openspec/changes/advanced-hub-and-connection-audit-board/specs/board-look/spec.md#requirement-the-connection-audit-reads-as-its-board
 */
export function auditVerdict(value) {
	if (value === 'yes') {
		return t('pipelinq', 'Yes')
	}
	if (value === 'no') {
		return t('pipelinq', 'No')
	}
	return t('pipelinq', 'Cannot be checked')
}

/**
 * Build the Check again header-action handler.
 *
 * @param {{generateUrl: function(string): string, http: {get: Function}, showSuccess: function(string): void, showError: function(string): void, translate: Function}} deps What the handler needs.
 * @return {{rerunConnectionAudit: function(): Promise<boolean>}} The handler, keyed by its manifest name.
 * @spec openspec/changes/advanced-hub-and-connection-audit-board/specs/board-look/spec.md#requirement-the-connection-audit-reads-as-its-board
 */
export function createConnectionAuditHandlers({
	generateUrl,
	http,
	showSuccess,
	showError,
	translate,
}) {
	return {
		/**
		 * Re-run the audit against the networks that will answer. The rows
		 * it stores reach the list through the collection's live update.
		 *
		 * @return {Promise<boolean>} Whether the audit ran.
		 * @spec openspec/changes/advanced-hub-and-connection-audit-board/specs/board-look/spec.md#requirement-the-connection-audit-reads-as-its-board
		 */
		rerunConnectionAudit: async () => {
			try {
				await http.get(generateUrl(CONNECTION_AUDIT_PATH), {
					params: { refresh: 'true' },
				})
			} catch (error) {
				showError(
					error?.response?.data?.error
						|| translate(
							'pipelinq',
							'Could not load the connection audit.',
						),
				)
				return false
			}
			showSuccess(translate('pipelinq', 'The connection audit ran again.'))
			return true
		},
	}
}

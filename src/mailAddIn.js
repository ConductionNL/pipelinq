/**
 * Outlook add-in task pane entrypoint (contact-moments-mail-add-in).
 *
 * Served by MailAddInController::taskpane() inside Outlook, outside the
 * Nextcloud shell. Waits for Office.js, then mounts the pane.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md#requirement-pipelinq-serves-an-outlook-add-in-req-mai-001
 */

import { loadState } from '@nextcloud/initial-state'
import { translate as t } from '@nextcloud/l10n'
import { createPane } from './mail-add-in/pane.js'

import './mail-add-in/pane.css'

const root = document.getElementById('pipelinq-mail-add-in')
const urls = loadState('pipelinq', 'mail-add-in', {})

if (root && window.Office && typeof window.Office.onReady === 'function') {
	window.Office.onReady(() => {
		createPane({ root, office: window.Office, fetch: window.fetch.bind(window), urls }).start()
	})
} else if (root) {
	const message = document.createElement('p')
	message.textContent = t('pipelinq', 'Open this page from Outlook, as the Pipelinq add-in.')
	root.replaceChildren(message)
}

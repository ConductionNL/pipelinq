/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Entry of the website chat window (webpack entry `webChatWindow`).
 *
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.2
 */
import { loadState } from '@nextcloud/initial-state'
import { getLanguage, translate } from '@nextcloud/l10n'
import { createChatWindow, windowTexts } from './chatWindow.js'

const root = document.getElementById('pipelinq-web-chat')
if (root !== null) {
	createChatWindow(root, loadState('pipelinq', 'web-chat', { available: false }), {
		fetch: window.fetch.bind(window),
		storage: window.sessionStorage,
		texts: windowTexts((text) => translate('pipelinq', text)),
		locale: getLanguage(),
		setInterval: window.setInterval.bind(window),
		clearInterval: window.clearInterval.bind(window),
	})
}

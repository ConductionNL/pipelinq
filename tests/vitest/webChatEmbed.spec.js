// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The line a website pastes for the chat (requests-web-chat-and-bot task 2.2).
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md#requirement-an-administrator-puts-a-chat-on-the-website-req-wch-001
 */
import { describe, expect, it } from 'vitest'
import { chatUrlOf, mountChatButton } from '../../src/webChat/embed.js'

describe('the web chat embed', () => {
	it('reads the window url from its own src, and only an http(s) one', () => {
		const win = 'https://cloud.example.org/index.php/apps/pipelinq/chat/abcdefghijklmnop1234'
		expect(chatUrlOf('https://cloud.example.org/apps/pipelinq/js/pipelinq-web-chat.js?chat=' + encodeURIComponent(win))).toBe(win)
		expect(chatUrlOf('https://cloud.example.org/apps/pipelinq/js/pipelinq-web-chat.js?chat=' + encodeURIComponent('javascript:alert(1)'))).toBeNull()
		expect(chatUrlOf('https://cloud.example.org/apps/pipelinq/js/pipelinq-web-chat.js')).toBeNull()
		expect(chatUrlOf('not a url')).toBeNull()
	})

	it('puts a chat button on the page that opens the window in a frame, loading it on first open', () => {
		const { button, frame } = mountChatButton(document, 'https://cloud.example.org/chat/x', 'Chat met ons')

		expect(button.textContent).toBe('Chat met ons')
		expect(frame.hidden).toBe(true)
		expect(frame.getAttribute('src')).toBeNull()
		button.click()
		expect(frame.hidden).toBe(false)
		expect(frame.src).toBe('https://cloud.example.org/chat/x')
		expect(button.getAttribute('aria-expanded')).toBe('true')
		button.click()
		expect(frame.hidden).toBe(true)
		expect(button.getAttribute('aria-expanded')).toBe('false')
	})
})

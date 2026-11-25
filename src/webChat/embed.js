/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The line a website pastes (webpack entry `webChat`, js/pipelinq-web-chat.js):
 *
 *   <script src="https://cloud.example.org/apps/pipelinq/js/pipelinq-web-chat.js?chat=<window url>" async></script>
 *
 * Adds a chat button to the host page and opens the chat window in a frame.
 * The window decides what it shows; only the channel's own websites may frame it.
 *
 * @spec openspec/changes/archive/2026-11-25-requests-web-chat-and-bot/tasks.md#task-2.2
 */

/**
 * The chat window url the embed line names, or null when it names none or not an http(s) one.
 *
 * @param {string} scriptSrc The script's own src.
 * @return {string|null}
 */
export function chatUrlOf(scriptSrc) {
	let url
	try {
		url = new URL(scriptSrc)
	} catch {
		return null
	}
	const chat = url.searchParams.get('chat') || ''
	try {
		const target = new URL(chat)
		return /^https?:$/.test(target.protocol) ? target.href : null
	} catch {
		return null
	}
}

/**
 * Put the chat button and its frame on the page.
 *
 * @param {Document} doc The host page.
 * @param {string} chatUrl The chat window url.
 * @param {string} label The button's label.
 * @return {{button: HTMLButtonElement, frame: HTMLIFrameElement}}
 */
export function mountChatButton(doc, chatUrl, label) {
	const button = doc.createElement('button')
	button.type = 'button'
	button.textContent = label
	button.setAttribute('aria-expanded', 'false')
	button.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483000;padding:12px 18px;border:0;border-radius:24px;background:var(--color-primary-element,#00679e);color:var(--color-primary-element-text,#fff);font:inherit;cursor:pointer'
	const frame = doc.createElement('iframe')
	frame.title = label
	frame.hidden = true
	frame.style.cssText = 'position:fixed;right:16px;bottom:72px;z-index:2147483000;width:min(380px,calc(100vw - 32px));height:min(560px,calc(100vh - 96px));border:1px solid var(--color-border,#ccc);border-radius:12px;background:var(--color-main-background,#fff)'
	button.addEventListener('click', () => {
		if (frame.src === '') {
			frame.src = chatUrl
		}
		frame.hidden = !frame.hidden
		button.setAttribute('aria-expanded', frame.hidden ? 'false' : 'true')
	})
	doc.body.append(frame, button)
	return { button, frame }
}

const script = typeof document !== 'undefined' ? document.currentScript : null
if (script !== null && script.src) {
	const chatUrl = chatUrlOf(script.src)
	if (chatUrl !== null) {
		const lang = (document.documentElement.lang || 'nl').slice(0, 2).toLowerCase()
		mountChatButton(document, chatUrl, lang === 'en' ? 'Chat with us' : 'Chat met ons')
	}
}

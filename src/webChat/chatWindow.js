/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The website chat window (requests-web-chat-and-bot D3, D4, D6, D7).
 *
 * Plain DOM, no framework: the window runs in a frame on somebody else's
 * website and should load fast. It keeps the visitor's chat secret in the
 * frame's sessionStorage only, so closing the tab ends the chat, and sets no
 * cookie. It polls every three seconds while the chat is open.
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */

export const POLL_MS = 3000

/**
 * The texts of the window, translated by the caller.
 *
 * @param {(text: string) => string} t The translate function.
 * @return {object} The texts.
 */
export function windowTexts(t) {
	return {
		title: t('Chat'),
		placeholder: t('Type your question'),
		send: t('Send'),
		person: t('Talk to a person'),
		you: t('You'),
		assistant: t('Assistant'),
		colleague: t('Colleague'),
		sources: t('Sources'),
		noBsn: t('Do not type your citizen service number (BSN) in this chat.'),
		waiting: t('A colleague will be with you shortly.'),
		withAgent: t('A colleague is answering.'),
		offline: t('Nobody is available right now. Leave your question and email address and we will answer by email.'),
		email: t('Email address'),
		leave: t('Leave a message'),
		left: t('Thank you. We will answer by email.'),
		closed: t('This chat has ended.'),
		unavailable: t('The chat is not available right now.'),
	}
}

/**
 * Make an element with a class and optional text.
 *
 * @param {Document} doc The document.
 * @param {string} tag The tag.
 * @param {string} className The class.
 * @param {string} [text] The text.
 * @return {HTMLElement} The element.
 */
function el(doc, tag, className, text) {
	const node = doc.createElement(tag)
	node.className = className
	if (text !== undefined) {
		node.textContent = text
	}
	return node
}

/**
 * Build and run the chat window in a root element.
 *
 * @param {HTMLElement} root The element to mount in.
 * @param {object} config `{ available, name, startUrl, sendUrl, pollUrl }` from the initial state.
 * @param {object} deps `{ fetch, storage, texts, locale, setInterval, clearInterval }`.
 * @return {object} Handles for tests: `{ ready, render, poll, stop, state }`.
 */
export function createChatWindow(root, config, deps) {
	const doc = root.ownerDocument
	const texts = deps.texts
	const storageKey = 'pipelinq-web-chat:' + config.startUrl
	const state = { chat: null, messages: [], since: '', mode: 'person', status: '', disclosureShown: false, offlineText: '', timer: null }

	root.textContent = ''
	const header = el(doc, 'h1', 'pipelinq-web-chat__title', config.name || texts.title)
	const notice = el(doc, 'p', 'pipelinq-web-chat__notice', texts.noBsn)
	const log = el(doc, 'ol', 'pipelinq-web-chat__log')
	log.setAttribute('role', 'log')
	log.setAttribute('aria-live', 'polite')
	const status = el(doc, 'p', 'pipelinq-web-chat__status')
	status.setAttribute('role', 'status')
	const form = el(doc, 'form', 'pipelinq-web-chat__form')
	const label = el(doc, 'label', 'pipelinq-web-chat__label', texts.placeholder)
	const input = el(doc, 'textarea', 'pipelinq-web-chat__input')
	input.id = 'pipelinq-web-chat-input'
	input.rows = 2
	input.maxLength = 2000
	label.htmlFor = input.id
	const emailLabel = el(doc, 'label', 'pipelinq-web-chat__label', texts.email)
	const email = el(doc, 'input', 'pipelinq-web-chat__email')
	email.id = 'pipelinq-web-chat-email'
	email.type = 'email'
	email.autocomplete = 'email'
	emailLabel.htmlFor = email.id
	const sendButton = el(doc, 'button', 'pipelinq-web-chat__send', texts.send)
	sendButton.type = 'submit'
	const personButton = el(doc, 'button', 'pipelinq-web-chat__person', texts.person)
	personButton.type = 'button'
	form.append(label, input, emailLabel, email, sendButton, personButton)
	root.append(header, notice, log, status, form)

	/**
	 * POST a JSON body to a route; answers the parsed body and the status.
	 *
	 * @param {string} url The route.
	 * @param {object} body The body.
	 * @return {Promise<{ok: boolean, status: number, body: object}>}
	 */
	async function post(url, body) {
		try {
			const response = await deps.fetch(url, {
				method: 'POST',
				credentials: 'omit',
				headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
				body: JSON.stringify(body),
			})
			let parsed = {}
			try {
				parsed = await response.json()
			} catch {
				parsed = {}
			}
			return { ok: response.ok, status: response.status, body: parsed }
		} catch {
			return { ok: false, status: 0, body: {} }
		}
	}

	/**
	 * Show where the chat stands, and the fields that state asks for.
	 *
	 * @param {string} chatState The state the server gave.
	 * @return {void}
	 */
	function showState(chatState) {
		state.status = chatState
		const offline = chatState === 'offline'
		emailLabel.hidden = !offline
		email.hidden = !offline
		personButton.hidden = !(chatState === 'assistant')
		const closed = chatState === 'closed' || chatState === 'left'
		input.disabled = closed
		sendButton.disabled = closed
		sendButton.textContent = offline ? texts.leave : texts.send
		const line = {
			waiting: texts.waiting,
			'with-agent': texts.withAgent,
			offline: state.offlineText || texts.offline,
			closed: texts.closed,
			left: texts.left,
			unavailable: texts.unavailable,
		}[chatState]
		status.textContent = line || ''
		if (closed) {
			stop()
		}
	}

	/**
	 * Render one message into the log.
	 *
	 * @param {object} message The message: `{ id, author, body, at, sources?, disclosure?, agentName? }`.
	 * @return {void}
	 */
	function render(message) {
		if (state.messages.some((known) => known.id === message.id)) {
			return
		}
		state.messages.push(message)
		if (message.at > state.since) {
			state.since = message.at
		}
		if (message.author === 'assistant' && !state.disclosureShown && message.disclosure) {
			log.append(el(doc, 'li', 'pipelinq-web-chat__disclosure', message.disclosure))
			state.disclosureShown = true
		}
		const item = el(doc, 'li', 'pipelinq-web-chat__message pipelinq-web-chat__message--' + message.author)
		const who = { visitor: texts.you, assistant: texts.assistant, agent: message.agentName || texts.colleague }[message.author] || texts.colleague
		item.append(el(doc, 'span', 'pipelinq-web-chat__author', who), el(doc, 'p', 'pipelinq-web-chat__body', message.body))
		const sources = Array.isArray(message.sources) ? message.sources.filter((s) => /^https?:\/\//i.test(s.url || '')) : []
		if (sources.length > 0) {
			const list = el(doc, 'ul', 'pipelinq-web-chat__sources')
			list.setAttribute('aria-label', texts.sources)
			for (const source of sources) {
				const link = el(doc, 'a', 'pipelinq-web-chat__source', source.title || source.url)
				link.href = source.url
				link.target = '_blank'
				link.rel = 'noopener noreferrer'
				const li = el(doc, 'li', '')
				li.append(link)
				list.append(li)
			}
			item.append(list)
		}
		log.append(item)
	}

	/**
	 * Take in the server's view of the chat.
	 *
	 * @param {object} view `{ state, messages, offlineText }`.
	 * @return {void}
	 */
	function apply(view) {
		if (typeof view.offlineText === 'string' && view.offlineText !== '') {
			state.offlineText = view.offlineText
		}
		for (const message of view.messages || []) {
			render(message)
		}
		if (view.state) {
			showState(view.state)
		}
	}

	/**
	 * Ask the server for what is new.
	 *
	 * @return {Promise<void>}
	 */
	async function poll() {
		if (state.chat === null) {
			return
		}
		const answer = await post(config.pollUrl, { ...state.chat, since: state.since })
		if (answer.ok) {
			apply(answer.body)
		} else if (answer.status === 403 || answer.status === 404) {
			deps.storage.removeItem(storageKey)
			showState('closed')
		}
	}

	/**
	 * Stop polling.
	 *
	 * @return {void}
	 */
	function stop() {
		if (state.timer !== null) {
			deps.clearInterval(state.timer)
			state.timer = null
		}
	}

	/**
	 * Send what the visitor typed, or ask for a person.
	 *
	 * @param {boolean} handover Whether the visitor asks for a person.
	 * @return {Promise<void>}
	 */
	async function send(handover) {
		const text = input.value.trim()
		const address = email.hidden ? '' : email.value.trim()
		if (text === '' && address === '' && !handover) {
			return
		}
		const answer = await post(config.sendUrl, { ...state.chat, text, email: address, handover, locale: deps.locale })
		if (!answer.ok) {
			status.textContent = answer.status === 400 ? (answer.body.error || '') : texts.unavailable
			return
		}
		input.value = ''
		apply(answer.body)
		if (address !== '') {
			showState('left')
		}
	}

	form.addEventListener('submit', (event) => {
		event.preventDefault()
		send(false)
	})
	personButton.addEventListener('click', () => send(true))

	/**
	 * Resume the chat this tab holds, or start one.
	 *
	 * @return {Promise<void>}
	 */
	async function begin() {
		if (!config.available) {
			showState('unavailable')
			form.hidden = true
			return
		}
		let held
		try {
			held = JSON.parse(deps.storage.getItem(storageKey) || 'null')
		} catch {
			held = null
		}
		if (held && held.conversation && held.secret) {
			state.chat = held
			await poll()
		}
		if (state.chat === null || state.status === 'closed') {
			const started = await post(config.startUrl, {})
			if (!started.ok) {
				showState('unavailable')
				return
			}
			state.chat = { conversation: started.body.conversation, secret: started.body.secret }
			state.offlineText = started.body.offlineText || ''
			deps.storage.setItem(storageKey, JSON.stringify(state.chat))
			if (started.body.greeting) {
				log.append(el(doc, 'li', 'pipelinq-web-chat__greeting', started.body.greeting))
			}
			showState(started.body.mode)
		}
		state.timer = deps.setInterval(poll, POLL_MS)
	}

	return { ready: begin(), render, poll, stop, state, elements: { log, status, input, email, form, personButton, sendButton } }
}

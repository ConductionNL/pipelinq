// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The website chat window (requests-web-chat-and-bot tasks 3.3 and 4.2).
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md
 */
import { describe, expect, it, vi } from 'vitest'
import { createChatWindow, windowTexts } from '../../src/webChat/chatWindow.js'

const config = { available: true, name: 'Website', startUrl: '/start', sendUrl: '/send', pollUrl: '/poll' }

/**
 * A fake fetch answering each route with the next queued body.
 *
 * @param {object} answers Per route, a list of `{ status, body }`.
 * @return {Function} The fetch, with `.calls`.
 */
function fakeFetch(answers) {
	const calls = []
	const fetch = vi.fn(async (url, init) => {
		calls.push({ url, body: JSON.parse(init.body), credentials: init.credentials })
		const answer = (answers[url] || []).shift() || { status: 200, body: { state: 'assistant', messages: [] } }
		return { ok: answer.status < 400, status: answer.status, json: async () => answer.body }
	})
	fetch.calls = calls
	return fetch
}

/**
 * A storage over a map.
 *
 * @return {object}
 */
function fakeStorage() {
	const map = new Map()
	return { getItem: (k) => (map.has(k) ? map.get(k) : null), setItem: (k, v) => map.set(k, v), removeItem: (k) => map.delete(k), map }
}

/**
 * Mount the window.
 *
 * @param {object} answers The fetch answers.
 * @param {object} [storage] The storage.
 * @return {Promise<object>} The window, its root, fetch and storage.
 */
async function mount(answers, storage = fakeStorage()) {
	const root = document.createElement('main')
	document.body.append(root)
	const fetch = fakeFetch(answers)
	const chat = createChatWindow(root, config, {
		fetch,
		storage,
		texts: windowTexts((text) => text),
		locale: 'nl',
		setInterval: vi.fn(() => 7),
		clearInterval: vi.fn(),
	})
	await chat.ready
	return { chat, root, fetch, storage }
}

describe('the website chat window', () => {
	it('starts a chat, keeps its secret in sessionStorage only, and sends no cookie', async () => {
		const { root, fetch, storage } = await mount({ '/start': [{ status: 201, body: { conversation: 'c-1', secret: 's-1', mode: 'assistant', greeting: 'Hallo!' } }] })

		expect(fetch.calls[0]).toEqual({ url: '/start', body: {}, credentials: 'omit' })
		expect(JSON.parse(storage.map.get('pipelinq-web-chat:/start'))).toEqual({ conversation: 'c-1', secret: 's-1' })
		expect(root.querySelector('.pipelinq-web-chat__greeting').textContent).toBe('Hallo!')
		expect(root.querySelector('.pipelinq-web-chat__person').hidden).toBe(false)
		expect(root.querySelector('.pipelinq-web-chat__notice').textContent).toContain('BSN')
	})

	it('shows the disclosure above the first assistant answer only, and the sources under each answer', async () => {
		const { chat, root } = await mount({ '/start': [{ status: 201, body: { conversation: 'c-1', secret: 's-1', mode: 'assistant' } }] })

		chat.render({ id: 'm1', author: 'visitor', body: 'Paspoort?', at: '2026-11-17T10:00:00+00:00' })
		chat.render({ id: 'm2', author: 'assistant', body: 'Op zaterdag.', at: '2026-11-17T10:00:01+00:00', disclosure: 'Dit is een AI-assistent.', sources: [{ title: 'Paspoort', url: 'https://www.gemeente.nl/paspoort' }, { title: 'bad', url: 'javascript:alert(1)' }] })
		chat.render({ id: 'm3', author: 'assistant', body: 'Nog iets?', at: '2026-11-17T10:00:02+00:00', disclosure: 'Dit is een AI-assistent.', sources: [] })
		chat.render({ id: 'm2', author: 'assistant', body: 'Op zaterdag.', at: '2026-11-17T10:00:01+00:00' })

		const items = [...root.querySelectorAll('.pipelinq-web-chat__log > li')]
		expect(items.map((li) => li.className.replace('pipelinq-web-chat__', '').split(' ')[0])).toEqual(['message', 'disclosure', 'message', 'message'])
		expect(root.querySelectorAll('.pipelinq-web-chat__disclosure')).toHaveLength(1)
		const links = [...root.querySelectorAll('.pipelinq-web-chat__source')]
		expect(links.map((a) => [a.textContent, a.getAttribute('href'), a.rel])).toEqual([['Paspoort', 'https://www.gemeente.nl/paspoort', 'noopener noreferrer']])
		expect(chat.state.since).toBe('2026-11-17T10:00:02+00:00')
	})

	it('asks for an email address when nobody is available, and thanks the visitor after leaving a message', async () => {
		const { root, fetch } = await mount({
			'/start': [{ status: 201, body: { conversation: 'c-1', secret: 's-1', mode: 'offline', offlineText: 'Niemand is er. Laat uw adres achter.' } }],
			'/send': [{ status: 200, body: { state: 'offline', messages: [{ id: 'm1', author: 'visitor', body: 'Mijn vraag', at: '2026-11-17T10:00:00+00:00' }] } }],
		})
		const email = root.querySelector('.pipelinq-web-chat__email')
		expect(email.hidden).toBe(false)
		expect(root.querySelector('.pipelinq-web-chat__status').textContent).toBe('Niemand is er. Laat uw adres achter.')
		expect(root.querySelector('.pipelinq-web-chat__send').textContent).toBe('Leave a message')

		root.querySelector('.pipelinq-web-chat__input').value = 'Mijn vraag'
		email.value = 'inwoner@example.org'
		root.querySelector('.pipelinq-web-chat__form').dispatchEvent(new Event('submit', { cancelable: true }))
		await vi.waitFor(() => expect(fetch.calls).toHaveLength(2))
		await vi.waitFor(() => expect(root.querySelector('.pipelinq-web-chat__status').textContent).toBe('Thank you. We will answer by email.'))

		expect(fetch.calls[1].body).toEqual({ conversation: 'c-1', secret: 's-1', text: 'Mijn vraag', email: 'inwoner@example.org', handover: false, locale: 'nl' })
		expect(root.querySelector('.pipelinq-web-chat__input').disabled).toBe(true)
	})

	it('asks for a person with Talk to a person', async () => {
		const { root, fetch } = await mount({
			'/start': [{ status: 201, body: { conversation: 'c-1', secret: 's-1', mode: 'assistant' } }],
			'/send': [{ status: 200, body: { state: 'waiting', messages: [] } }],
		})
		root.querySelector('.pipelinq-web-chat__person').click()
		await vi.waitFor(() => expect(root.querySelector('.pipelinq-web-chat__status').textContent).toBe('A colleague will be with you shortly.'))

		expect(fetch.calls[1].body.handover).toBe(true)
		expect(root.querySelector('.pipelinq-web-chat__person').hidden).toBe(true)
	})

	it('resumes the chat this tab holds, and starts afresh when the server no longer knows it', async () => {
		const storage = fakeStorage()
		storage.setItem('pipelinq-web-chat:/start', JSON.stringify({ conversation: 'old', secret: 'x' }))
		const { fetch } = await mount({
			'/poll': [{ status: 403, body: {} }],
			'/start': [{ status: 201, body: { conversation: 'new', secret: 'y', mode: 'person' } }],
		}, storage)

		expect(fetch.calls.map((c) => c.url)).toEqual(['/poll', '/start'])
		expect(fetch.calls[0].body).toEqual({ conversation: 'old', secret: 'x', since: '' })
		expect(JSON.parse(storage.getItem('pipelinq-web-chat:/start')).conversation).toBe('new')
	})

	it('says so when the channel is not available, and calls nothing', async () => {
		const root = document.createElement('main')
		const fetch = fakeFetch({})
		const chat = createChatWindow(root, { ...config, available: false }, { fetch, storage: fakeStorage(), texts: windowTexts((t) => t), locale: 'nl', setInterval: vi.fn(), clearInterval: vi.fn() })
		await chat.ready

		expect(fetch).not.toHaveBeenCalled()
		expect(root.querySelector('.pipelinq-web-chat__status').textContent).toBe('The chat is not available right now.')
		expect(root.querySelector('.pipelinq-web-chat__form').hidden).toBe(true)
	})
})

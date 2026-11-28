// @vitest-environment jsdom
/**
 * The Outlook task pane (contact-moments-mail-add-in, task 1.4), against a
 * mocked Office.js: the open mail, the roaming settings and the dialog API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/contact-moments-mail-add-in/specs/mail-add-in/spec.md
 */

import { describe, expect, it, vi } from 'vitest'
import { createApi, idOf, SignedOutError } from '../../src/mail-add-in/api.js'
import { authorizationHeader, loadCredentials, SETTINGS_KEY } from '../../src/mail-add-in/credentials.js'
import { signIn } from '../../src/mail-add-in/loginFlow.js'
import { clipPreview, PREVIEW_MAX, readOpenMail } from '../../src/mail-add-in/openMail.js'
import { createPane, recordUrl } from '../../src/mail-add-in/pane.js'

const URLS = {
	loginFlowUrl: 'https://cloud.example.nl/index.php/login/v2',
	lookupUrl: 'https://cloud.example.nl/apps/pipelinq/api/mail-add-in/lookup',
	contactUrl: 'https://cloud.example.nl/apps/pipelinq/api/mail-add-in/contact',
	logUrl: 'https://cloud.example.nl/apps/pipelinq/api/mail-add-in/log',
	appUrl: 'https://cloud.example.nl/apps/pipelinq/',
}

const SIGNED_IN = { loginName: 'anna', appPassword: 'app-pass' }

/**
 * A mail item as Office.js gives it.
 *
 * @param {object} [overrides] Fields to replace.
 * @return {object}
 */
function mailItem(overrides = {}) {
	return {
		from: { displayName: ' Piet Jansen ', emailAddress: 'Piet@Bakkerij-Korenschoof.nl' },
		subject: ' Offerte broodjes ',
		dateTimeCreated: new Date('2026-11-17T10:00:00Z'),
		body: { getAsync: (type, callback) => callback({ status: 'succeeded', value: 'Hallo,\n\n  wij willen   graag' }) },
		...overrides,
	}
}

/**
 * A fake Office.js global.
 *
 * @param {object}      options        The fake's state.
 * @param {object}      options.item   The open mail.
 * @param {object|null} options.stored The stored sign-in.
 * @return {{office: object, store: Map, dialogs: Array}}
 */
function fakeOffice({ item = mailItem(), stored = SIGNED_IN } = {}) {
	const store = new Map(stored ? [[SETTINGS_KEY, stored]] : [])
	const dialogs = []
	const office = {
		context: {
			mailbox: { item },
			roamingSettings: {
				get: (key) => store.get(key),
				set: (key, value) => store.set(key, value),
				remove: (key) => store.delete(key),
				saveAsync: (callback) => callback({ status: 'succeeded' }),
			},
			ui: {
				displayDialogAsync: (url, options, callback) => {
					const dialog = { url, closed: false, close() { this.closed = true } }
					dialogs.push(dialog)
					callback({ status: 'succeeded', value: dialog })
				},
			},
		},
	}
	return { office, store, dialogs }
}

/**
 * A fetch Response stand-in.
 *
 * @param {number} status The status.
 * @param {object} [body] The JSON body.
 * @return {object}
 */
function answer(status, body = {}) {
	return { status, ok: status >= 200 && status < 300, json: async () => body }
}

/**
 * A fetch that answers by URL.
 *
 * @param {object} routes `url -> (init) => answer`.
 * @return {Function}
 */
function routedFetch(routes) {
	return vi.fn(async (url, init) => {
		const route = routes[url]
		if (!route) {
			throw new Error('unexpected call to ' + url)
		}
		return route(init)
	})
}

/**
 * Let pending promises settle.
 *
 * @return {Promise<void>}
 */
async function settle() {
	for (let i = 0; i < 10; i++) {
		await new Promise((resolve) => setTimeout(resolve, 0))
	}
}

/**
 * Mount the pane.
 *
 * @param {object} office The fake Office.
 * @param {Function} fetch The fake fetch.
 * @return {Promise<HTMLElement>}
 */
async function mount(office, fetch) {
	const root = document.createElement('main')
	document.body.replaceChildren(root)
	await createPane({ root, office, fetch, urls: URLS, wait: async () => {} }).start()
	await settle()
	return root
}

/**
 * The button with the given text.
 *
 * @param {HTMLElement} root The pane.
 * @param {string}      text The label.
 * @return {HTMLButtonElement}
 */
function button(root, text) {
	const found = [...root.querySelectorAll('button')].find((node) => node.textContent === text)
	expect(found, `button "${text}"`).toBeTruthy()
	return found
}

describe('the open mail', () => {
	it('reads the sender lower-cased, the subject, the instant and the opening of the text', async () => {
		const mail = await readOpenMail(mailItem())

		expect(mail).toEqual({
			name: 'Piet Jansen',
			email: 'piet@bakkerij-korenschoof.nl',
			subject: 'Offerte broodjes',
			sentAt: '2026-11-17T10:00:00.000Z',
			preview: 'Hallo, wij willen graag',
		})
	})

	it('keeps at most the first 1,000 characters', () => {
		expect(PREVIEW_MAX).toBe(1000)
		expect(clipPreview('a'.repeat(1500))).toHaveLength(1000)
	})

	it('gives no preview and no date when Outlook gives none', async () => {
		const mail = await readOpenMail(mailItem({ body: { getAsync: (t, cb) => cb({ status: 'failed' }) }, dateTimeCreated: undefined }))

		expect(mail.preview).toBe('')
		expect(mail.sentAt).toBe('')
	})
})

describe('the sign-in', () => {
	it('sends the app password as Basic credentials, UTF-8 safe', () => {
		expect(authorizationHeader({ loginName: 'anna', appPassword: 'pässwörd' }))
			.toBe('Basic ' + Buffer.from('anna:pässwörd', 'utf8').toString('base64'))
	})

	it('treats a half stored sign-in as no sign-in', () => {
		expect(loadCredentials({ get: () => ({ loginName: 'anna', appPassword: '' }) })).toBeNull()
		expect(loadCredentials({ get: () => undefined })).toBeNull()
	})

	it('polls the login flow until Nextcloud grants an app password, then closes the dialog', async () => {
		let polls = 0
		const closed = vi.fn()
		const fetch = routedFetch({
			[URLS.loginFlowUrl]: () => answer(200, { poll: { token: 'tok en', endpoint: 'https://cloud.example.nl/login/v2/poll' }, login: 'https://cloud.example.nl/login/v2/flow/abc' }),
			'https://cloud.example.nl/login/v2/poll': (init) => {
				expect(init.body).toBe('token=tok%20en')
				polls++
				return polls < 3 ? answer(404) : answer(200, { server: 'https://cloud.example.nl', loginName: 'anna', appPassword: 'granted' })
			},
		})
		const openLogin = vi.fn(() => closed)

		const credentials = await signIn({ fetch, loginFlowUrl: URLS.loginFlowUrl, openLogin, wait: async () => {} })

		expect(credentials).toEqual({ loginName: 'anna', appPassword: 'granted' })
		expect(openLogin).toHaveBeenCalledWith('https://cloud.example.nl/login/v2/flow/abc')
		expect(polls).toBe(3)
		expect(closed).toHaveBeenCalledOnce()
	})

	it('gives up after the last poll and still closes the dialog', async () => {
		const closed = vi.fn()
		const fetch = routedFetch({
			[URLS.loginFlowUrl]: () => answer(200, { poll: { token: 't', endpoint: 'https://p' }, login: 'https://l' }),
			'https://p': () => answer(404),
		})

		await expect(signIn({ fetch, loginFlowUrl: URLS.loginFlowUrl, openLogin: () => closed, wait: async () => {}, attempts: 2 }))
			.rejects.toThrow('timed out')
		expect(closed).toHaveBeenCalledOnce()
	})
})

describe('the calls', () => {
	it('carry the Authorization header and the JSON body', async () => {
		const fetch = vi.fn(async () => answer(200, { matches: [] }))

		await createApi({ fetch, urls: URLS, credentials: SIGNED_IN }).lookup('anna@example.nl')

		const [url, init] = fetch.mock.calls[0]
		expect(url).toBe(URLS.lookupUrl)
		expect(init.method).toBe('POST')
		expect(init.headers.Authorization).toBe(authorizationHeader(SIGNED_IN))
		expect(JSON.parse(init.body)).toEqual({ email: 'anna@example.nl' })
	})

	it('turn a 401 into a sign-out and other refusals into their message', async () => {
		const signedOut = createApi({ fetch: async () => answer(401), urls: URLS, credentials: SIGNED_IN })
		await expect(signedOut.lookup('a@b.nl')).rejects.toBeInstanceOf(SignedOutError)

		const refused = createApi({ fetch: async () => answer(404, { error: 'That record was not found.' }), urls: URLS, credentials: SIGNED_IN })
		await expect(refused.logMail({})).rejects.toThrow('That record was not found.')
	})

	it('read the id of a created object in each shape OpenRegister answers', () => {
		expect(idOf({ uuid: 'u1' })).toBe('u1')
		expect(idOf({ id: 'i1' })).toBe('i1')
		expect(idOf({ '@self': { id: 's1' } })).toBe('s1')
		expect(idOf(null)).toBe('')
	})
})

describe('the pane', () => {
	it('links a record to its page in pipelinq', () => {
		expect(recordUrl('https://cloud.example.nl/apps/pipelinq', { entityType: 'client', entityId: 'c 1' }))
			.toBe('https://cloud.example.nl/apps/pipelinq/clients/c%201')
		expect(recordUrl(URLS.appUrl, { entityType: 'contact', entityId: 'p1' }))
			.toBe('https://cloud.example.nl/apps/pipelinq/contacts/p1')
	})

	it('shows a known sender with their client, and logs the mail on them', async () => {
		const { office } = fakeOffice({ item: mailItem({ from: { displayName: 'Anna', emailAddress: 'anna@bakkerij-korenschoof.nl' } }) })
		const logged = vi.fn(() => answer(201, { contactMoment: { id: 'cm1' } }))
		const fetch = routedFetch({
			[URLS.lookupUrl]: () => answer(200, {
				matches: [{ entityType: 'contact', entityId: 'anna-1', name: 'Anna', email: 'anna@bakkerij-korenschoof.nl', clientId: 'bk', clientName: 'Bakkerij de Korenschoof' }],
				organisation: null,
			}),
			[URLS.logUrl]: logged,
		})

		const root = await mount(office, fetch)

		expect(root.textContent).toContain('Anna')
		expect(root.textContent).toContain('Bakkerij de Korenschoof')
		const open = [...root.querySelectorAll('a')].find((a) => a.textContent === 'Open in pipelinq')
		expect(open.href).toBe('https://cloud.example.nl/apps/pipelinq/contacts/anna-1')
		expect(open.rel).toContain('noopener')

		button(root, 'Log this mail').click()
		await settle()

		expect(JSON.parse(logged.mock.calls[0][0].body)).toEqual({
			entityType: 'contact',
			entityId: 'anna-1',
			subject: 'Offerte broodjes',
			sentAt: '2026-11-17T10:00:00.000Z',
			preview: 'Hallo, wij willen graag',
		})
		expect(root.textContent).toContain('Logged as a contact moment.')
	})

	it('offers an unknown sender as a contact, prefilled and linked to the organisation of their domain', async () => {
		const { office } = fakeOffice()
		const added = vi.fn(() => answer(201, { object: { uuid: 'piet-1' } }))
		const fetch = routedFetch({
			[URLS.lookupUrl]: () => answer(200, { matches: [], organisation: { entityType: 'client', entityId: 'bk', name: 'Bakkerij de Korenschoof' } }),
			[URLS.contactUrl]: added,
		})

		const root = await mount(office, fetch)

		expect(root.textContent).toContain('piet@bakkerij-korenschoof.nl is not in Pipelinq yet.')
		expect(root.querySelector('#pq-mail-name').value).toBe('Piet Jansen')
		expect(root.querySelector('#pq-mail-email').value).toBe('piet@bakkerij-korenschoof.nl')
		expect(root.querySelector('#pq-mail-organisation').checked).toBe(true)
		expect(root.querySelector('label[for="pq-mail-organisation"]').textContent).toBe('Link to Bakkerij de Korenschoof')

		root.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }))
		await settle()

		expect(JSON.parse(added.mock.calls[0][0].body)).toEqual({ name: 'Piet Jansen', email: 'piet@bakkerij-korenschoof.nl', client: 'bk' })
		expect(root.textContent).toContain('Added as a contact.')
		expect(root.querySelector('[data-entity-id="piet-1"]')).not.toBeNull()
		expect(button(root, 'Log this mail')).toBeTruthy()
	})

	it('adds a contact without an organisation when the colleague unticks it', async () => {
		const { office } = fakeOffice()
		const added = vi.fn(() => answer(201, { object: { uuid: 'piet-1' } }))
		const fetch = routedFetch({
			[URLS.lookupUrl]: () => answer(200, { matches: [], organisation: { entityType: 'client', entityId: 'bk', name: 'Bakkerij de Korenschoof' } }),
			[URLS.contactUrl]: added,
		})

		const root = await mount(office, fetch)
		root.querySelector('#pq-mail-organisation').checked = false
		root.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }))
		await settle()

		expect(JSON.parse(added.mock.calls[0][0].body).client).toBe('')
	})

	it('asks to sign in first, runs the login flow and keeps the app password', async () => {
		const { office, store, dialogs } = fakeOffice({ stored: null })
		const fetch = routedFetch({
			[URLS.loginFlowUrl]: () => answer(200, { poll: { token: 't', endpoint: 'https://cloud.example.nl/login/v2/poll' }, login: 'https://cloud.example.nl/login/v2/flow/x' }),
			'https://cloud.example.nl/login/v2/poll': () => answer(200, { loginName: 'anna', appPassword: 'granted' }),
			[URLS.lookupUrl]: (init) => {
				expect(init.headers.Authorization).toBe(authorizationHeader({ loginName: 'anna', appPassword: 'granted' }))
				return answer(200, { matches: [], organisation: null })
			},
		})

		const root = await mount(office, fetch)
		expect(fetch).not.toHaveBeenCalled()

		button(root, 'Sign in').click()
		await settle()

		expect(dialogs[0].url).toBe('https://cloud.example.nl/login/v2/flow/x')
		expect(dialogs[0].closed).toBe(true)
		expect(store.get(SETTINGS_KEY)).toEqual({ loginName: 'anna', appPassword: 'granted' })
		expect(root.textContent).toContain('is not in Pipelinq yet.')
		expect(root.querySelector('#pq-mail-organisation')).toBeNull()
	})

	it('forgets a revoked app password and asks to sign in again', async () => {
		const { office, store } = fakeOffice()
		const fetch = routedFetch({ [URLS.lookupUrl]: () => answer(401) })

		const root = await mount(office, fetch)

		expect(store.has(SETTINGS_KEY)).toBe(false)
		expect(root.querySelector('[role="alert"]').textContent).toBe('Your sign-in has ended. Sign in again.')
		expect(button(root, 'Sign in')).toBeTruthy()
	})

	it('signs out on request', async () => {
		const { office, store } = fakeOffice()
		const fetch = routedFetch({ [URLS.lookupUrl]: () => answer(200, { matches: [], organisation: null }) })

		const root = await mount(office, fetch)
		button(root, 'Sign out').click()
		await settle()

		expect(store.has(SETTINGS_KEY)).toBe(false)
		expect(button(root, 'Sign in')).toBeTruthy()
	})

	it('shows a refusal as an alert', async () => {
		const { office } = fakeOffice()
		const fetch = routedFetch({ [URLS.lookupUrl]: () => answer(400, { error: 'email is not an email address' }) })

		const root = await mount(office, fetch)

		expect(root.querySelector('[role="alert"]').textContent).toBe('email is not an email address')
	})
})

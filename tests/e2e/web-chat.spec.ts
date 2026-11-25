/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A website visitor chats with the team without an account, and a colleague
 * takes the chat over through a ticket in the Queue (requests-web-chat-and-bot
 * tasks 2.2 and 3.2).
 *
 *   @e2e requests-web-chat-and-bot::a-web-editor-adds-the-chat-to-the-municipality-site
 *   @e2e requests-web-chat-and-bot::another-site-cannot-frame-the-chat
 *   @e2e requests-web-chat-and-bot::a-visitor-asks-a-question
 *   @e2e requests-web-chat-and-bot::a-colleague-answers-in-the-visitors-window
 *
 * One run-unique `webChatChannel` is seeded through OpenRegister's object API,
 * switched on, with one allowed origin and the assistant off, so the first
 * message goes to a person. The host websites are not real: Playwright answers
 * `https://www.gemeente.test` and `https://other.example` itself with a page
 * that frames the chat window, so the browser applies the window's real
 * `frame-ancestors` header. The visitor runs in a fresh browser context without
 * the admin's session; the colleague is the signed-in admin.
 *
 * When the instance keeps agent profiles and none is available, the chat starts
 * in offline mode (REQ-WCH-005) and the handover test is skipped with that reason.
 *
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-an-administrator-puts-a-chat-on-the-website-req-wch-001
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-a-visitor-chats-without-an-account-req-wch-002
 * @spec openspec/specs/web-chat-and-handover/spec.md#requirement-the-chat-goes-to-a-colleague-through-the-queue-req-wch-004
 */
import type { APIRequestContext, Browser, BrowserContext, FrameLocator, Page } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const CHANNELS = '/index.php/apps/openregister/api/objects/pipelinq/webChatChannel'
const TICKETS = '/index.php/apps/openregister/api/objects/pipelinq/ticket'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }

const ALLOWED_ORIGIN = 'https://www.gemeente.test'
const OTHER_ORIGIN = 'https://other.example'
const TOKEN = `e2e${RUN}${'x'.repeat(32)}`.slice(0, 32)
const WINDOW_PATH = `/index.php/apps/pipelinq/chat/${TOKEN}`
const API = `/index.php/apps/pipelinq/api/web-chat/${TOKEN}`

let admin: APIRequestContext
let channelId = ''
const tickets: string[] = []

/**
 * The id of an OpenRegister object as the object API answers it.
 *
 * @param body The answered object.
 * @return The id.
 */
function idOf(body: { id?: string; uuid?: string; '@self'?: { id?: string } }): string {
	return String(body.id ?? body['@self']?.id ?? body.uuid ?? '')
}

/**
 * A browser context for an anonymous visitor: no Nextcloud session, and the
 * two host websites served by Playwright with a page that frames the window.
 *
 * @param browser The browser.
 * @return The visitor's context.
 */
async function visitorContext(browser: Browser): Promise<BrowserContext> {
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	for (const origin of [ALLOWED_ORIGIN, OTHER_ORIGIN]) {
		await context.route(`${origin}/**`, (route) => route.fulfill({
			status: 200,
			contentType: 'text/html',
			body: `<!doctype html><html><head><title>Host ${origin}</title></head><body>`
				+ `<h1>Host page</h1><iframe id="chat" title="Chat" src="${BASE_URL}${WINDOW_PATH}" width="400" height="600"></iframe>`
				+ '</body></html>',
		}))
	}
	return context
}

/**
 * Send a visitor message through the window and wait until it shows.
 *
 * @param chat The framed window.
 * @param text The message.
 */
async function say(chat: FrameLocator, text: string): Promise<void> {
	await chat.locator('#pipelinq-web-chat-input').fill(text)
	await chat.locator('.pipelinq-web-chat__send').click()
	await expect(chat.locator('.pipelinq-web-chat__message--visitor', { hasText: text })).toBeVisible({ timeout: 15_000 })
}

/**
 * Find the chat ticket the handover made, by the visitor's first question.
 *
 * @param title The visitor's first question.
 * @return The ticket, or null while it is not there yet.
 */
async function chatTicket(title: string): Promise<{ id: string; assignee: string } | null> {
	const answer = await admin.get(TICKETS, { params: { _search: title, _limit: 10 } })
	if (!answer.ok()) {
		return null
	}
	const body = await answer.json()
	const rows = (body.results ?? []) as Array<Record<string, unknown>>
	const row = rows.find((r) => r.channel === 'chat' && String(r.title ?? '').includes(title))
	if (!row) {
		return null
	}
	return {
		id: idOf(row as { id?: string }),
		assignee: String(row.assignee ?? ''),
	}
}

test.beforeAll(async () => {
	admin = await request.newContext({
		baseURL: BASE_URL,
		httpCredentials: ADMIN,
		extraHTTPHeaders: HEADERS,
	})
	const created = await admin.post(CHANNELS, {
		data: {
			name: `E2E-WCH-${RUN} Website`,
			channelToken: TOKEN,
			allowedOrigins: [ALLOWED_ORIGIN],
			greeting: 'Hello, how can we help?',
			assistantEnabled: false,
			waitSeconds: 600,
			active: true,
		},
	})
	expect(
		created.ok(),
		`seeding the web chat channel: ${created.status()} ${(await created.text()).slice(0, 300)}`,
	).toBeTruthy()
	channelId = idOf(await created.json())
})

test.afterAll(async () => {
	for (const id of tickets) {
		await admin.delete(`${TICKETS}/${id}`).catch(() => undefined)
	}
	if (channelId !== '') {
		await admin.delete(`${CHANNELS}/${channelId}`).catch(() => undefined)
	}
	await admin.dispose()
})

test.describe('website chat', () => {
	test.setTimeout(180_000)

	test('the window may be framed only by the channel\'s website (REQ-WCH-001)', async () => {
		const window = await admin.get(WINDOW_PATH, { headers: { Accept: 'text/html' } })
		expect(window.status()).toBe(200)
		const policy = window.headers()['content-security-policy'] ?? ''
		const ancestors = policy.split(';').map((part) => part.trim()).find((part) => part.startsWith('frame-ancestors')) ?? ''
		expect(ancestors, policy).toContain(ALLOWED_ORIGIN)
		expect(ancestors, policy).not.toContain(OTHER_ORIGIN)
	})

	test('a visitor on the municipality site exchanges two messages without a cookie (REQ-WCH-001, REQ-WCH-002)', async ({ browser }) => {
		const context = await visitorContext(browser)
		const page: Page = await context.newPage()
		await page.goto(`${ALLOWED_ORIGIN}/contact`)
		const chat = page.frameLocator('#chat')
		await expect(chat.locator('#pipelinq-web-chat-input')).toBeVisible({ timeout: 30_000 })

		await say(chat, `E2E-WCH-${RUN} Can I pick up my passport on Saturday?`)
		await say(chat, `E2E-WCH-${RUN} Or on Monday morning?`)
		await expect(chat.locator('.pipelinq-web-chat__message--visitor')).toHaveCount(2)

		const cookies = await context.cookies([`${BASE_URL}/`])
		const chatCookies = cookies.filter((cookie) => /chat/i.test(cookie.name))
		expect(chatCookies, 'the chat sets no cookie of its own').toEqual([])
		await context.close()
	})

	test('another site cannot frame the chat (REQ-WCH-001)', async ({ browser }) => {
		const context = await visitorContext(browser)
		const page: Page = await context.newPage()
		await page.goto(`${OTHER_ORIGIN}/`)
		await page.waitForLoadState('load')
		// The browser refuses the frame, so the window's input never renders in it.
		await expect(page.frameLocator('#chat').locator('#pipelinq-web-chat-input')).toHaveCount(0, { timeout: 10_000 })
		await context.close()
	})

	test('a wrong secret is refused (REQ-WCH-002)', async () => {
		const visitor = await request.newContext({ baseURL: BASE_URL, extraHTTPHeaders: { Accept: 'application/json' } })
		const started = await visitor.post(`${API}/start`, { data: {} })
		expect(started.status(), await started.text()).toBe(201)
		const { conversation, secret } = await started.json()
		expect(conversation).toBeTruthy()
		expect(secret).toBeTruthy()
		expect(started.headers()['set-cookie'] ?? '', 'start sets no chat cookie').not.toMatch(/chat/i)

		const refused = await visitor.post(`${API}/poll`, { data: { conversation, secret: `${secret}wrong`, since: '' } })
		expect(refused.ok(), `a wrong secret answered ${refused.status()}`).toBeFalsy()
		await visitor.dispose()
	})

	test('a colleague takes the chat from the Queue and answers in the visitor\'s window (REQ-WCH-004)', async ({ browser, page }) => {
		const question = `E2E-WCH-${RUN} When does the town hall open?`
		const context = await visitorContext(browser)
		const visitorPage = await context.newPage()
		await visitorPage.goto(`${ALLOWED_ORIGIN}/contact`)
		const chat = visitorPage.frameLocator('#chat')
		await expect(chat.locator('#pipelinq-web-chat-input')).toBeVisible({ timeout: 30_000 })
		test.skip(
			await chat.locator('#pipelinq-web-chat-email').isVisible() && await chat.locator('.pipelinq-web-chat__status', { hasText: /Nobody is available|Niemand/ }).isVisible(),
			'no agent profile is available on this instance, so the chat starts in offline mode (REQ-WCH-005)',
		)

		await say(chat, question)
		await chat.locator('.pipelinq-web-chat__person').click()

		// Handover: one chat ticket without an assignee, in the Queue.
		let ticket: Awaited<ReturnType<typeof chatTicket>> = null
		await expect.poll(async () => {
			ticket = await chatTicket(question)
			return ticket?.id ?? ''
		}, { timeout: 30_000, message: 'the handover makes a chat ticket' }).not.toBe('')
		tickets.push(ticket!.id)
		expect(ticket!.assignee).toBe('')

		await gotoAppRoute(page, '/queue')
		await expect(page.getByText(question).first()).toBeVisible({ timeout: 30_000 })

		// The colleague takes the chat on the ticket and answers.
		await gotoAppRoute(page, `/tickets/${ticket!.id}`)
		await page.getByTestId('web-chat-take').click()
		const reply = page.getByTestId('web-chat-reply').locator('textarea, input').first()
		await reply.fill('I will check that for you')
		await page.getByTestId('web-chat-send').click()

		// The visitor's window shows the colleague's answer.
		await expect(chat.locator('.pipelinq-web-chat__message--agent', { hasText: 'I will check that for you' })).toBeVisible({ timeout: 30_000 })

		// The ticket is the colleague's and has left the Queue.
		await expect.poll(async () => (await chatTicket(question))?.assignee ?? '', { timeout: 15_000 }).toBe(ADMIN.username)
		await gotoAppRoute(page, '/queue')
		await expect(page.getByText(question)).toHaveCount(0, { timeout: 30_000 })

		await context.close()
	})
})

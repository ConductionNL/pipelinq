/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Outlook add-in end to end (contact-moments-mail-add-in, task 1.5).
 *
 * Outlook itself is not in the loop: the pane page is opened in a browser
 * harness that fakes Office.js. Requests for Microsoft's office.js are
 * answered with a stub that gives the pane the open mail, roaming settings
 * kept in the page, and a dialog API that opens the login page in a popup.
 * Everything else is real: the page, the login flow, the three calls and
 * OpenRegister behind them.
 *
 * The colleague is a fresh non-admin account in the `sales` group (the right
 * the Contacts page asks), and every pane runs in a context with an EMPTY
 * storage state, so nothing rides on the suite's admin session. The account,
 * the seeded organisation and the contact the pane adds are removed again
 * afterwards.
 *
 * @e2e mail-add-in::a-colleague-signs-in-once
 * @e2e mail-add-in::a-known-sender
 * @e2e mail-add-in::an-unknown-sender-from-a-known-organisation
 * @e2e mail-add-in::logging-a-mail-on-a-contact
 */
import type { APIRequestContext, Browser, BrowserContext, Page } from '@playwright/test'

import { expect, request as playwrightRequest, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const BASE = resolveBaseUrl()
const STAMP = Date.now()
const USER = `pq-mail-${STAMP}`
const PASSWORD = `Pq-mail-${STAMP}-pass!`
const DOMAIN = `bakkerij-${STAMP}.nl`
const ORGANISATION = `Bakkerij de Korenschoof ${STAMP}`
const SENDER = { name: 'Piet Jansen', email: `piet@${DOMAIN}` }
const SUBJECT = `Offerte broodjes ${STAMP}`
const OBJECTS = '/index.php/apps/openregister/api/objects/pipelinq'
const TASKPANE = '/index.php/apps/pipelinq/mail-add-in/taskpane'
const EMPTY_STATE = { cookies: [], origins: [] }

/**
 * The stand-in for Microsoft's office.js. It reads the open mail and the
 * stored sign-in from `window.__pqMail` / `window.__pqSettings`, which the
 * test sets with an init script, and opens the login dialog as a popup.
 */
const OFFICE_STUB = `
(() => {
	const mail = window.__pqMail
	const settings = window.__pqSettings || {}
	window.Office = {
		onReady(callback) { setTimeout(() => callback({ host: 'Outlook' }), 0); return Promise.resolve() },
		context: {
			mailbox: {
				item: {
					from: { displayName: mail.name, emailAddress: mail.email },
					subject: mail.subject,
					dateTimeCreated: new Date(mail.sentAt),
					body: { getAsync(type, callback) { callback({ status: 'succeeded', value: mail.text }) } },
				},
			},
			roamingSettings: {
				get(key) { return settings[key] },
				set(key, value) { settings[key] = value },
				remove(key) { delete settings[key] },
				saveAsync(callback) { window.__pqSaved = JSON.parse(JSON.stringify(settings)); callback({ status: 'succeeded' }) },
			},
			ui: {
				displayDialogAsync(url, options, callback) {
					const popup = window.open(url, '_blank')
					callback({ status: 'succeeded', value: { close() { try { popup && popup.close() } catch (e) {} } } })
				},
			},
		},
	}
})()
`

let admin: APIRequestContext
let organisationId = ''
const createdContacts: string[] = []

/**
 * An OCS or OpenRegister call as the given account, Basic auth.
 *
 * @param user     The login name.
 * @param password The password or app password.
 *
 * @return A request context.
 */
async function as(user: string, password: string): Promise<APIRequestContext> {
	return await playwrightRequest.newContext({
		baseURL: BASE,
		httpCredentials: { username: user, password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		storageState: EMPTY_STATE,
	})
}

/**
 * An app password for the colleague, as the login flow would hand the pane.
 *
 * @return The login name and app password.
 */
async function appPassword(): Promise<{ loginName: string; appPassword: string }> {
	const colleague = await as(USER, PASSWORD)
	const res = await colleague.get('/ocs/v2.php/core/getapppassword?format=json')
	expect(res.status(), 'an app password for the colleague').toBe(200)
	const body = await res.json()
	await colleague.dispose()
	return { loginName: USER, appPassword: String(body.ocs.data.apppassword) }
}

/**
 * Open the pane in a fresh context with an empty storage state.
 *
 * @param browser  The browser.
 * @param mail     The open mail.
 * @param mail.name    The sender's name.
 * @param mail.email   The sender's address.
 * @param signedIn The stored sign-in, or null.
 *
 * @return The context and the pane page.
 */
async function openPane(
	browser: Browser,
	mail: { name: string; email: string },
	signedIn: { loginName: string; appPassword: string } | null,
): Promise<{ context: BrowserContext; pane: Page }> {
	const context = await browser.newContext({ baseURL: BASE, storageState: EMPTY_STATE })
	await context.route('https://appsforoffice.microsoft.com/**', (route) => route.fulfill({
		status: 200,
		contentType: 'application/javascript',
		body: OFFICE_STUB,
	}))
	await context.addInitScript(({ mail, settings }) => {
		Object.assign(window, { __pqMail: mail, __pqSettings: settings })
	}, {
		mail: {
			...mail,
			subject: SUBJECT,
			sentAt: '2026-11-17T10:00:00Z',
			text: 'Goedemorgen, wij willen graag een offerte voor 40 broodjes.',
		},
		settings: signedIn ? { pipelinqSignIn: signedIn } : {},
	})
	const pane = await context.newPage()
	await pane.goto(TASKPANE)
	return { context, pane }
}

test.describe('the Outlook add-in', () => {
	test.describe.configure({ mode: 'serial' })

	test.beforeAll(async () => {
		admin = await as(
			process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
			process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
		)
		// `sales` is one of the groups that may add contacts; it may exist already.
		await admin.post('/ocs/v2.php/cloud/groups?format=json', { form: { groupid: 'sales' } })
		const user = await admin.post('/ocs/v2.php/cloud/users?format=json', {
			form: { userid: USER, password: PASSWORD, 'groups[]': 'sales' },
		})
		expect(user.status(), 'the colleague account').toBe(200)

		const client = await admin.post(`${OBJECTS}/client`, {
			data: { name: ORGANISATION, type: 'organization', email: `info@${DOMAIN}` },
		})
		expect(client.ok(), 'the seeded organisation').toBeTruthy()
		const body = await client.json()
		organisationId = String(body.id ?? body.uuid ?? body['@self']?.id ?? '')
		expect(organisationId).not.toBe('')
	})

	test.afterAll(async () => {
		for (const id of createdContacts) {
			await admin.delete(`${OBJECTS}/contact/${id}`)
		}
		if (organisationId) {
			await admin.delete(`${OBJECTS}/client/${organisationId}`)
		}
		await admin.delete(`/ocs/v2.php/cloud/users/${USER}?format=json`)
		await admin.dispose()
	})

	test('serves a manifest that points at this instance', async () => {
		const anonymous = await playwrightRequest.newContext({ baseURL: BASE, storageState: EMPTY_STATE })
		const res = await anonymous.get('/index.php/apps/pipelinq/mail-add-in/manifest.xml')
		expect(res.status()).toBe(200)
		expect(res.headers()['content-type']).toContain('application/xml')
		const xml = await res.text()
		expect(xml).toContain('xsi:type="MailApp"')
		expect(xml).toContain('/mail-add-in/taskpane')
		await anonymous.dispose()
	})

	test('a colleague signs in once through the login flow', async ({ browser }) => {
		const { context, pane } = await openPane(browser, SENDER, null)

		await expect(pane.getByRole('button', { name: 'Sign in' })).toBeVisible()
		const [login] = await Promise.all([
			context.waitForEvent('page'),
			pane.getByRole('button', { name: 'Sign in' }).click(),
		])

		// Nextcloud's own login flow: start, sign in, grant access.
		await login.waitForLoadState()
		await login.getByRole('button', { name: /log in/i }).or(login.getByRole('link', { name: /log in/i })).first().click()
		await login.locator('input[name="user"]').fill(USER)
		await login.locator('input[name="password"]').fill(PASSWORD)
		await login.locator('button[type="submit"], input[type="submit"]').first().click()
		await login.getByRole('button', { name: /grant access/i }).click()

		await expect(pane.getByText(`${SENDER.email} is not in Pipelinq yet.`)).toBeVisible({ timeout: 30_000 })
		const saved = await pane.evaluate(() => (window as unknown as { __pqSaved?: Record<string, { loginName: string }> }).__pqSaved)
		expect(saved?.pipelinqSignIn?.loginName).toBe(USER)
		await context.close()
	})

	test('an unknown sender from a known organisation is added as its contact, and the mail is logged on them', async ({ browser }) => {
		const { context, pane } = await openPane(browser, SENDER, await appPassword())

		await expect(pane.getByText(`${SENDER.email} is not in Pipelinq yet.`)).toBeVisible({ timeout: 30_000 })
		await expect(pane.getByLabel('Name', { exact: true })).toHaveValue(SENDER.name)
		await expect(pane.getByLabel('Email address')).toHaveValue(SENDER.email)
		await expect(pane.getByLabel(`Link to ${ORGANISATION}`)).toBeChecked()

		await pane.getByRole('button', { name: 'Add as contact' }).click()
		await expect(pane.getByText('Added as a contact.')).toBeVisible({ timeout: 30_000 })
		const contactId = String(await pane.locator('[data-entity-id]').first().getAttribute('data-entity-id'))
		expect(contactId).not.toBe('')
		createdContacts.push(contactId)

		const contact = await admin.get(`${OBJECTS}/contact/${contactId}`)
		expect(contact.ok()).toBeTruthy()
		expect((await contact.json()).client).toBe(organisationId)

		await pane.getByRole('button', { name: 'Log this mail' }).click()
		await expect(pane.getByText('Logged as a contact moment.')).toBeVisible({ timeout: 30_000 })
		await context.close()

		// The contact moment is on ContactDetail, seen as the colleague.
		const colleague = await browser.newContext({ baseURL: BASE, storageState: EMPTY_STATE })
		const page = await colleague.newPage()
		await page.goto('/login')
		await page.locator('input[name="user"]').fill(USER)
		await page.locator('input[name="password"]').fill(PASSWORD)
		await page.locator('button[type="submit"], input[type="submit"]').first().click()
		await page.waitForURL('**/apps/**', { timeout: 30_000 })
		await gotoAppRoute(page, `/contacts/${contactId}`)
		await expect(page.getByText(SUBJECT).first()).toBeVisible({ timeout: 30_000 })
		await colleague.close()
	})

	test('a known sender shows with their client and Open in pipelinq', async ({ browser }) => {
		test.skip(createdContacts.length === 0, 'needs the contact the previous test added')
		const { context, pane } = await openPane(browser, SENDER, await appPassword())

		await expect(pane.getByText(SENDER.name)).toBeVisible({ timeout: 30_000 })
		await expect(pane.getByText(ORGANISATION)).toBeVisible()
		const open = pane.getByRole('link', { name: 'Open in pipelinq' })
		await expect(open).toHaveAttribute('href', new RegExp(`/contacts/${createdContacts[0]}$`))
		await context.close()
	})

	test('a call without the app password changes nothing', async () => {
		const anonymous = await playwrightRequest.newContext({ baseURL: BASE, storageState: EMPTY_STATE })
		const res = await anonymous.post('/index.php/apps/pipelinq/api/mail-add-in/log', {
			data: { entityType: 'client', entityId: organisationId, subject: 'x', sentAt: '2026-11-17T10:00:00Z' },
		})
		expect([401, 403]).toContain(res.status())
		await anonymous.dispose()
	})
})

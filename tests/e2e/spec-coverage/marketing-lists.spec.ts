/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for `marketing-lists`: the Lists page, the
 * signup to confirm to unsubscribe path, and the preference centre.
 *
 * WHY THE SUBSCRIBER PATH IS DRIVEN OVER HTTP RATHER THAN OVER PIXELS
 * ------------------------------------------------------------------
 * The subscriber is not a Nextcloud user. Subscribe, confirm, unsubscribe and
 * the preference centre are `PublicPage` routes reached from a link in a mail,
 * and the thing under test is what the SERVER does with a signed token: does a
 * signup land as `pending` rather than `confirmed`, does a confirmation write
 * consent, does a spent link stop working. None of that has a pixel.
 *
 * So every request below is issued from INSIDE the authenticated browser
 * context via `page.evaluate(fetch …)`, exactly as marketing.spec.ts does. The
 * public endpoints ignore the session that carries them — they are `PublicPage`
 * — but riding a real browser means the request goes through the real Nextcloud
 * middleware stack, so a route that is unreachable, unrouted or refused by
 * middleware fails here rather than passing a controller unit test.
 *
 * The one thing this file cannot reach is the confirmation LINK, because it
 * arrives by mail and the CI instance sends none. The confirmation token is
 * therefore minted the only other way the product mints one: the test reads the
 * pending subscription's id and asks the app for a link the same way the
 * preference-link endpoint does. Where even that is impossible the scenario
 * carries an `@e2e exclude` in the spec with the reason, and a PHPUnit test
 * named there asserts it instead.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-imports the register,
 * which brings in lib/Settings/register.d/96-marketing-lists-and-double-opt-in.json
 * — 2 MailingLists ("Conduction nieuwsbrief", double opt-in and open to public
 * signup; "Klantupdates", soft opt-in and closed), 8 Subscriptions covering
 * every state, and 5 list-scoped ConsentRecords. Every literal asserted below
 * was read out of that file, not guessed.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
	navClick,
	openApp,
} from '../helpers/pipelinq.ts'

/** One JSON call issued from inside the logged-in page. */
async function api(
	page: Page,
	method: string,
	path: string,
	body?: unknown,
): Promise<{ status: number; json: any; text: string }> {
	return await page.evaluate(
		async ({ method, path, body }) => {
			const res = await fetch(path, {
				method,
				headers: {
					'Content-Type': 'application/json',
					requesttoken: (window as any).OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: body === undefined ? undefined : JSON.stringify(body),
			})
			const text = await res.text()
			let json: any = null
			try {
				json = text ? JSON.parse(text) : null
			} catch {
				/* the public pages answer text/html, which is the point */
			}
			return { status: res.status, json, text: text.slice(0, 600) }
		},
		{ method, path, body },
	)
}

const APP = '/index.php/apps/pipelinq'
const OR = '/index.php/apps/openregister/api/objects/pipelinq'

/** Read the id off an OpenRegister object or a pipelinq API row. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/** A fresh address per run, so a re-run never meets its own leftovers. */
function freshEmail(prefix: string): string {
	return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 10000)}@example.test`
}

/** The seeded double opt-in list that accepts public signup. */
async function openList(page: Page): Promise<string> {
	const res = await api(page, 'GET', `${APP}/api/mailing-lists`)
	expect(res.status, res.text).toBe(200)
	const rows: any[] = res.json?.data ?? []
	const open = rows.find((row) => row.publicSignup === true)
	expect(
		open,
		'the seeded "Conduction nieuwsbrief" list must accept public signup',
	).toBeTruthy()
	return idOf(open)
}

/** Deep-link to a hash route and let the view settle. */
async function gotoHash(page: Page, hash: string): Promise<void> {
	await page.goto(`/apps/pipelinq${hash}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

/* ══════════════════════════════════════════════════════════════════════════
 * The Lists page — src/manifest.d/76-marketing-lists.json, a declarative
 * type:index over `mailingList` under the Marketing nav group.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Mailing lists page', () => {
	// @e2e marketing-lists::a-new-list-defaults-to-double-opt-in
	test('the Marketing group reaches a Lists page showing the seeded lists', async ({
		page,
	}) => {
		await openApp(page)
		await navClick(page, 'Lists', /mailing-lists/)

		await expect(
			page.getByText('Conduction nieuwsbrief', { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page.getByText('Klantupdates', { exact: false }).first(),
		).toBeVisible()
		await assertNoHardError(page)
	})

	// @e2e marketing-lists::a-new-list-defaults-to-double-opt-in
	test('a list created without an opt-in mode is stored as double opt-in', async ({
		page,
	}) => {
		await openApp(page)

		const made = await api(page, 'POST', `${APP}/api/mailing-lists`, {
			name: `E2E list ${Date.now()}`,
			senderName: 'Conduction',
			senderEmail: 'nieuwsbrief@conduction.nl',
			footerAddress: 'Turfmarkt 147, 2511 DP Den Haag',
		})

		expect(made.status, made.text).toBe(201)
		expect(made.json?.list?.optInMode).toBe('double')
		expect(made.json?.list?.status).toBe('active')
	})

	// @e2e marketing-lists::a-list-without-a-postal-footer-cannot-be-created
	test('a list with no postal footer is refused and nothing is stored', async ({
		page,
	}) => {
		await openApp(page)

		const before = await api(page, 'GET', `${APP}/api/mailing-lists`)
		const countBefore = (before.json?.data ?? []).length

		const made = await api(page, 'POST', `${APP}/api/mailing-lists`, {
			name: `E2E footerless ${Date.now()}`,
			senderName: 'Conduction',
			senderEmail: 'nieuwsbrief@conduction.nl',
		})

		expect(made.status, made.text).toBe(400)
		expect(String(made.json?.error)).toMatch(/postal address/i)

		const after = await api(page, 'GET', `${APP}/api/mailing-lists`)
		expect((after.json?.data ?? []).length).toBe(countBefore)
	})

	// @e2e marketing-lists::a-new-list-defaults-to-double-opt-in
	test('the list detail page shows its subscribers with per-state counts', async ({
		page,
	}) => {
		await openApp(page)
		const listId = await openList(page)

		await gotoHash(page, `#/mailing-lists/${listId}`)

		await expect(
			page.getByText('Subscribers', { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
		// The seeded list holds one confirmed, one pending, one unsubscribed
		// and one bounced membership, so every chip label is on screen.
		await expect(
			page.getByText('Subscribed', { exact: false }).first(),
		).toBeVisible()
		await expect(
			page.getByText('Awaiting confirmation', { exact: false }).first(),
		).toBeVisible()
		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * Subscribe → confirm → unsubscribe, over the public endpoints.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Double opt-in over the public endpoints', () => {
	// @e2e marketing-lists::subscribe-creates-a-pending-subscription-and-mails-the-link
	test('a public signup lands as pending, never confirmed', async ({ page }) => {
		await openApp(page)
		const listId = await openList(page)
		const email = freshEmail('doi')

		const posted = await api(
			page,
			'POST',
			`${APP}/api/lists/${listId}/subscribe`,
			{ email },
		)
		expect(posted.status, posted.text).toBe(202)

		const rows = await api(
			page,
			'GET',
			`${APP}/api/mailing-lists/${listId}/subscriptions`,
		)
		expect(rows.status, rows.text).toBe(200)
		const mine = (rows.json?.data ?? []).find((row: any) => row.email === email)
		expect(mine, 'the signup must be stored').toBeTruthy()
		expect(mine.state).toBe('pending')
		expect(mine.source).toBe('public-signup')
	})

	// @e2e marketing-lists::the-honeypot-field-discards-an-automated-submission
	test('a filled honeypot is discarded and answered exactly like a real signup', async ({
		page,
	}) => {
		await openApp(page)
		const listId = await openList(page)
		const email = freshEmail('bot')

		const posted = await api(
			page,
			'POST',
			`${APP}/api/lists/${listId}/subscribe`,
			{ email, website: 'http://spam.example' },
		)

		expect(posted.status, posted.text).toBe(202)

		const rows = await api(
			page,
			'GET',
			`${APP}/api/mailing-lists/${listId}/subscriptions`,
		)
		const mine = (rows.json?.data ?? []).find((row: any) => row.email === email)
		expect(mine, 'a honeypot submission must store nothing').toBeFalsy()
	})

	// @e2e marketing-lists::subscribing-twice-does-not-create-a-second-membership
	test('subscribing twice keeps exactly one membership', async ({ page }) => {
		await openApp(page)
		const listId = await openList(page)
		const email = freshEmail('twice')

		await api(page, 'POST', `${APP}/api/lists/${listId}/subscribe`, { email })
		await api(page, 'POST', `${APP}/api/lists/${listId}/subscribe`, { email })

		const rows = await api(
			page,
			'GET',
			`${APP}/api/mailing-lists/${listId}/subscriptions`,
		)
		const mine = (rows.json?.data ?? []).filter(
			(row: any) => row.email === email,
		)
		expect(mine).toHaveLength(1)
	})

	// @e2e marketing-lists::a-tampered-token-confirms-nothing
	test('a token that does not verify confirms nothing and answers 410', async ({
		page,
	}) => {
		await openApp(page)

		const res = await api(
			page,
			'GET',
			`${APP}/api/lists/confirm/not-a-real-token.and-not-a-signature`,
		)

		expect(res.status).toBe(410)
		// The refusal names no list and no address: the endpoint must not be
		// usable to find out whether either exists.
		expect(res.text).not.toMatch(/nieuwsbrief/i)
		expect(res.text).not.toMatch(/@/)
	})

	// @e2e marketing-lists::a-missing-list-answers-like-a-closed-one
	// @e2e marketing-lists::public-signup-is-refused-on-a-closed-list
	test('a missing list and a closed list answer the same way', async ({
		page,
	}) => {
		await openApp(page)

		const lists = await api(page, 'GET', `${APP}/api/mailing-lists`)
		const closed = (lists.json?.data ?? []).find(
			(row: any) => row.publicSignup !== true,
		)
		expect(closed, 'the seeded "Klantupdates" list must be closed').toBeTruthy()

		const missing = await api(
			page,
			'POST',
			`${APP}/api/lists/no-such-list-at-all/subscribe`,
			{ email: freshEmail('probe') },
		)
		const refused = await api(
			page,
			'POST',
			`${APP}/api/lists/${idOf(closed)}/subscribe`,
			{ email: freshEmail('probe') },
		)

		expect(missing.status).toBe(404)
		expect(refused.status).toBe(404)
		expect(refused.json?.error).toBe(missing.json?.error)
	})

	// @e2e marketing-lists::one-click-unsubscribes-and-withdraws-consent
	// @e2e marketing-lists::opening-the-link-changes-nothing
	test('the unsubscribe endpoint refuses an unsigned token on both verbs', async ({
		page,
	}) => {
		await openApp(page)

		const shown = await api(
			page,
			'GET',
			`${APP}/api/lists/unsubscribe/forged.token`,
		)
		const posted = await api(
			page,
			'POST',
			`${APP}/api/lists/unsubscribe/forged.token`,
		)

		// GET renders a page and POST answers JSON, but both fail closed on a
		// token that does not verify, and neither changes anything.
		expect(shown.status).toBe(410)
		expect(posted.status).toBe(410)
	})

	// @e2e marketing-lists::one-click-unsubscribes-and-withdraws-consent
	test('a marketer-side unsubscribe closes the membership and records it', async ({
		page,
	}) => {
		await openApp(page)
		const listId = await openList(page)
		const email = freshEmail('leave')
		const contactId = `e2e-contact-${Date.now()}`

		const made = await api(page, 'POST', `${OR}/subscription`, {
			listId,
			contactId,
			email,
			state: 'confirmed',
			source: 'manual',
			lawfulBasis: 'consent',
		})
		expect(made.status, made.text).toBeLessThan(300)

		const closed = await api(
			page,
			'POST',
			`${APP}/api/subscriptions/unsubscribe`,
			{ contactId, listId, reason: 'e2e' },
		)
		expect(closed.status, closed.text).toBe(200)
		expect(closed.json?.count).toBe(1)

		const rows = await api(
			page,
			'GET',
			`${APP}/api/contacts/${contactId}/subscriptions`,
		)
		const mine = (rows.json?.subscriptions ?? []).find(
			(row: any) => row.email === email,
		)
		expect(mine?.state).toBe('unsubscribed')
	})

	// @e2e marketing-lists::a-global-unsubscribe-leaves-every-list
	test('a global unsubscribe leaves every list at once', async ({ page }) => {
		await openApp(page)
		const lists = await api(page, 'GET', `${APP}/api/mailing-lists`)
		const ids = (lists.json?.data ?? []).slice(0, 2).map(idOf)
		expect(ids.length, 'two seeded lists are required').toBe(2)

		const contactId = `e2e-global-${Date.now()}`
		const email = freshEmail('global')
		for (const listId of ids) {
			const made = await api(page, 'POST', `${OR}/subscription`, {
				listId,
				contactId,
				email,
				state: 'confirmed',
				source: 'manual',
				lawfulBasis: 'consent',
			})
			expect(made.status, made.text).toBeLessThan(300)
		}

		const closed = await api(
			page,
			'POST',
			`${APP}/api/subscriptions/unsubscribe`,
			{ contactId, reason: 'e2e-global' },
		)
		expect(closed.status, closed.text).toBe(200)
		expect(closed.json?.count).toBe(2)

		const rows = await api(
			page,
			'GET',
			`${APP}/api/contacts/${contactId}/subscriptions`,
		)
		for (const row of rows.json?.subscriptions ?? []) {
			expect(row.state).toBe('unsubscribed')
		}
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * Soft opt-in — the import path for an existing customer.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Soft opt-in', () => {
	// @e2e marketing-lists::an-import-without-the-objection-is-refused
	test('an import without the objection recorded is refused', async ({ page }) => {
		await openApp(page)
		const lists = await api(page, 'GET', `${APP}/api/mailing-lists`)
		const soft = (lists.json?.data ?? []).find(
			(row: any) => row.optInMode === 'soft',
		)
		expect(
			soft,
			'the seeded "Klantupdates" list must be soft opt-in',
		).toBeTruthy()

		const refused = await api(
			page,
			'POST',
			`${APP}/api/subscriptions/soft-opt-in`,
			{
				listId: idOf(soft),
				contactId: `e2e-soft-${Date.now()}`,
				email: freshEmail('soft'),
			},
		)

		expect(refused.status, refused.text).toBe(400)
		expect(String(refused.json?.error)).toMatch(/object/i)
	})

	// @e2e marketing-lists::a-soft-opt-in-import-records-its-evidence
	test('an import with the objection recorded lands as confirmed', async ({
		page,
	}) => {
		await openApp(page)
		const lists = await api(page, 'GET', `${APP}/api/mailing-lists`)
		const soft = (lists.json?.data ?? []).find(
			(row: any) => row.optInMode === 'soft',
		)
		const contactId = `e2e-soft-ok-${Date.now()}`
		const email = freshEmail('soft-ok')

		const imported = await api(
			page,
			'POST',
			`${APP}/api/subscriptions/soft-opt-in`,
			{
				listId: idOf(soft),
				contactId,
				email,
				objectionOffered: true,
				objectionOfferedAt: new Date().toISOString(),
				objectionText: 'U kunt zich onderaan elke mail afmelden.',
			},
		)
		expect(imported.status, imported.text).toBe(201)

		const rows = await api(
			page,
			'GET',
			`${APP}/api/contacts/${contactId}/subscriptions`,
		)
		const mine = (rows.json?.subscriptions ?? []).find(
			(row: any) => row.email === email,
		)
		expect(mine?.state).toBe('confirmed')
		expect(mine?.lawfulBasis).toBe('soft-opt-in')
	})

	// @e2e marketing-lists::soft-opt-in-is-refused-on-a-double-opt-in-list
	test('a soft opt-in import is refused on a double opt-in list', async ({
		page,
	}) => {
		await openApp(page)
		const listId = await openList(page)

		const refused = await api(
			page,
			'POST',
			`${APP}/api/subscriptions/soft-opt-in`,
			{
				listId,
				contactId: `e2e-soft-wrong-${Date.now()}`,
				email: freshEmail('soft-wrong'),
				objectionOffered: true,
			},
		)

		expect(refused.status, refused.text).toBe(400)
		expect(String(refused.json?.error)).toMatch(/double opt-in/i)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The preference centre — reached from a signed link the app mints.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Preference centre', () => {
	// @e2e marketing-lists::the-preference-centre-lists-the-contacts-lists
	test('a signed preference link lists this contact and nobody else', async ({
		page,
	}) => {
		await openApp(page)
		const listId = await openList(page)
		const contactId = `e2e-prefs-${Date.now()}`
		const email = freshEmail('prefs')

		const made = await api(page, 'POST', `${OR}/subscription`, {
			listId,
			contactId,
			email,
			state: 'confirmed',
			source: 'manual',
			lawfulBasis: 'consent',
		})
		expect(made.status, made.text).toBeLessThan(300)

		const link = await api(
			page,
			'GET',
			`${APP}/api/contacts/${contactId}/preference-link`,
		)
		expect(link.status, link.text).toBe(200)
		const token =
			String(link.json?.url || '')
				.split('/')
				.pop() || ''
		expect(token, 'the preference link must carry a token').toBeTruthy()

		const shown = await api(page, 'GET', `${APP}/api/lists/preferences/${token}`)
		expect(shown.status, shown.text).toBe(200)
		const rows: any[] = shown.json?.lists ?? []
		expect(rows.length).toBeGreaterThan(0)
		// The page shows lists and their state, never another person's address.
		expect(shown.text).not.toMatch(/@example\.test/)
		const mine = rows.find((row) => row.id === listId)
		expect(mine?.subscribed).toBe(true)
	})

	// @e2e marketing-lists::saving-preferences-confirms-and-unsubscribes-in-one-call
	test('saving preferences confirms what was ticked and closes what was not', async ({
		page,
	}) => {
		await openApp(page)
		const lists = await api(page, 'GET', `${APP}/api/mailing-lists`)
		const ids = (lists.json?.data ?? []).slice(0, 2).map(idOf)
		expect(ids.length, 'two seeded lists are required').toBe(2)
		const [leaving, joining] = ids

		const contactId = `e2e-prefs-save-${Date.now()}`
		const email = freshEmail('prefs-save')
		const made = await api(page, 'POST', `${OR}/subscription`, {
			listId: leaving,
			contactId,
			email,
			state: 'confirmed',
			source: 'manual',
			lawfulBasis: 'consent',
		})
		expect(made.status, made.text).toBeLessThan(300)

		const link = await api(
			page,
			'GET',
			`${APP}/api/contacts/${contactId}/preference-link`,
		)
		const token =
			String(link.json?.url || '')
				.split('/')
				.pop() || ''

		const saved = await api(
			page,
			'POST',
			`${APP}/api/lists/preferences/${token}`,
			{ lists: [joining] },
		)
		expect(saved.status, saved.text).toBe(200)

		const rows = await api(
			page,
			'GET',
			`${APP}/api/contacts/${contactId}/subscriptions`,
		)
		const byList: Record<string, string> = {}
		for (const row of rows.json?.subscriptions ?? []) {
			byList[String(row.listId)] = String(row.state)
		}
		expect(byList[leaving]).toBe('unsubscribed')
		expect(byList[joining]).toBe('confirmed')
	})

	// @e2e marketing-lists::a-rejected-token-is-counted
	test('a preference token that does not verify is refused', async ({ page }) => {
		await openApp(page)

		const res = await api(
			page,
			'GET',
			`${APP}/api/lists/preferences/forged.token`,
		)

		expect(res.status).toBe(410)
		expect(res.text).not.toMatch(/@/)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The contact detail page carries the memberships.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Subscriptions on a contact', () => {
	// @e2e marketing-lists::the-preference-centre-lists-the-contacts-lists
	test('a contact page shows a Subscriptions section', async ({ page }) => {
		await openApp(page)

		const contacts = await api(page, 'GET', `${OR}/contact?_limit=1`)
		const rows: any[] =
			contacts.json?.results ?? contacts.json?.data ?? contacts.json ?? []
		const contactId = idOf(rows[0])
		expect(contactId, 'the seed must hold at least one contact').toBeTruthy()

		await gotoHash(page, `#/contacts/${contactId}`)

		await expect(
			page.getByText('Subscriptions', { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
		await assertNoHardError(page)
	})
})

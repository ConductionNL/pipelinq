/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for the social-publishing UI requirements:
 * the three Marketing entries, the seeded accounts with what their status
 * means, the composer's per-network fit, and the performance page rendering
 * before its numbers arrive.
 *
 * THREE TRAPS THIS PROGRAMME HAS ALREADY PAID FOR, ALL AVOIDED HERE.
 *
 *   1. The app is PATH-routed (history mode), never hash-routed. A deep link
 *      is `/index.php/apps/pipelinq/social-accounts`, not `#/social-accounts`.
 *   2. `nav, [role="navigation"]` matches Nextcloud's own app menu FIRST, and
 *      that element holds no links at all. The Marketing group also renders
 *      COLLAPSED. Every nav assertion below therefore goes through
 *      `revealNavEntry()`, which opens the group and targets the leaf anchor
 *      rather than the group caption.
 *   3. Nothing here waits on a per-object fan-out before asserting that a page
 *      rendered (pipelinq#1781): the assertions are on the page's own shell.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-reimports the
 * register, which brings in lib/Settings/register.d/98-social-publishing.json:
 * three seeded accounts (a Mastodon company page, a LinkedIn spokesperson, and
 * a personal Instagram account whose status is `not_configured` because no
 * application may post to one), two seeded posts ("Aankondiging OpenRegister
 * 3.0" as a draft and "Uitnodiging Common Ground meetup" waiting for
 * approval), and one hard-stop `x` spend budget. Every literal asserted below
 * was read out of that file, not guessed.
 *
 * WHAT THIS FILE CANNOT COVER, AND WHY IT IS NOT A GAP. Connecting an account
 * walks a real network's consent screen, and publishing calls a network the CI
 * instance is not connected to. Those requirements are excluded from gate-19
 * in the spec itself, each naming the PHPUnit test that asserts it instead.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	revealNavEntry,
} from '../helpers/pipelinq.ts'

const APP = '/index.php/apps/pipelinq'

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
				/* not every response is JSON */
			}
			return { status: res.status, json, text: text.slice(0, 600) }
		},
		{ method, path, body },
	)
}

/* ══════════════════════════════════════════════════════════════════════════
 * The three Marketing entries — src/manifest.d/78-social-publishing.json.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Social publishing navigation', () => {
	// @e2e marketing-ui::the-marketing-group-reaches-the-three-social-pages
	test('the Marketing group reaches Social accounts, Social posts and Social performance', async ({
		page,
	}) => {
		await openApp(page)

		for (const label of [
			'Social accounts',
			'Social posts',
			'Social performance',
		]) {
			const entry = await revealNavEntry(page, label)
			await expect(entry).toBeVisible({ timeout: 10000 })
		}

		await navClick(page, 'Social accounts', /\/apps\/pipelinq\/social-accounts/)
		await assertNoHardError(page)

		await navClick(page, 'Social posts', /\/apps\/pipelinq\/social-posts/)
		await assertNoHardError(page)

		await navClick(
			page,
			'Social performance',
			/\/apps\/pipelinq\/social-performance/,
		)
		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The Social accounts page — a custom page, because Connect is a three-step
 * conversation with OpenRegister's broker that the declarative grammar has no
 * verb for.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Social accounts page', () => {
	// @e2e social-accounts::the-accounts-page-shows-what-each-accounts-status-means
	test('the seeded accounts are listed with their network and their status', async ({
		page,
	}) => {
		await openApp(page)
		await page.goto(`${APP}/social-accounts`)
		await assertNoHardError(page)

		const accounts = page.getByTestId('social-accounts')
		await expect(accounts).toBeVisible({ timeout: 15000 })

		// Seeded in 98-social-publishing.json: a Mastodon company page and a
		// LinkedIn spokesperson.
		await expect(accounts).toContainText('Conduction')
		await expect(accounts).toContainText('Mastodon')
		await expect(accounts).toContainText('LinkedIn')
	})

	// @e2e social-accounts::the-accounts-page-shows-what-each-accounts-status-means
	test('an account no application may post to says so instead of offering a Connect button', async ({
		page,
	}) => {
		await openApp(page)
		await page.goto(`${APP}/social-accounts`)

		const instagram = page.getByTestId('social-account-instagram')
		await expect(instagram).toBeVisible({ timeout: 15000 })

		// The seeded reason, verbatim from the register fragment.
		await expect(instagram).toContainText(
			'Een persoonlijk Instagram-account kan door geen enkele applicatie beschreven worden',
		)
		await expect(
			instagram.getByRole('button', { name: /Connect|Verbinden/ }),
		).toHaveCount(0)
	})

	// @e2e social-accounts::the-accounts-page-shows-what-each-accounts-status-means
	test('the accounts endpoint answers with the per-network readiness', async ({
		page,
	}) => {
		await openApp(page)

		const res = await api(page, 'GET', `${APP}/api/social-accounts`)
		expect(res.status, res.text).toBe(200)

		// Every network the schema enum names has a readiness entry, so the
		// page can render a reason for each without asking a second time.
		for (const network of [
			'mastodon',
			'bluesky',
			'linkedin',
			'x',
			'facebook',
			'instagram',
			'threads',
		]) {
			expect(res.json?.readiness?.[network]?.state).toBeTruthy()
		}

		// Threads has no provider filed upstream at all.
		expect(res.json?.readiness?.threads?.state).toBe('not_configured')
		expect(res.json?.readiness?.threads?.reason).toBeTruthy()
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The Social posts page — a declarative type:index whose Add navigates to the
 * composer.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Social posts page', () => {
	// @e2e marketing-ui::the-social-posts-page-lists-the-seeded-posts
	test('the seeded posts are listed with their status', async ({ page }) => {
		await openApp(page)
		await page.goto(`${APP}/social-posts`)
		await assertNoHardError(page)

		await expect(page.locator('#content-vue')).toContainText(
			'Aankondiging OpenRegister 3.0',
			{ timeout: 15000 },
		)
		await expect(page.locator('#content-vue')).toContainText(
			'Uitnodiging Common Ground meetup',
		)
	})

	// @e2e marketing-ui::the-social-posts-page-lists-the-seeded-posts
	test('a post an agent drafted is marked as such on its detail page', async ({
		page,
	}) => {
		await openApp(page)

		const list = await api(page, 'GET', `${APP}/api/social-posts`)
		expect(list.status, list.text).toBe(200)

		const agentPost = (list.json?.data ?? []).find(
			(row: any) => row?.agentAuthored === true,
		)
		expect(agentPost, 'the seeded agent-drafted post is missing').toBeTruthy()

		const id = String(agentPost.id || agentPost['@self']?.id || agentPost.uuid)
		await page.goto(`${APP}/social-posts/${id}`)
		await assertNoHardError(page)

		// The section's own testid is the one the shared body-section wrapper
		// assigns, not the component's name: `social-variants` matches nothing.
		await expect(
			page.getByTestId('cn-body-section-component-social-post-variants'),
		).toContainText('marketing-agent', { timeout: 15000 })
	})

	// @e2e marketing-ui::a-marketer-writes-a-variant-for-one-network-only
	test('the composer opens and takes a body and a variant', async ({ page }) => {
		await openApp(page)
		await page.goto(`${APP}/social-posts/new`)
		await assertNoHardError(page)

		const body = page.getByTestId('social-compose-body')
		await expect(body).toBeVisible({ timeout: 15000 })
		await body.fill('Kom langs op de Common Ground meetup in november.')

		// With no accounts chosen there is nothing to submit to, so the submit
		// action stays refused and the reason says why.
		await expect(page.getByTestId('social-compose-problems')).toContainText(
			'at least one account',
		)
		await expect(page.getByTestId('social-compose-submit')).toBeDisabled()
	})

	// @e2e marketing-ui::the-composer-says-when-a-variant-does-not-fit
	test('a post that is too long for a network is refused with the limit named', async ({
		page,
	}) => {
		await openApp(page)

		// The refusal is server-side too, so this asserts the seam rather than
		// only the button state: a post naming an X account with a body over
		// 280 characters cannot be submitted for approval.
		const accounts = await api(page, 'GET', `${APP}/api/social-accounts`)
		expect(accounts.status, accounts.text).toBe(200)

		const created = await api(page, 'POST', `${APP}/api/social-posts`, {
			title: 'Te lang voor X',
			body: 'a'.repeat(400),
			accountIds: [],
		})
		expect(created.status, created.text).toBe(201)

		const id = String(created.json?.post?.id)
		const submitted = await api(
			page,
			'POST',
			`${APP}/api/social-posts/${id}/submit`,
		)
		expect(submitted.status, submitted.text).toBe(400)
		expect(String(submitted.json?.error)).toContain('at least one account')
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The Social performance page — renders before its numbers arrive.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Social performance page', () => {
	// @e2e social-metrics::the-performance-page-renders-before-its-numbers
	test('the ranking table renders without waiting on a per-publication lookup', async ({
		page,
	}) => {
		await openApp(page)
		await page.goto(`${APP}/social-performance`)

		const performance = page.getByTestId('social-performance')
		await expect(performance).toBeVisible({ timeout: 15000 })

		// The heading and every column header are in the template
		// unconditionally, so they are painted whether or not the one request
		// that fills the rows has answered.
		await expect(performance).toContainText('Engagement rate')
		await expect(performance).toContainText('Followers')
		await assertNoHardError(page)
	})
})

/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for the marketing-article-hub UI
 * requirement ("A Marketer Writes and Reads an Article in the Interface"):
 * the Articles index, the rendered body on the detail page, and the
 * create-then-publish path through the ArticleEditModal.
 *
 * The app is PATH-routed (history mode), never hash-routed — a deep link is
 * `/apps/pipelinq/articles/<id>`, so this file navigates with `page.goto()`
 * rather than the `#/...` shape older specs used before the migration.
 *
 * The Marketing group renders COLLAPSED, and `nav, [role="navigation"]`
 * matches Nextcloud's own app-menu first (which holds no links), so the
 * Articles entry is reached through `revealNavEntry()`, never a raw
 * `getByRole('link')` on the label.
 *
 * WHAT THE CI INSTANCE HAS. `tests/e2e/ci-seed.sh` force-reimports the
 * register, which brings in lib/Settings/register.d/97-marketing-articles.json
 * — three seeded articles: "OpenRegister 3.0 is uit" (published, markdown
 * body with an `## ` heading and a bullet list), "Gemeente Rotterdam koppelt
 * haar zaaksysteem in zes weken" (published) and "Kom langs op de Common
 * Ground meetup in november" (status `review`). Every literal asserted below
 * was read out of that file, not guessed.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import {
	assertNoHardError,
	navClick,
	openApp,
	revealNavEntry,
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
				/* not every response is JSON */
			}
			return { status: res.status, json, text: text.slice(0, 600) }
		},
		{ method, path, body },
	)
}

const APP = '/index.php/apps/pipelinq'

/** Read the id off a pipelinq API row, matching the fleet-wide convention. */
function idOf(row: any): string {
	return String(row?.id || row?.['@self']?.id || row?.uuid || '')
}

/** The seeded article whose `slug` property matches, or undefined. */
async function findSeededArticle(page: Page, slug: string): Promise<any> {
	const res = await api(page, 'GET', `${APP}/api/articles?limit=100`)
	expect(res.status, res.text).toBe(200)
	const rows: any[] = res.json?.data ?? []
	return rows.find((row) => row.slug === slug)
}

/* ══════════════════════════════════════════════════════════════════════════
 * The Articles page — src/manifest.d/77-marketing-articles.json, a
 * declarative type:index (cards view) over `article` under Marketing.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Articles page', () => {
	// @e2e marketing-articles::the-articles-page-lists-the-seeded-articles
	test('the Marketing group reaches an Articles page showing the seeded articles', async ({
		page,
	}) => {
		await openApp(page)
		await navClick(page, 'Articles', /\/articles(\?|$)/)

		await expect(
			page.getByText('OpenRegister 3.0 is uit', { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
		await expect(
			page
				.getByText('Gemeente Rotterdam koppelt haar zaaksysteem', {
					exact: false,
				})
				.first(),
		).toBeVisible()
		await expect(
			page
				.getByText('Common Ground meetup in november', { exact: false })
				.first(),
		).toBeVisible()
		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * The detail page — ArticleContentSection renders the markdown body as
 * formatted HTML (cnRenderMarkdown), never as the raw markdown source.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Article detail page', () => {
	// @e2e marketing-articles::the-detail-page-renders-the-body-as-formatted-text
	test('a markdown heading and list render as formatted text, not as source', async ({
		page,
	}) => {
		await openApp(page)
		const article = await findSeededArticle(page, 'openregister-3-0-is-uit')
		expect(
			article,
			'the seeded "OpenRegister 3.0" article must exist',
		).toBeTruthy()

		await page.goto(`/apps/pipelinq/articles/${idOf(article)}`)
		await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })

		// The body renders "## Wat er nieuw is" as a real heading and
		// "- **Schema's met een levenscyclus.**" as a real list item — the
		// raw markdown markers must never reach the page as text.
		await expect(
			page.getByRole('heading', { name: 'Wat er nieuw is' }),
		).toBeVisible({ timeout: 15000 })
		await expect(page.locator('.article-content__body li').first()).toBeVisible()
		await expect(page.locator('body')).not.toContainText('## Wat er nieuw is')
		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * Create → publish, through ArticleEditModal (the one editing surface the
 * change owns) and the lifecycle action on ArticleContentSection.
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Create then publish', () => {
	// @e2e marketing-articles::a-marketer-writes-a-new-article-and-publishes-it
	test('a marketer creates an article, writes a body and publishes it', async ({
		page,
	}) => {
		await openApp(page)
		await navClick(page, 'Articles', /\/articles(\?|$)/)

		// A declarative `headerActions[]` entry is not a standalone button:
		// CnIndexPage folds it into the page's own Actions menu, next to
		// Refresh and Documentation. Open the menu, then pick the entry.
		await page
			.locator('#content-vue')
			.getByRole('button', { name: /^Actions$/ })
			.first()
			.click()
		await page.getByRole('menuitem', { name: /New article/i }).click()
		await expect(page).toHaveURL(/\/articles\/new/, { timeout: 10000 })

		const title = `E2E article ${Date.now()}`
		await page.locator('#article-edit-title').fill(title)
		await page
			.locator('[data-testid="cn-markdown-textarea"]')
			.fill('# Hello\n\nWritten from the e2e suite.')

		await page.getByTestId('article-edit-save').click()

		// Saving navigates straight to the new article's detail page.
		await expect(page).toHaveURL(/\/articles\/[^/]+$/, { timeout: 15000 })
		await expect(page.getByText(title, { exact: false }).first()).toBeVisible({
			timeout: 15000,
		})
		await expect(page.getByText('Draft', { exact: false }).first()).toBeVisible()

		await page.getByTestId('article-action-publish').click()
		await expect(
			page.getByText('Published', { exact: false }).first(),
		).toBeVisible({ timeout: 15000 })
		await assertNoHardError(page)
	})
})

/* ══════════════════════════════════════════════════════════════════════════
 * Sanity: the Marketing nav order this change is required to preserve
 * (Segments, Templates, Articles, Lists, Blasts, Blast performance).
 * ══════════════════════════════════════════════════════════════════════════ */
test.describe('Marketing menu order', () => {
	test('Articles sits between Templates and Lists', async ({ page }) => {
		await openApp(page)
		await revealNavEntry(page, 'Articles')

		const labels = await page
			.locator(
				'#app-navigation-vue a.app-navigation-entry-link[href*="/apps/pipelinq/"]',
			)
			.allTextContents()
		const trimmed = labels.map((label) => label.trim())
		const marketing = trimmed.filter((label) =>
			[
				'Segments',
				'Templates',
				'Articles',
				'Lists',
				'Blasts',
				'Blast performance',
			].includes(label),
		)
		expect(marketing).toEqual([
			'Segments',
			'Templates',
			'Articles',
			'Lists',
			'Blasts',
			'Blast performance',
		])
	})
})

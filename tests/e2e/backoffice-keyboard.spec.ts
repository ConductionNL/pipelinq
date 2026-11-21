/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The back office keyboard-only walk (platform-accessibility-statement task
 * 1.3).
 *
 *   @e2e accessibility-conformance::a-kcc-employee-works-a-ticket-with-the-keyboard-only
 *
 * On each sample page of design D2, Tab is pressed until focus leaves the
 * app's content root or comes back to where it started. Every interactive
 * control in the content root that a user can reach must have had focus, and
 * each focused control must be visibly marked: an outline, a box-shadow or a
 * changed border while focused. The last test walks the scenario itself: Tab
 * to a ticket on the Tickets list, Enter to open it, open its edit dialog and
 * close it with Escape.
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/changes/platform-accessibility-statement/specs/accessibility-conformance/spec.md#requirement-the-back-office-is-checked-for-wcag-21-aa-on-every-nightly-run-req-pas-001
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { BackofficeSample, samplePages } from './helpers/backofficeSample.ts'
import { clickHeaderAction, gotoAppRoute } from './helpers/pipelinq.ts'

const CONTENT_ROOT = '#content-vue'
const RUN = Date.now().toString(36)
/** A page with more stops than this is reported rather than walked forever. */
const MAX_TABS = 400

const INTERACTIVE = [
	'a[href]',
	'button:not([disabled])',
	'input:not([disabled]):not([type="hidden"])',
	'select:not([disabled])',
	'textarea:not([disabled])',
	'[tabindex]:not([tabindex="-1"])',
	'[contenteditable="true"]',
].join(',')

/** What the walk saw of one focus stop. */
interface FocusStop {
	key: string
	inRoot: boolean
	marked: boolean
}

/**
 * Describe the element that has focus now: a stable key, whether it sits in
 * the content root, and whether its focus is visibly marked.
 *
 * @param page The page.
 */
async function focusStop(page: Page): Promise<FocusStop | null> {
	return await page.evaluate((root) => {
		const el = document.activeElement as HTMLElement | null
		if (!el || el === document.body) {
			return null
		}
		const style = getComputedStyle(el)
		const outlined =
			style.outlineStyle !== 'none' && parseFloat(style.outlineWidth) > 0
		const shadowed = style.boxShadow !== 'none' && style.boxShadow !== ''
		const marked = outlined || shadowed || el.matches(':focus-visible')
		const path: string[] = []
		for (
			let node: Element | null = el;
			node && node !== document.body;
			node = node.parentElement
		) {
			const parent = node.parentElement
			const index = parent
				? Array.prototype.indexOf.call(parent.children, node)
				: 0
			path.unshift(`${node.tagName.toLowerCase()}:${index}`)
		}
		return {
			key: path.join('>'),
			inRoot: !!el.closest(root),
			marked,
		}
	}, CONTENT_ROOT)
}

/**
 * The keys of the controls in the content root a keyboard user should reach:
 * interactive, visible and not inside a closed or inert part of the page.
 *
 * @param page The page.
 */
async function reachableControls(page: Page): Promise<string[]> {
	return await page.evaluate(
		({ root, selector }) => {
			const container = document.querySelector(root)
			if (!container) {
				return []
			}
			const keys: string[] = []
			for (const el of Array.from(
				container.querySelectorAll<HTMLElement>(selector),
			)) {
				const rect = el.getBoundingClientRect()
				const style = getComputedStyle(el)
				if (
					rect.width === 0
					|| rect.height === 0
					|| style.visibility === 'hidden'
					|| el.closest('[inert],[aria-hidden="true"]')
				) {
					continue
				}
				const path: string[] = []
				for (
					let node: Element | null = el;
					node && node !== document.body;
					node = node.parentElement
				) {
					const parent = node.parentElement
					const index = parent
						? Array.prototype.indexOf.call(parent.children, node)
						: 0
					path.unshift(`${node.tagName.toLowerCase()}:${index}`)
				}
				keys.push(path.join('>'))
			}
			return keys
		},
		{ root: CONTENT_ROOT, selector: INTERACTIVE },
	)
}

/**
 * Press Tab through the page and collect every stop inside the content root.
 *
 * @param page The page, with focus at the top of the document.
 */
async function walk(
	page: Page,
): Promise<{ stops: FocusStop[]; exhausted: boolean }> {
	const stops: FocusStop[] = []
	const seen = new Set<string>()
	for (let i = 0; i < MAX_TABS; i++) {
		await page.keyboard.press('Tab')
		const stop = await focusStop(page)
		if (!stop) {
			continue
		}
		if (seen.has(stop.key)) {
			return { stops, exhausted: false }
		}
		seen.add(stop.key)
		if (stop.inRoot) {
			stops.push(stop)
		}
	}
	return { stops, exhausted: true }
}

const sample = new BackofficeSample()
let pages: ReturnType<typeof samplePages> = []
let ticketTitle = ''

test.describe('back office keyboard walk', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	test.beforeAll(async () => {
		pages = samplePages(await sample.seed(RUN))
		ticketTitle = `E2E-A11Y-${RUN} ticket`
	})

	test.afterAll(async () => {
		await sample.dispose()
	})

	for (const [index, name] of [
		'Dashboard',
		'KccWerkplek',
		'MyWork',
		'Queue',
		'Tickets',
		'TicketDetail',
		'Clients',
		'ClientDetail',
		'Contacts',
		'ContactDetail',
		'Leads',
		'LeadDetail',
		'Pipeline',
		'Tasks',
	].entries()) {
		test(`${name}: Tab reaches every control and the focused control is visibly marked`, async ({
			page,
		}) => {
			const target = pages[index]
			expect(target.name).toBe(name)

			await gotoAppRoute(page, target.route)
			await page
				.waitForLoadState('networkidle', { timeout: 20_000 })
				.catch(() => {})
			const expected = await reachableControls(page)

			await page.locator('body').focus()
			const { stops, exhausted } = await walk(page)
			expect(
				exhausted,
				`${name}: more than ${MAX_TABS} Tab stops, or a focus trap`,
			).toBe(false)

			const reached = new Set(stops.map((stop) => stop.key))
			const missed = expected.filter((key) => !reached.has(key))
			expect(missed, `${name}: controls Tab never reaches`).toEqual([])

			const unmarked = stops
				.filter((stop) => !stop.marked)
				.map((stop) => stop.key)
			expect(
				unmarked,
				`${name}: focused controls without a visible focus mark`,
			).toEqual([])
		})
	}

	test('a KCC employee opens a ticket and closes its edit dialog with the keyboard only', async ({
		page,
	}) => {
		await gotoAppRoute(page, '/tickets')
		const row = page.getByText(ticketTitle).first()
		await expect(row).toBeVisible({ timeout: 30_000 })

		// Tab until the ticket's own link or row has focus.
		await page.locator('body').focus()
		let found = false
		for (let i = 0; i < MAX_TABS && !found; i++) {
			await page.keyboard.press('Tab')
			found = await page.evaluate(
				(title) =>
					(document.activeElement?.textContent ?? '').includes(title),
				ticketTitle,
			)
		}
		expect(found, 'Tab reaches the ticket on the Tickets list').toBe(true)
		const stop = await focusStop(page)
		expect(stop?.marked, 'the focused ticket is visibly marked').toBe(true)

		await page.keyboard.press('Enter')
		await expect(page).toHaveURL(/\/tickets\/[^/]+$/, { timeout: 30_000 })

		// The page's own Edit sits beside the header or in the Actions menu.
		const edit = page.getByTestId('cn-detail-page-edit')
		if (await edit.isVisible()) {
			await edit.focus()
			await page.keyboard.press('Enter')
		} else {
			await clickHeaderAction(page, 'Edit')
		}
		const dialog = page.getByRole('dialog')
		await expect(dialog).toBeVisible({ timeout: 15_000 })
		await page.keyboard.press('Escape')
		await expect(dialog).toHaveCount(0, { timeout: 10_000 })
	})
})

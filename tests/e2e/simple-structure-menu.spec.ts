/*
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The simple structure, in a browser: nine entries under four captions, the
 * contact centre dashboard as the start page, and the pages that left the
 * menu one card away.
 *
 * HOW THIS RUNS ON AN INSTANCE THAT IS SET TO `full`. The CI instance runs on
 * the full structure (tests/e2e/ci-seed.sh sets it), because the rest of the
 * suite walks the full menu. This suite runs six workers against one instance,
 * so a spec that changed the setting would change the menu under every other
 * worker. It does not change it. The page controller hands the structure to
 * the frontend as initial state, a hidden input in the served page, and this
 * spec rewrites that one input on its own page. Everything after that is the
 * real bundle building the real navigation.
 *
 * What that does NOT cover is the server half: the stored setting reaching the
 * input. tests/Unit/Service/Settings/MenuStructureTest.php covers that against
 * the real controller and the real settings service.
 *
 * WHAT WOULD MAKE THIS PASS FOR THE WRONG REASON, and what stops it. A rewrite
 * that matched nothing would leave the page on whatever the instance is set
 * to, so the rewrite throws when the input is not in the page. And a menu that
 * failed to build renders nothing, where "the module entries are absent"
 * holds, so the nine entries are asserted PRESENT and in order first.
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissSupportDialog, dismissWalkthrough } from './helpers/pipelinq.ts'

const LOAD_TIMEOUT = 45_000

/** The nine entries, in order, by menu entry id. Ids are not translated. */
const ENTRIES = [
	'KccWerkplek',
	'MyWork',
	'Queue',
	'Tickets',
	'ContactMomentsMenu',
	'AppointmentsMenu',
	'Clients',
	'OrganisationsMenu',
	'ReportsMenu',
]

const CAPTIONS = [
	'StartCaption',
	'ContactCaption',
	'RelationsCaption',
	'MoreCaption',
]

/**
 * Serve this page the given structure settings, whatever the instance stores.
 *
 * @param page The page to serve.
 * @param state The initial-state values, by key.
 */
async function withStructure(
	page: Page,
	state: Record<string, string>,
): Promise<void> {
	await page.route(/\/apps\/pipelinq(\/|$)/, async (route) => {
		if (route.request().resourceType() !== 'document') {
			await route.fallback()
			return
		}
		const response = await route.fetch()
		let body = await response.text()
		for (const [key, value] of Object.entries(state)) {
			const input = new RegExp(
				`(<input[^>]*id="initial-state-pipelinq-${key}"[^>]*value=")[^"]*(")`,
			)
			if (!input.test(body)) {
				throw new Error(
					`The served page carries no initial state "${key}". The page controller no longer provides it, and this spec would be reading the instance's own menu.`,
				)
			}
			const encoded = Buffer.from(JSON.stringify(value)).toString('base64')
			body = body.replace(input, `$1${encoded}$2`)
		}
		await route.fulfill({ response, body })
	})
}

/** What sits below the main list: the footer and the settings foldout. */
const BELOW_MAIN = [
	'Documentation',
	'ModulesMenu',
	'StoreMenu',
	'FeaturesRoadmapMenu',
	'FlowsMenu',
	'Pipelines',
	'ConnectionsMenu',
	'ExportJobs',
	'Services',
	'Resources',
]

/**
 * The ids of the entries and captions of the MAIN list, in the order drawn.
 *
 * The navigation draws the main list first, then the footer and the settings
 * foldout. Those keep their own entries, so they are taken off the end by
 * name, and anything left over that should not be there fails the caller's
 * comparison rather than being filtered away here.
 *
 * @param page The page.
 */
async function mainMenuIds(page: Page): Promise<string[]> {
	const nav = page.getByTestId('cn-nav')
	await expect(nav).toBeVisible({ timeout: LOAD_TIMEOUT })
	await expect(nav.getByTestId('cn-nav-caption-StartCaption')).toBeVisible({
		timeout: LOAD_TIMEOUT,
	})
	const ids = await nav.evaluate((root) =>
		Array.from(
			root.querySelectorAll(
				'[data-testid^="cn-nav-entry-"], [data-testid^="cn-nav-caption-"]',
			),
		).map((node) =>
			(node.getAttribute('data-testid') ?? '').replace(
				/^cn-nav-(entry|caption)-/,
				'',
			),
		),
	)
	return ids.filter((id) => !BELOW_MAIN.includes(id))
}

test.describe('The simple structure', () => {
	// @e2e navigation-ia::a-contact-centre-agent-opens-pipelinq-on-a-new-instance
	test('the menu shows nine entries under four captions, and the app opens on the contact centre dashboard', async ({
		page,
	}) => {
		await withStructure(page, { menu_structure: 'simple', menu_modules: '' })
		await page.goto('/apps/pipelinq/', { timeout: LOAD_TIMEOUT })

		const ids = await mainMenuIds(page)
		expect(
			ids.filter((id) => !CAPTIONS.includes(id)),
			'the main menu must hold exactly the nine entries, in order',
		).toEqual(ENTRIES)
		expect(ids.filter((id) => CAPTIONS.includes(id))).toEqual(CAPTIONS)

		// The app root is the contact centre dashboard, not the sales overview.
		await expect(page).toHaveURL(/\/werkplek$/, { timeout: LOAD_TIMEOUT })
	})

	// @e2e navigation-ia::a-page-of-a-module-that-is-off-is-one-card-away
	test('a module that is off is one card away on the Modules page', async ({
		page,
	}) => {
		await withStructure(page, { menu_structure: 'simple', menu_modules: '' })
		await page.goto('/apps/pipelinq/modules', { timeout: LOAD_TIMEOUT })
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		const ids = await mainMenuIds(page)
		expect(ids).not.toContain('Leads')
		await expect(
			page.getByTestId('cn-nav').getByTestId('cn-nav-entry-ModulesMenu'),
		).toBeVisible()

		const card = page
			.getByTestId('cn-reports-grid')
			.locator('a[href$="/leads"]')
			.first()
		await expect(card).toBeVisible({ timeout: LOAD_TIMEOUT })
		await card.click()
		await expect(page).toHaveURL(/\/leads$/, { timeout: LOAD_TIMEOUT })
	})

	// @e2e navigation-ia::an-administrator-switches-the-sales-module-on
	test('a module that is switched on joins the menu under Modules', async ({
		page,
	}) => {
		await withStructure(page, {
			menu_structure: 'simple',
			menu_modules: 'sales',
		})
		await page.goto('/apps/pipelinq/werkplek', { timeout: LOAD_TIMEOUT })

		const ids = await mainMenuIds(page)
		const at = (id: string) => ids.indexOf(id)
		expect(at('ModulesCaption')).toBeGreaterThan(at('OrganisationsMenu'))
		for (const id of ['Dashboard', 'Leads', 'Prospects', 'Pipeline']) {
			expect(at(id), id).toBeGreaterThan(at('ModulesCaption'))
			expect(at(id), id).toBeLessThan(at('MoreCaption'))
		}
		// The other modules stay out.
		expect(ids).not.toContain('Products')
		expect(ids).not.toContain('MarketingGroup')
	})
})

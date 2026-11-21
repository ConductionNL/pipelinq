import { expect, test } from '@playwright/test'
/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The back office WCAG 2.1 A and AA sweep (platform-accessibility-statement
 * task 1.1).
 *
 *   @e2e accessibility-conformance::a-new-unlabeled-button-fails-the-nightly-run
 *   @e2e accessibility-conformance::a-fixed-known-issue-must-leave-the-list
 *
 * axe-core's own `axe.min.js` is injected into each sample page (design D3: no
 * `@axe-core/playwright`) and run with the wcag2a, wcag2aa, wcag21a and
 * wcag21aa tags, scoped to the app's content root so Nextcloud's own header
 * does not count against pipelinq. Every page's violations are compared with
 * `a11y-known-issues.json`: a violation not on the list fails the page and
 * names the page, the rule and the node; a listed one that no longer occurs
 * fails it too, so the list cannot keep fixed issues (design D4).
 *
 * Written from the change's scenarios; not yet run (it needs a seeded instance).
 *
 * @spec openspec/changes/platform-accessibility-statement/specs/accessibility-conformance/spec.md#requirement-the-back-office-is-checked-for-wcag-21-aa-on-every-nightly-run-req-pas-001
 */
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import {
	compareWithKnownIssues,
	validateKnownIssues,
} from './helpers/a11yKnownIssues.js'
import { BackofficeSample, samplePages } from './helpers/backofficeSample.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const AXE_PATH = require.resolve('axe-core/axe.min.js')
const KNOWN = JSON.parse(
	readFileSync(join(__dirname, 'a11y-known-issues.json'), 'utf8'),
)
const TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']
const CONTENT_ROOT = '#content-vue'
const RUN = Date.now().toString(36)

/** The axe result fields the comparison reads. */
interface AxeViolation {
	id: string
	help: string
	nodes: Array<{ target: string[] }>
}

const sample = new BackofficeSample()
let pages: ReturnType<typeof samplePages> = []

test.describe('back office WCAG 2.1 AA sweep', () => {
	test.describe.configure({ mode: 'serial' })
	test.setTimeout(120_000)

	test.beforeAll(async () => {
		expect(
			validateKnownIssues(KNOWN),
			'a11y-known-issues.json is malformed',
		).toEqual([])
		pages = samplePages(await sample.seed(RUN))
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
		test(`${name} has no WCAG 2.1 A or AA violation that is not on the known-issues list`, async ({
			page,
		}) => {
			const target = pages[index]
			expect(target.name).toBe(name)

			await gotoAppRoute(page, target.route)
			// Let the page's own fetches settle so the sweep sees the rendered page.
			await page
				.waitForLoadState('networkidle', { timeout: 20_000 })
				.catch(() => {})

			await page.addScriptTag({ path: AXE_PATH })
			const violations = (await page.evaluate(
				async ({ root, tags }) => {
					const axe = (window as unknown as { axe: any }).axe
					const result = await axe.run(root, {
						runOnly: { type: 'tag', values: tags },
						resultTypes: ['violations'],
					})
					return result.violations.map((v: any) => ({
						id: v.id,
						help: v.help,
						nodes: v.nodes.map((n: any) => ({ target: n.target })),
					}))
				},
				{ root: CONTENT_ROOT, tags: TAGS },
			)) as AxeViolation[]

			const { unexpected, resolved } = compareWithKnownIssues(
				name,
				violations,
				KNOWN,
			)
			expect(unexpected, `new WCAG violations on ${name}`).toEqual([])
			expect(resolved, `known issues on ${name} that no longer occur`).toEqual(
				[],
			)
		})
	}
})

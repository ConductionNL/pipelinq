/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for
 * openspec/specs/lead-scoring-win-probability/spec.md.
 *
 * The capability splits cleanly in two. The DECAY ARITHMETIC is an
 * `x-openregister-calculations` expression evaluated by OpenRegister on read
 * (`materialise: false`), keyed on `@self.updated` — a field OpenRegister owns
 * and no client may backdate, so "a lead untouched for 45 days" is a state a
 * browser cannot construct. Those four scenarios carry a reason-bearing
 * `@e2e exclude`.
 *
 * The SURFACING is entirely declarative — a manifest index column and a detail
 * data widget — and is asserted here, which is exactly where a "no new frontend
 * code" claim needs proving: the value has to actually arrive and render.
 */
import { test, expect, Page } from '@playwright/test'

import {
	openApp,
	navClick,
	assertNoHardError,
	dismissWalkthrough,
	dismissSupportDialog,
} from '../helpers/pipelinq'

/** Deep-link to a hash route and let the view settle. */
async function gotoHash(page: Page, hash: string): Promise<void> {
	await page.goto(`/apps/pipelinq/#${hash}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

// @e2e openspec/specs/lead-scoring-win-probability/spec.md#pipeline-list-shows-a-colour-banded-win-probability-column
test('the Leads index renders a Win % column through the shared banded cell widget', async ({ page }) => {
	await openApp(page)
	await navClick(page, 'Leads', /\/leads/)

	const content = page.locator('#content-vue')
	await expect(content.getByRole('heading', { name: 'Leads' }).first()).toBeVisible({ timeout: 20000 })

	// The manifest declares BOTH `probability` ("Probability") and
	// `winProbability` ("Win %") against the same `lead-probability` cell widget
	// (src/manifest.json). Asserting both are present is what distinguishes "the
	// decayed column was added" from "the existing probability column was
	// relabelled".
	const table = content.locator('table').first()
	await expect(table).toBeVisible({ timeout: 25000 })
	const headers = table.locator('thead th')
	await expect(headers.filter({ hasText: 'Win %' })).toHaveCount(1)
	await expect(headers.filter({ hasText: /^\s*Probability\s*$/ })).toHaveCount(1)

	// The column carries VALUES, not just a header: the seeded leads render a
	// 0-100 reading in the Win % cell. Found by header position so the assertion
	// survives a column reorder.
	const winIndex = await headers.evaluateAll((ths) =>
		ths.findIndex((th) => (th.textContent || '').trim().startsWith('Win %')),
	)
	expect(winIndex, 'the Win % header must be locatable').toBeGreaterThanOrEqual(0)
	const firstWinCell = table.locator('tbody tr').first().locator('td').nth(winIndex)
	await expect(firstWinCell).toBeVisible({ timeout: 20000 })
	await expect(firstWinCell).toHaveText(/\d/)

	await assertNoHardError(page)
})

// @e2e openspec/specs/lead-scoring-win-probability/spec.md#deal-page-shows-win-probability
test('the lead detail Deal widget renders winProbability beside value and status', async ({ page }) => {
	await openApp(page)

	// Reach a real lead through the API the app itself uses, then open its
	// declarative `type: "detail"` page (the Leads index row-click toggles the
	// filter sidebar rather than routing, as workflows/client-crud.spec.ts
	// documents for the equivalent Clients list).
	const lead = await page.evaluate(async () => {
		const res = await fetch('/index.php/apps/openregister/api/objects/pipelinq/lead?_limit=25', {
			headers: { 'OCS-APIREQUEST': 'true' },
		})
		const body = await res.json().catch(() => null)
		const rows: any[] = body?.results ?? body ?? []
		// Prefer a lead that actually carries the calculated field with a
		// distinctive value, so the render assertion below cannot be satisfied by
		// an unrelated number on the page.
		const withCalc = rows.find((r) => typeof r?.winProbability === 'number' && r.winProbability > 0)
		const row = withCalc ?? rows[0]
		return row
			? { id: row.id || row['@self']?.id, win: row.winProbability, prob: row.probability }
			: null
	})
	expect(lead, 'the register seed must provide at least one lead').toBeTruthy()

	// The decayed value is computed by OpenRegister and DELIVERED to the client —
	// the precondition for anything rendering it at all.
	expect(typeof lead!.win, 'winProbability must be served on the lead object').toBe('number')

	await gotoHash(page, `/leads/${lead!.id}`)

	// The Deal data widget declares
	// include: [title, value, probability, winProbability, expectedCloseDate,
	// priority, status] (src/manifest.json, widget id "lead-deal").
	const deal = page.locator('#content-vue')
		.locator('[data-testid="cn-widget"], .cn-detail-card, section, article')
		.filter({ has: page.getByText(/^\s*Deal\s*$/) })
		.first()
	await expect(deal).toBeVisible({ timeout: 25000 })

	// The neighbours the scenario names it "alongside", and then the decayed
	// value itself, rendered inside that same widget.
	await expect(deal).toContainText(/Probability/i)
	await expect(deal).toContainText(/Status/i)
	await expect(deal, 'the Deal widget must render the decayed winProbability value')
		.toContainText(String(lead!.win))

	await assertNoHardError(page)
})

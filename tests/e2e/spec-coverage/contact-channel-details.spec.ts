/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for
 * openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md.
 *
 * UI-observable scenarios: the Contact channels body section on the Client
 * and Contact detail pages (display + add affordance). Backend mapping
 * (vCard write-back/import, the upgrade backfill, and the segment rule
 * builder's dotted-field support) is excluded per-scenario in the spec
 * deltas themselves — those are PHP service methods with no browser-visible
 * surface, covered by PHPUnit.
 *
 * DATA. `lib/Settings/demo_seed_data.json` seeds "[Demo] Anna Jansen" with a
 * mobile phone and a LinkedIn profile, and "[Demo] Pieter de Vries" with a
 * mobile phone and a Mastodon profile (contact-channel-details). ci-seed.sh
 * force-imports the register and runs the demo seeder, so on CI these are
 * present. A client is picked BY NAME rather than "the first client" for the
 * same reason declarative-view-system.spec.ts does — the register is shared
 * across specs in the same run, and an arbitrary client may not carry the
 * channel data this scenario is actually about.
 */
import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { dismissWalkthrough, openApp } from '../helpers/pipelinq.ts'
import { FixtureSession } from '../workflows/helpers/fixtures.ts'

/** Navigate to a pipelinq SPA route, mounting or remounting as needed. */
async function gotoPage(page: Page, hash: string): Promise<void> {
	const target = `/apps/pipelinq${hash}`
	const alreadyMounted = page.url().includes('/apps/pipelinq')
	await page.goto(target)
	if (alreadyMounted) {
		await page.reload()
	}
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 20000 })
	await dismissWalkthrough(page)
}

test.describe('Contact channels body section', () => {
	// MEASURED, not guessed. The client detail page fires 48 API requests, and
	// on a warm local instance with no other load the last of them lands at
	// 22.9 s — the page is genuinely that expensive, and this spec is the only
	// one that waits for the section's DATA rather than its heading, so it is
	// the only one that has to outlast the whole page.
	//
	// Without this the three tests below have failed on EVERY development push
	// since they were added on 2026-09-04, always the same way: the section
	// renders its NcLoadingIcon and never leaves it inside the budget. The
	// sibling specs that touch this page (ia-tickets-and-projects,
	// request-client-contact-cascade, contactmoment-client-contact-cascade)
	// already carry the same marker for the same reason.
	//
	// The 48 requests include THREE identical GETs of the client object and
	// three of /api/customer-360/summary: `fetchObject` de-duplicates only
	// requests that are in flight at the same moment, and never reads the
	// `objects[type][id]` cache it just filled. Filed upstream — this marker
	// buys the suite honest time, it does not make the page fast.
	test.beforeEach(() => {
		test.slow()
	})

	// @e2e openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md#requirement-detail-pages-display-channels-as-a-linked-list-with-kind-chips
	test('a client with a seeded mobile phone and LinkedIn profile renders both in the Contact channels section', async ({
		page,
	}) => {
		// FixtureSession issues its reads with `page.evaluate(fetch('/index.php/…'))`,
		// so the page must already be ON the instance: a relative URL cannot be
		// parsed against about:blank, and the failure names the URL, not the cause.
		await openApp(page)
		const fx = new FixtureSession(page)
		const clients = await fx.list('client', { _limit: 100 })
		const anna = clients.find((c: any) => c.name === '[Demo] Anna Jansen')
		expect(
			anna,
			'lib/Settings/demo_seed_data.json must seed "[Demo] Anna Jansen" with '
				+ 'a mobile phone and a LinkedIn profile (contact-channel-details) — '
				+ 'ci-seed.sh must have run the demo seeder',
		).toBeTruthy()
		const clientId = String(anna.id || anna['@self']?.id)

		await gotoPage(page, `/clients/${clientId}`)

		const section = page.locator('[data-section-id="channels"]')
		await expect(section).toHaveCount(1)
		await expect(section).toBeVisible({ timeout: 15000 })

		// The seeded mobile phone renders as a tel: link.
		await expect(section.locator('a[href="tel:+31612345678"]')).toBeVisible()

		// The seeded LinkedIn profile renders as a clickable external link.
		await expect(
			section.locator(
				'a[href="https://www.linkedin.com/in/anna-jansen-demo"]',
			),
		).toBeVisible()
	})

	// @e2e openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md#requirement-detail-pages-display-channels-as-a-linked-list-with-kind-chips
	test('the Contact channels section renders on the Contact detail page too', async ({
		page,
	}) => {
		// FixtureSession issues its reads with `page.evaluate(fetch('/index.php/…'))`,
		// so the page must already be ON the instance: a relative URL cannot be
		// parsed against about:blank, and the failure names the URL, not the cause.
		await openApp(page)
		const fx = new FixtureSession(page)
		const contacts = await fx.list('contact', { _limit: 1 })
		expect(
			contacts.length,
			'ci-seed.sh must have seeded at least one contact',
		).toBeGreaterThan(0)
		const contactId = String(contacts[0].id || contacts[0]['@self']?.id)

		await gotoPage(page, `/contacts/${contactId}`)

		await expect(page.locator('[data-section-id="channels"]')).toHaveCount(1)
		// An unresolvable section degrades to an inline error card instead of
		// breaking the page — same guard declarative-view-system.spec.ts uses.
		await expect(
			page.locator('[data-testid^="cn-body-section-error-"]'),
		).toHaveCount(0)
	})

	// @e2e openspec/changes/contact-channel-details/specs/contact-channel-details/spec.md#requirement-channels-are-added-edited-and-removed-through-dedicated-modals
	test('clicking Add on the Emails list opens the add-email modal', async ({
		page,
	}) => {
		// FixtureSession issues its reads with `page.evaluate(fetch('/index.php/…'))`,
		// so the page must already be ON the instance: a relative URL cannot be
		// parsed against about:blank, and the failure names the URL, not the cause.
		await openApp(page)
		const fx = new FixtureSession(page)
		const clients = await fx.list('client', { _limit: 1 })
		expect(
			clients.length,
			'ci-seed.sh must have seeded at least one client',
		).toBeGreaterThan(0)
		const clientId = String(clients[0].id || clients[0]['@self']?.id)

		await gotoPage(page, `/clients/${clientId}`)

		const section = page.locator('[data-section-id="channels"]')
		await expect(section).toBeVisible({ timeout: 15000 })

		await section.getByTestId('add-email-button').click()

		await expect(page.getByTestId('channel-value-input')).toBeVisible({
			timeout: 10000,
		})
		await expect(page.getByTestId('channel-modal-save')).toBeVisible()
	})
})

/*
 * @e2e exclude write-back-maps-channel-arrays-to-typed-vcard-properties — PHP
 * service method (ContactVcardPropertyBuilder) writing to Nextcloud Contacts
 * via IManager, no Pipelinq UI surface; covered by PHPUnit
 * @e2e exclude import-maps-typed-vcard-properties-to-channel-arrays — PHP
 * service method (ContactDataBuilder) consumed by the import API; covered by
 * PHPUnit
 * @e2e exclude existing-records-backfill-channel-arrays-on-upgrade — an
 * upgrade repair step (IRepairStep), outside any user-driven browser flow;
 * covered by PHPUnit (BackfillContactChannelArraysTest)
 * @e2e exclude rule-fields-reach-into-array-of-object-properties — the
 * segment rule-builder UI has no page wiring real fieldOptions yet
 * (SegmentBuilder/SegmentRuleNode are reusable components awaiting a
 * forthcoming SegmentEditor surface, per src/registry.js); the engine is
 * covered by PHPUnit (SegmentServiceTest)
 * @e2e exclude client-and-contact-schemas-carry-typed-channel-arrays —
 * schema validation is enforced by OpenRegister at save time; exercised
 * indirectly by the scenarios above (the seeded objects would not have saved
 * otherwise) and by the register fragment's own JSON-schema validity
 */

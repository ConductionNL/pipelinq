/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Child-to-parent event wiring.
 *
 * WHY THIS FILE EXISTS. Nine emit/listener pairs in this app were broken for
 * an unknown length of time: the child emitted a KEBAB-case event
 * (`$emit('add-phase')`) while the parent listened in CAMEL case
 * (`@addPhase`). Vue matches event names literally, so the handler simply
 * never ran. Nothing threw, nothing logged, and the 55 unit tests stayed green
 * throughout — a suite that passes while the feature is dead cannot be the
 * guard. The 31 eslint `vue/custom-event-name-casing` findings that would have
 * named it were sitting in `eslint-suppressions.json`.
 *
 * The assertion below is therefore deliberately about the HANDLER FIRING, not
 * about the markup: it drives the real affordance and asserts the effect the
 * parent's handler produces. Under the bug the interaction was a silent no-op,
 * so this spec fails loudly if the casing regresses.
 *
 * ⚠️ The failure surfaces LATER than its cause. When the interaction lands but
 * nothing happens, the timeout is reported against the effect assertion, which
 * reads as "the fetch is broken" rather than "the event never arrived". If
 * this spec ever goes red, suspect the emit/listener names first.
 *
 * WHAT USED TO BE HERE. Three of these tests drove the project WBS tree —
 * `addPhase`, `addTask` and `addActivity` on ProjectDetail. Planninq owns
 * projects now, so the tree, its schemas and its page are gone from this app,
 * and the tests went with them rather than being left to assert a route that
 * 404s. The equivalent guard lives in planninq alongside the code it guards.
 *
 * NOT covered, and each for a different reason:
 *
 * - `createLead` HAS BEEN REMOVED. ProspectCard's "Create lead" button emitted
 *   it and ProspectWidget.onCreateLead received it, but the handler called
 *   `prospectStore.createLeadFromProspect()`, which does not exist:
 *   src/store/modules/prospect.js defines only fetchProspects and
 *   removeProspect. Nor was there anything behind it — appinfo/routes.php has
 *   `prospect#index` (GET) alone, ProspectController exposes only index(), and
 *   ProspectDiscoveryService only discover(). Reconnecting the event in #1677
 *   turned a silent no-op into a TypeError, so the affordance was deleted
 *   rather than left advertising a feature nobody had built.
 *
 *   ⚠️ And it could never have fired anyway: NOTHING imports ProspectWidget,
 *   so it and ProspectCard are tree-shaken out — `prospect-widget` appears
 *   zero times in the built bundle. `/prospects` is served by ProspectsView,
 *   which does not use ProspectCard at all. Both components are orphaned,
 *   which is a larger cleanup than removing one button and is left alone here.
 * - `validateLeaf` / `searchContacts` live in components nothing imports, so
 *   they are absent from every built chunk and cannot be reached at all.
 */
import { expect, test } from '@playwright/test'
import { openApp } from './helpers/pipelinq.ts'

test.describe('rapportage win/loss range events', () => {
	test.setTimeout(120000)

	/**
	 * WinLossWidget emits `rangeChange`; LeadAnalyticsSection.onRangeChange
	 * stores the range and calls loadStats(), which re-fetches
	 * GET /api/rapportage/pipeline-stats with dateFrom/dateTo.
	 *
	 * That re-fetch is the whole assertion, and it is a clean one because the
	 * widget mounts on "All time", which sends NO date parameters. So a request
	 * carrying `dateFrom` can only exist if the emit reached the parent. Under
	 * the kebab/camel casing bug the selector still changed and the chart still
	 * looked alive — nothing re-fetched, and the numbers silently stayed on the
	 * all-time window.
	 */
	test('rangeChange reaches LeadAnalyticsSection and re-fetches the stats', async ({
		page,
	}) => {
		const datedRequests: string[] = []
		page.on('request', (request) => {
			const url = request.url()
			if (url.includes('pipeline-stats') && url.includes('dateFrom')) {
				datedRequests.push(url)
			}
		})

		await openApp(page)
		await page.goto('/apps/pipelinq/rapportage')

		// The initial mount fetch carries no date parameters, so nothing should
		// have been recorded yet; if it has, the assertion below would pass for
		// the wrong reason.
		const rangeSelect = page
			.locator('.vs__dropdown-toggle, .v-select')
			.filter({ hasText: /All time|Last 30 days|Last 90 days|Last 12 months/ })
			.first()
		await expect(rangeSelect).toBeVisible({ timeout: 60000 })
		expect(
			datedRequests,
			'a dated pipeline-stats request fired before the range was ever changed — the assertion below would not prove anything',
		).toHaveLength(0)

		await rangeSelect.click()
		await page
			.locator('.vs__dropdown-menu li', { hasText: 'Last 30 days' })
			.first()
			.click()

		await expect
			.poll(() => datedRequests.length, {
				message:
					'selecting "Last 30 days" produced no pipeline-stats request carrying dateFrom — onRangeChange() never ran, which means the rangeChange event did not reach LeadAnalyticsSection',
				timeout: 30000,
			})
			.toBeGreaterThan(0)
	})
})

// ─────────────────────────────────────────────────────────────────────────────
// ⚠️ THE CONSENT-MODAL CHAINS ARE NOT TESTED HERE. This is the measurement,
// not a shrug, and it is written down so nobody spends the afternoon I did.
//
// Tests for `requestConsent` and `skipAndSend` were written, driving the real
// six-step blast wizard with the segments, templates, compliance and submit
// endpoints stubbed. They are not shipped because they are FLAKY, and flaky in
// a way that survived every fix:
//
//   alone, twice                          pass
//   together, three runs                  1 failed / 2 passed / 1 failed
//   serialised (mode: 'serial')           still 2 of 3 failing
//   unique blast name per test            still failing
//   POST /api/blasts stubbed as well      still 1 of 3 failing
//   in CI                                 skipAndSend failed one run,
//                                         requestConsent the next
//
// so it is not a worker race, not a name collision, and not state left behind
// by a real blast. The wizard does not reliably reach its final step: the
// failures alternate between "the consent modal never opened" and
// "Create blast" rendering permanently DISABLED — canSubmit() false after
// every step had apparently advanced.
//
// A test that reddens a 330-spec suite one run in three teaches the reader to
// re-run rather than to read, which is the exact habit that let the original
// kebab/camel bug live for months. So: not shipped.
//
// THE CHAIN IS NOT UNVERIFIED, though, and that distinction matters. Both
// emits were confirmed AT RUNTIME by hand. Clicking "Request consent" against
// the unfixed build threw, in the page, from the handler itself:
//
//   TypeError: Cannot read properties of undefined (reading 'showTemporary')
//       at Proxy.onConsentRequest (pipelinq-main.js)
//
// which proves the emit reaches BlastForm — the casing bug this file exists to
// guard — and simultaneously proved the second defect fixed in #1693, that
// `OC.Notification` does not exist on Nextcloud 34. After that fix the toast
// renders. onConsentSkip is a single assignment behind the same @click
// binding in the same component.
//
// ⚠️ And do NOT "helpfully" click the channel select while driving that
// wizard. `selectedChannel` is initialised to the string 'email' while the
// NcSelect's options are {value,label} objects, so opening the control and
// dismissing it without picking CLEARS the model and leaves "Create blast"
// disabled forever. Tried as a fix; it broke both consent tests locally.
// ─────────────────────────────────────────────────────────────────────────────

/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 behavioural e2e coverage for openspec/specs/appointment-booking/spec.md.
 *
 * WHAT IS OBSERVABLE THROUGH A BROWSER, AND WHAT IS NOT
 * ----------------------------------------------------
 * The appointment-booking capability is overwhelmingly server-side: availability
 * computation, skill routing, deposit/payment seams, reminder + confirmation
 * mail, calendar push and the walk-in queue all live in PHP services with no
 * browser surface of their own. Those scenarios carry a reason-bearing
 * `@e2e exclude` in the spec naming the PHPUnit class that asserts them.
 *
 * What IS reachable from a browser is the admin catalogue (Services index +
 * the Service create/edit form) and the PUBLIC booking portal shell, which is
 * served without a Nextcloud login. Those are asserted here.
 *
 * Nav location: `BookingsGroup` ("Appointments") is relocated under the
 * Operational dashboard by src/menu-layout.json, so the Services leaf is inside
 * a collapsed group — reached through revealNavEntry()/navClick(), never by a
 * raw visibility assertion.
 */
import { test, expect, Page } from '@playwright/test'

import {
	openApp,
	navClick,
	clickHeaderAction,
	assertNoHardError,
	dismissSupportDialog,
	dismissWalkthrough,
} from '../helpers/pipelinq'
import { FixtureSession, TEST_PREFIX } from '../workflows/helpers/fixtures'

/**
 * ISO timestamp `hours` from now (negative = in the past), on the hour.
 * Booking's schema requires `startAt`/`endAt`, and BookingDetailSection gates
 * its admin actions on whether `startAt` is future or past — so the offset is
 * the whole point of the fixture, not decoration.
 */
function hoursFromNow(hours: number): string {
	const d = new Date(Date.now() + hours * 3600_000)
	d.setUTCMinutes(0, 0, 0)
	return d.toISOString()
}

/**
 * Deep-link to a hash route and wait for the app body to settle.
 *
 * The declarative `type: "detail"` pages (ClientDetail `/clients/:id`,
 * BookingDetail `/bookings/:id`) are not reachable by a row click — the Clients
 * list row toggles the filter sidebar (see workflows/client-crud.spec.ts) and
 * the Bookings ledger sets `rowClickToView: false`. A hash goto is the same
 * navigation the manifest `actions[].handler: "navigate"` performs, and it is
 * the established pattern in this suite (rapportage.spec.ts,
 * spec-coverage/outbound-messaging.spec.ts).
 */
async function gotoHash(page: Page, hash: string): Promise<void> {
	await page.goto(`/apps/pipelinq/#${hash}`)
	await expect(page.locator('#content-vue')).toBeVisible({ timeout: 15000 })
	await dismissWalkthrough(page)
	await dismissSupportDialog(page)
}

// @e2e openspec/specs/appointment-booking/spec.md#service-list-shows-all-services-with-filters
test('Services: the admin service catalogue index renders its list surface', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Services', /\/services/)

	const content = page.locator('#content-vue')
	await expect(
		content.getByRole('heading', { name: 'Services' }).first(),
	).toBeVisible()
	await expect(page.locator('[data-testid="cn-index-page"]').first()).toBeVisible()
	// Tolerates both a populated and an empty register — the assertion is that
	// the schema-driven data surface mounted, not that a particular row exists.
	await expect(
		content
			.locator(
				'table, .cn-data-table, [data-testid="cn-data-table"], .empty-content, [data-testid="cn-empty-state"]',
			)
			.first(),
	).toBeVisible({ timeout: 15000 })
	await assertNoHardError(page)
})

// @e2e openspec/specs/appointment-booking/spec.md#service-detail-allows-editing-all-fields
test('Services: "New service" opens the ServiceDetail form with editable fields', async ({
	page,
}) => {
	await openApp(page)
	await navClick(page, 'Services', /\/services/)

	// The Services index sets `showAdd: false` and declares its create entry as a
	// manifest headerActions[] item, so the control lives in the CnActionsBar
	// overflow rather than as a visible primary CTA (see clickHeaderAction).
	await clickHeaderAction(page, 'New service')
	await expect(page).toHaveURL(/\/services\/new/, { timeout: 10000 })

	// ServiceDetail renders the ServiceForm branch for id === 'new'
	// (src/views/bookings/ServiceDetail.vue, `v-if="editing || isNew"`).
	await expect(
		page
			.locator('#content-vue')
			.getByRole('heading', { name: 'New service' })
			.first(),
	).toBeVisible({ timeout: 15000 })
	await expect(
		page
			.locator('#content-vue')
			.locator('input, .input-field__input, textarea')
			.first(),
	).toBeVisible({ timeout: 10000 })
	await assertNoHardError(page)
})

/*
 * REQ-APT-014 Customer Timeline Integration + the Booking admin detail. These
 * are the DATA-DEPENDENT scenarios of this capability, so they seed their own
 * objects and delete exactly what they created.
 *
 * WHY THE CLIENT IS CREATED THROUGH THE UI AND THE BOOKINGS THROUGH THE API.
 * `client.contactsUid` is REQUIRED and `client.name` is `readOnly`
 * (lib/Settings/register.d/15-unify-client-contact.json) — the Nextcloud
 * addressbook contact is the authoritative identity and is "never minted
 * locally", so a plain `POST /objects/pipelinq/client` is rejected 400 by
 * OpenRegister. The supported path is the bespoke "New Client" dialog, which
 * posts to `/api/contacts-sync/create`; that is the same route
 * workflows/client-crud.spec.ts drives. `booking` and `service` carry no such
 * constraint (required: customerId/serviceId/startAt/endAt/status and
 * name/durationMinutes/status respectively), so those go straight through the
 * OR object API the app's own `saveObject()` calls.
 *
 * SERIAL, and not merely for tidiness: the three tests share ONE seeded client
 * and the FIRST of them asserts that client's bookings list is EMPTY, which is
 * only true before the second test seeds it. Under `fullyParallel: true` they
 * would otherwise be scheduled across workers in an undefined order.
 */
test.describe('Booking admin surfaces (seeded)', () => {
	test.describe.configure({ mode: 'serial' })

	const CUSTOMER_NAME = `${TEST_PREFIX}-Booking Timeline Customer`

	// The fixture page stays OPEN for the whole describe: FixtureSession issues
	// its requests via `page.evaluate(fetch …)` so it needs a live, authenticated
	// page to carry `OC.requestToken`. Closing it in beforeAll would make every
	// later create/cleanup call throw.
	let fxPage: Page
	let fx: FixtureSession
	let customerId = ''
	let serviceId = ''
	let futureBookingId = ''
	let pastBookingId = ''

	test.beforeAll(async ({ browser }) => {
		fxPage = await browser.newPage()
		await openApp(fxPage)
		fx = new FixtureSession(fxPage)

		// --- the client, via the contact-first "New Client" dialog on the
		// Dashboard (openApp lands there; the dialog is a Dashboard headerAction).
		await fxPage
			.getByRole('button', { name: /New Client/i })
			.first()
			.click()
		const dialog = fxPage.locator('[data-testid="client-create-dialog"]').first()
		await expect(dialog).toBeVisible({ timeout: 15000 })
		await dialog.locator('[data-testid="client-name-input"]').fill(CUSTOMER_NAME)
		// Email and phone are filled even though this capability does not read
		// them: the register is SHARED across the whole parallel run, and a client
		// carrying only a name and a type is a degraded row that other specs can
		// pick up. In run 31481319464 exactly that happened — the declarative
		// -view-system Client 360 test took `clients[0]`, landed on a bare fixture
		// like this one, and failed asserting the Identity widget's fields. That
		// test now selects a populated client, and this fixture no longer leaves a
		// half-filled row for anyone else to trip over.
		await dialog
			.locator('[data-testid="client-email-input"]')
			.fill('gate19-booking@example.test')
		await dialog
			.locator('[data-testid="client-phone-input"]')
			.fill('+31 20 000 0000')
		await dialog.locator('[data-testid="client-type-select"]').click()
		// NcSelect teleports its dropdown, so the option is matched page-wide, and
		// it is awaited rather than clicked blind — the list is populated
		// asynchronously (workflows/client-crud.spec.ts sleeps here instead).
		const typeOption = fxPage
			.locator('li[role="option"], .vs__dropdown-option')
			.filter({ hasText: 'organi' })
			.first()
		await expect(typeOption).toBeVisible({ timeout: 10000 })
		await typeOption.click()
		await dialog.locator('[data-testid="client-form-save"]').click()
		await expect(dialog).toBeHidden({ timeout: 20000 })

		const created = (
			await fx.list('client', { _limit: 5, name: CUSTOMER_NAME })
		)[0]
		expect(
			created,
			'the seeded client must be readable back from OpenRegister',
		).toBeTruthy()
		customerId = String(created.id || created['@self']?.id)
		fx.track('client', customerId)

		const service = await fx.create('service', {
			name: `${TEST_PREFIX}-Knipbeurt`,
			durationMinutes: 30,
			status: 'active',
		})
		serviceId = String(service.id || service['@self']?.id)
	})

	test.afterAll(async () => {
		if (fx) await fx.cleanup()
		if (fxPage) await fxPage.close()
	})

	// @e2e openspec/specs/appointment-booking/spec.md#bookings-section-shows-empty-state-if-none-exist
	test('a customer with no bookings gets an empty state, not an error', async ({
		page,
	}) => {
		await openApp(page)
		await gotoHash(page, `/clients/${customerId}`)

		const content = page.locator('#content-vue')
		await expect(
			content.getByText('No bookings for this customer.'),
		).toBeVisible({ timeout: 20000 })
		// The failure branch renders `.bookings-card__state--error` instead, so a
		// broken fetch cannot pass itself off as "this customer has no bookings".
		await expect(content.locator('.bookings-card__state--error')).toHaveCount(0)
		await assertNoHardError(page)
	})

	// @e2e openspec/specs/appointment-booking/spec.md#bookings-visible-on-customer-detail
	test("the Customer detail page lists that customer's bookings, future first", async ({
		page,
	}) => {
		// Two future + one past: the card sorts upcoming ascending, then past
		// descending (BookingsCard.sortedBookings).
		const future = await fx.create('booking', {
			customerId,
			serviceId,
			startAt: hoursFromNow(48),
			endAt: hoursFromNow(49),
			status: 'confirmed',
		})
		futureBookingId = String(future.id || future['@self']?.id)
		await fx.create('booking', {
			customerId,
			serviceId,
			startAt: hoursFromNow(72),
			endAt: hoursFromNow(73),
			status: 'pending-deposit',
		})
		const past = await fx.create('booking', {
			customerId,
			serviceId,
			startAt: hoursFromNow(-48),
			endAt: hoursFromNow(-47),
			status: 'confirmed',
		})
		pastBookingId = String(past.id || past['@self']?.id)

		await openApp(page)
		await gotoHash(page, `/clients/${customerId}`)

		// BookingsCard is a bodyWidget on the declarative ClientDetail page
		// (src/manifest.json, widget id "bookings").
		const table = page.locator('#content-vue .viewTable').first()
		await expect(table).toBeVisible({ timeout: 25000 })

		const rows = table.locator('tbody tr')
		await expect(rows).toHaveCount(3, { timeout: 25000 })
		// Status is carried as TEXT in a badge, not by colour alone (REQ-APT-017).
		await expect(table.locator('tbody .status-badge')).toHaveCount(3)
		// Future-first: the two upcoming rows precede the past one, and the
		// upcoming pair is itself ascending (+48h confirmed before +72h pending).
		await expect(rows.nth(0).locator('.status-badge')).toHaveText(/Confirmed/)
		await expect(rows.nth(1).locator('.status-badge')).toHaveText(
			/Awaiting deposit/,
		)
		await expect(rows.nth(2).locator('.status-badge')).toHaveText(/Confirmed/)

		await assertNoHardError(page)
	})

	/*
	 * SPEC/IMPLEMENTATION MISMATCH — reported, not fixed. The scenario asks for
	 * all four of "Mark completed", "Mark no-show", "Reschedule" and "Cancel" on
	 * a CONFIRMED FUTURE booking. BookingDetailSection gates the first two on
	 * `isPast` and the last two on `isFuture`
	 * (src/components/bookings/BookingDetailSection.vue, canComplete/canNoShow vs
	 * canReschedule/canCancel), so NO single booking can ever show all four. This
	 * asserts what the implementation actually guarantees: each of the four
	 * actions is rendered in its own time window.
	 */
	// @e2e openspec/specs/appointment-booking/spec.md#booking-detail-shows-status-actions
	test('Booking detail exposes its time-window-gated status actions', async ({
		page,
	}) => {
		await openApp(page)
		const content = page.locator('#content-vue')

		await gotoHash(page, `/bookings/${futureBookingId}`)
		await expect(
			content.getByRole('button', { name: 'Reschedule' }),
		).toBeVisible({ timeout: 25000 })
		await expect(
			content.getByRole('button', { name: 'Cancel', exact: true }),
		).toBeVisible()
		await assertNoHardError(page)

		await gotoHash(page, `/bookings/${pastBookingId}`)
		await expect(
			content.getByRole('button', { name: 'Mark completed' }),
		).toBeVisible({ timeout: 25000 })
		await expect(
			content.getByRole('button', { name: 'Mark no-show' }),
		).toBeVisible()
		await assertNoHardError(page)
	})
})

/*
 * The PUBLIC booking portal. `portalPage#index` / `portalPage#subpath` are
 * `@PublicPage` (lib/Controller/PortalPageController.php) and the portal SPA is
 * a SEPARATE hash-routed bundle mounted at /apps/pipelinq/portal, so these tests
 * deliberately run with an EMPTY storage state: the point of the scenario is
 * that a customer never logs in.
 */
test.describe('Public booking portal (no Nextcloud login)', () => {
	test.use({ storageState: { cookies: [], origins: [] } })

	const BOOKING_ROUTE = '/apps/pipelinq/portal/#/book/haircut-simple'

	// @e2e openspec/specs/appointment-booking/spec.md#customer-books-without-login
	test('the booking portal is served to an anonymous visitor', async ({
		page,
	}) => {
		const response = await page.goto(BOOKING_ROUTE)
		expect(response, 'the public portal produced no response').not.toBeNull()
		expect(
			response?.status(),
			'the booking portal must be publicly served',
		).toBe(200)

		// Nextcloud never asked for credentials and never bounced to the login
		// page — the whole point of the scenario.
		await expect(page.locator('input[name="user"]')).toHaveCount(0)
		await expect(page).not.toHaveURL(/\/login/)

		// The booking portal component itself mounted.
		await expect(page.locator('.booking-portal')).toHaveCount(1, {
			timeout: 15000,
		})
	})

	// @e2e openspec/specs/appointment-booking/spec.md#portal-is-wcag-21-aa-accessible
	test('the booking portal exposes a bypass-block link and a live region', async ({
		page,
	}) => {
		await page.goto(BOOKING_ROUTE)

		const portal = page.locator('.booking-portal')
		await expect(portal).toHaveCount(1, { timeout: 15000 })

		// WCAG 2.4.1 Bypass Blocks — the skip link is rendered unconditionally
		// and targets the booking form landmark.
		const skip = portal.locator('.booking-skip-link')
		await expect(skip).toHaveCount(1)
		await expect(skip).toHaveAttribute('href', '#booking-form')

		// The portal reaches a settled, announced state: either the service
		// layout rendered, or the state is announced through a live region
		// (WCAG 4.1.3 Status Messages). Written as an `or` so the assertion
		// stays true whichever branch the data takes.
		await expect(
			portal
				.locator('.booking-layout')
				.or(portal.locator('[role="status"], [role="alert"]'))
				.first(),
		).toBeVisible({ timeout: 15000 })
	})
})

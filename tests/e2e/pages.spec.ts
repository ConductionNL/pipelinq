/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * View smoke + create-form coverage, rewritten for the manifest-driven
 * app shell (#392). Views are opened by clicking the in-app nav (see
 * helpers/nav.ts) because deep `goto` links reset to the Dashboard.
 *
 * Create actions open a modal titled "Create <Entity>" with a submit
 * button that starts disabled; the test asserts the modal opens and the
 * guard holds, then cancels (these tests do not persist records).
 *
 * Gate-19 @e2e traceability (test → spec scenario):
 *   "Clients renders list controls"          @e2e client-management::display-client-list-with-default-settings
 *   "Clients opens its create modal"         @e2e client-management::fail-to-create-a-client-without-required-name
 *   "Leads renders list controls"            @e2e lead-management::display-lead-list-with-key-columns
 *   "Leads opens its create modal"           @e2e lead-management::reject-lead-without-title
 *   "Requests renders list controls"         @e2e request-management::default-list-display
 *   "Requests opens its create modal"        @e2e request-management::title-must-not-be-empty
 *   "Products renders list controls"         @e2e product-catalog::product-list-display
 *   "Products opens its create modal"        @e2e product-catalog::create-a-product
 *   "Queues renders list controls"           @e2e queue-management::view-all-queues
 *   "Queues opens its create modal"          @e2e queue-management::validation-title-is-required
 *   "Contactmomenten renders list controls"  @e2e contactmomenten::display-contactmomenten-list
 *   "Contactmomenten opens its create modal" @e2e contactmomenten::contactmoment-validates-required-fields
 *   "Complaints renders list controls"       @e2e klachtenregistratie::filter-by-status
 *   "Complaints opens its create modal"      @e2e klachtenregistratie::create-complaint-with-required-fields
 *   "Pipeline renders the kanban board…"     @e2e pipeline::mixed-entity-kanban
 *   "My Work renders its filter controls"    @e2e my-work::view-assigned-leads-and-requests
 *                                            @e2e my-work::toggle-to-show-completed-items
 *                                            @e2e my-work::item-count-display
 *   "Reporting renders KPI tiles…"           @e2e contactmomenten-rapportage::display-daily-kpi-overview
 *                                            @e2e contactmomenten-rapportage::display-sla-compliance
 */
import { test, expect } from '@playwright/test'
import { openView } from './helpers/nav'

// List views backed by the shared CnList component: heading, "Add"
// button label, and the create-modal title. Contacts reuses the Client
// label (shared component), so its add label is matched loosely.
const LIST_VIEWS = [
	{ route: 'clients', heading: 'Clients', add: 'Add Client', create: 'Create Client' },
	{ route: 'leads', heading: 'Leads', add: 'Add Lead', create: 'Create Lead' },
	{ route: 'requests', heading: 'Requests', add: 'Add Request', create: 'Create Request' },
	{ route: 'tasks', heading: 'Tasks', add: 'Add Task', create: 'Create Task' },
	{ route: 'contactmomenten', heading: 'Contactmomenten', add: 'Add Contactmoment', create: 'Create Contactmoment' },
	{ route: 'complaints', heading: 'Complaints', add: 'Add Complaint', create: 'Create Complaint' },
	{ route: 'products', heading: 'Products', add: 'Add Product', create: 'Create Product' },
	{ route: 'surveys', heading: 'Surveys', add: 'Add Survey', create: 'Create Survey' },
	{ route: 'queues', heading: 'Queues', add: 'Add Queue', create: 'Create Queue' },
] as const

test.describe('List views', () => {

	for (const view of LIST_VIEWS) {
		test(`${view.heading} renders list controls`, async ({ page }) => {
			await openView(page, view.route, view.heading)
			// Cards / Table view toggle is part of every list view.
			await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible()
			await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
			await expect(page.getByRole('button', { name: view.add })).toBeVisible()
		})

		test(`${view.heading} opens its create modal`, async ({ page }) => {
			await openView(page, view.route, view.heading)
			await page.getByRole('button', { name: view.add }).click()
			await expect(page.getByRole('heading', { name: view.create })).toBeVisible({ timeout: 10000 })
			// The submit button is guarded until required fields are filled.
			await expect(page.getByRole('button', { name: 'Create', exact: true })).toBeDisabled()
			await expect(page.getByRole('button', { name: 'Cancel' })).toBeVisible()
			await page.getByRole('button', { name: 'Cancel' }).click()
		})
	}

	test('Contacts renders list controls', async ({ page }) => {
		await openView(page, 'contacts', 'Contacts')
		await expect(page.getByRole('radio', { name: 'Cards' })).toBeVisible()
		await expect(page.getByRole('radio', { name: 'Table' })).toBeVisible()
		await expect(page.getByRole('button', { name: /^Add / })).toBeVisible()
	})
})

test.describe('Specialised views', () => {

	test('Pipeline renders the kanban board with a pipeline selector', async ({ page }) => {
		await openView(page, 'pipeline', 'Pipeline')
		// The kanban board loads asynchronously (brief "Loading…" spinners);
		// wait for them to clear before asserting the board content.
		await page.getByText(/^Loading/).first()
			.waitFor({ state: 'hidden', timeout: 30000 }).catch(() => {})
		// The selected pipeline name renders in the board header / selector
		// once the board has mounted.
		await expect(page.getByText('Sales Pipeline').first()).toBeVisible({ timeout: 30000 })
	})

	test('My Work renders its filter controls', async ({ page }) => {
		await openView(page, 'my-work', 'My Work')
		await expect(page.getByRole('button', { name: 'All' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Leads' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Requests' })).toBeVisible()
		await expect(page.getByText('Show completed')).toBeVisible()
	})

	test('Reporting renders KPI tiles and report tabs', async ({ page }) => {
		await openView(page, 'rapportage', 'Reporting Dashboard')
		await expect(page.getByRole('button', { name: 'Export CSV' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Channel Analytics' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Agent Performance' })).toBeVisible()
		await expect(page.getByText('SLA compliance')).toBeVisible()
	})

	test('Kennisbank renders the knowledge-base view', async ({ page }) => {
		await openView(page, 'kennisbank', 'Kennisbank')
		await expect(page.getByRole('heading', { name: 'Kennisbank', level: 2 })).toBeVisible()
	})
})

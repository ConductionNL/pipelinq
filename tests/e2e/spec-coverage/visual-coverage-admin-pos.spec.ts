/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-26 (visual-coverage) e2e proof for the four POS master-data page
 * components that live on the Nextcloud ADMIN page rather than in the SPA.
 *
 * WHERE THESE PAGES ACTUALLY LIVE. The `nav-ia-cleanup` change moved POS staff
 * and POS roles off the app navigation onto `/settings/admin/pipelinq`, which is
 * its own webpack entry (`src/settings.js` → `views/settings/Settings.vue`,
 * mounted on `#pipelinq-settings`) with NO vue-router. So:
 *
 *   PosStaffList.vue  rendered by PosStaffManager, an NcSettingsSection
 *   PosStaffForm.vue  rendered by PosStaffFormDialog, opened from that list
 *   PosRoleList.vue   rendered by PosRoleManager, an NcSettingsSection
 *   PosRoleForm.vue   rendered by PosRoleFormDialog, opened from that list
 *
 * There is no route for any of the four, and the forms are unreachable without
 * a click — which is why these are click-driven journeys rather than deep links.
 *
 * ⚠️ NOTE FOR GATE-26 MAINTAINERS. The gate lists all four as PAGE components
 * because its router heuristic reads the literal string "vue-router" out of the
 * host files' comments — comments whose actual content is that there IS NO
 * vue-router here (`_import_graph()` treats any file containing "vue-router" as
 * a router module, so anything those files import is classified routable). They
 * are child components of the admin settings page, not routable pages. They are
 * covered here with real tests anyway: the surfaces are genuinely reachable and
 * genuinely worth a regression test, so covering them is strictly better than
 * arguing with the classifier via a waiver.
 *
 * PRECONDITIONS, ASSERTED RATHER THAN ASSUMED. Both sections are rendered
 * `v-if="isAdmin && isConfigured"`, where `isConfigured` is `!!config.register`.
 * `ci-seed.sh` step 1 POSTs `/api/settings/reimport` and FAILS THE JOB unless it
 * returns 200; `SettingsLoadService` writes the `register` app-config key on
 * that path, and step 4 re-verifies the register exists in OpenRegister. The
 * suite authenticates as admin (global-setup.ts). Each test asserts the section
 * heading first, so a broken precondition names itself instead of surfacing as
 * "the staff list is missing".
 */

import type { Page } from '@playwright/test'

import { expect, test } from '@playwright/test'
import { nextcloudErrorPage } from '../helpers/pipelinq.ts'

/**
 * Open `/settings/admin/pipelinq` and wait for the pipelinq settings app to
 * mount.
 *
 * `templates/settings/admin.php` renders `<div id="pipelinq-settings">` and
 * `src/settings.js` mounts INSIDE it (Vue 3 `mount()` renders into the host
 * rather than replacing it), so the id survives and an unmounted shell stays
 * empty — making `toBeVisible` a real mount signal rather than an HTTP one.
 *
 * @param page The Playwright page.
 */
async function openAdminSettings(page: Page): Promise<void> {
	const response = await page.goto('/settings/admin/pipelinq')
	expect(response, 'navigation produced no response').not.toBeNull()
	expect(response?.status(), 'the admin settings page must be served').toBe(200)
	await expect(nextcloudErrorPage(page)).toHaveCount(0)
	await expect(page.locator('#pipelinq-settings')).toBeVisible({ timeout: 20000 })
}

// ── src/views/pos/PosStaffList.vue ───────────────────────────────────────────
test('PosStaffList: the POS staff admin section mounts src/views/pos/PosStaffList.vue', async ({
	page,
}) => {
	await openAdminSettings(page)

	// Precondition: PosStaffManager rendered at all (isAdmin && isConfigured).
	await expect(
		page.getByRole('heading', { name: 'POS staff' }).first(),
	).toBeVisible({ timeout: 20000 })

	// PosStaffList reads `/api/pos/staff` with plain axios rather than through
	// the object store, and its root + header sit above the loading `v-if`, so
	// both render whether the fetch succeeds, fails or returns nothing.
	const list = page.locator('.pos-staff-list')
	await expect(list).toBeVisible({ timeout: 15000 })
	await expect(
		list.getByRole('button', { name: 'New staff member' }),
	).toBeVisible()
})

// ── src/views/pos/PosStaffForm.vue ───────────────────────────────────────────
test('PosStaffForm: the POS staff create dialog mounts src/views/pos/PosStaffForm.vue', async ({
	page,
}) => {
	await openAdminSettings(page)
	await expect(
		page.getByRole('heading', { name: 'POS staff' }).first(),
	).toBeVisible({ timeout: 20000 })

	const list = page.locator('.pos-staff-list')
	await expect(list).toBeVisible({ timeout: 15000 })
	await list.getByRole('button', { name: 'New staff member' }).click()

	// PosStaffFormDialog wraps the form in an NcDialog, which teleports to
	// document.body — so the form is matched page-wide, not inside the list.
	const form = page.locator('.pos-staff-form')
	await expect(form).toBeVisible({ timeout: 15000 })
	// `staffId` is '' on the create path, so `isNew` is true and the form's own
	// header reads "New staff member" — proof the CREATE branch mounted.
	await expect(
		form.getByRole('heading', { name: 'New staff member' }),
	).toBeVisible()
	await expect(form.locator('.pos-staff-form__fields')).toBeVisible()
})

// ── src/views/pos/PosRoleList.vue ────────────────────────────────────────────
test('PosRoleList: the POS roles admin section mounts src/views/pos/PosRoleList.vue', async ({
	page,
}) => {
	await openAdminSettings(page)

	await expect(
		page.getByRole('heading', { name: 'POS roles' }).first(),
	).toBeVisible({ timeout: 20000 })

	// PosRoleList is nothing but a CnIndexPage, and the admin page hosts several
	// of them — so identify THIS one by the title its own component passes.
	// CnIndexPage's root div and CnPageHeader's <h1> are both unconditional, so
	// this holds even when the `posRole` collection fetch comes back empty.
	const roleIndex = page.locator('[data-testid="cn-index-page"]').filter({
		has: page.locator('[data-testid="cn-page-title"]', {
			hasText: 'POS roles',
		}),
	})
	await expect(roleIndex).toHaveCount(1, { timeout: 15000 })
	await expect(roleIndex.first()).toBeVisible()
})

// ── src/views/pos/PosRoleForm.vue ────────────────────────────────────────────
test('PosRoleForm: the POS role create dialog mounts src/views/pos/PosRoleForm.vue', async ({
	page,
}) => {
	await openAdminSettings(page)
	await expect(
		page.getByRole('heading', { name: 'POS roles' }).first(),
	).toBeVisible({ timeout: 20000 })

	const roleIndex = page
		.locator('[data-testid="cn-index-page"]')
		.filter({
			has: page.locator('[data-testid="cn-page-title"]', {
				hasText: 'POS roles',
			}),
		})
		.first()
	await expect(roleIndex).toBeVisible({ timeout: 15000 })

	// CnIndexPage's `showAdd` defaults to true, so CnActionsBar always renders
	// the primary Add button with `data-testid="cn-cta-primary"`; it emits
	// `add`, which PosRoleList forwards as `create` to PosRoleManager. The
	// empty-state action is offered as an alternative because on a register with
	// no roles the operator's real entry point is the one labelled "New role".
	const addRole = roleIndex
		.locator('[data-testid="cn-cta-primary"]')
		.or(roleIndex.getByRole('button', { name: 'New role' }))
		.first()
	await expect(addRole).toBeVisible({ timeout: 15000 })
	await addRole.click()

	// PosRoleFormDialog's NcDialog teleports to document.body.
	const form = page.locator('.pos-role-form')
	await expect(form).toBeVisible({ timeout: 15000 })
	await expect(form.getByRole('heading', { name: 'New POS role' })).toBeVisible()
	await expect(form.locator('.pos-role-form__fields')).toBeVisible()
})

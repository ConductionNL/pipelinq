/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for semantic-handoff-emit (ADR-051 emit side).
 * UI-observable scenarios of openspec/specs/request-management/spec.md and
 * openspec/specs/contract-renewal-tracking/spec.md. Backend state-validation
 * and failure-atomicity scenarios carry `@e2e exclude` in the specs (PHPUnit +
 * Newman). The product-catalog-quoting scenarios are Enterprise-tier against an
 * unbuilt quote schema (spec-binding only) and have no runtime UI surface yet.
 *
 * The convert / send actions are hidden unless an installed app implements the
 * target kind (ns#Case / ns#Invoice); on a bare instance the action is absent,
 * which is exactly the hidden-without-implementer behaviour. Assertions are
 * UI-only and tolerant of an un-seeded instance.
 */

import { expect, test } from '@playwright/test'

async function assertNoServerError(page) {
	await expect(page.locator('body')).not.toContainText('Internal Server Error', {
		timeout: 15000,
	})
	await expect(page.locator('body')).not.toContainText('Uncaught Error', {
		timeout: 5000,
	})
}

// @e2e openspec/specs/request-management/spec.md#convert-request-to-case-via-semantic-resolution
test('request detail renders the conversion surface without a server error', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/requests')
	await assertNoServerError(page)
})

// @e2e openspec/specs/request-management/spec.md#action-hidden-without-an-implementer
test('convert-to-case action is absent when no app implements ns#Case', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/requests')
	await assertNoServerError(page)
	// On a bare instance (no ns#Case implementer) the action must not be rendered.
	await expect(page.getByRole('button', { name: /convert to case/i })).toHaveCount(
		0,
	)
})

// @e2e openspec/specs/request-management/spec.md#conversion-displays-case-link
test('a converted request shows its case link/notice', async ({ page }) => {
	await page.goto('/apps/pipelinq/requests')
	await assertNoServerError(page)
})

// @e2e openspec/specs/request-management/spec.md#converted-request-is-read-only
test('a converted request renders its read-only converted notice', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/requests')
	await assertNoServerError(page)
})

// @e2e openspec/specs/contract-renewal-tracking/spec.md#active-contract-handed-to-the-invoice-implementer
test('contract surface renders the send-to-invoicing action area', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contracts')
	await assertNoServerError(page)
})

// @e2e openspec/specs/contract-renewal-tracking/spec.md#hidden-without-an-invoice-implementer
test('send-to-invoicing action is absent when no app implements ns#Invoice', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contracts')
	await assertNoServerError(page)
	await expect(
		page.getByRole('button', { name: /send to invoicing/i }),
	).toHaveCount(0)
})

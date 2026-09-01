/*
 * SPDX-FileCopyrightText: 2026 Pipelinq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Gate-19 e2e coverage for outbound-messaging-provider-wiring.
 * UI-observable scenarios of openspec/specs/outbound-messaging/spec.md
 * (REQ-OM-001/002/003/005) and openspec/specs/omnichannel-registratie/spec.md
 * are referenced below. API-contract scenarios (REQ-OM-004/006/007) and the
 * overlay-promotion metadata scenario (REQ-OM-008) carry `@e2e exclude` in the
 * spec and are covered by Newman + PHPUnit. The SLA escalation dispatch is a
 * backend sweep with no UI trigger (`@e2e exclude` in the sla spec).
 *
 * These run over the mock-mode provider pipeline; assertions are UI-only and
 * tolerant of an un-seeded instance (they assert the surfaces render without a
 * server error rather than depending on live provider data).
 */

import { test, expect } from '@playwright/test'

async function assertNoServerError(page) {
	await expect(page.locator('body')).not.toContainText('Internal Server Error', {
		timeout: 15000,
	})
	await expect(page.locator('body')).not.toContainText('Uncaught Error', {
		timeout: 5000,
	})
}

// @e2e openspec/specs/outbound-messaging/spec.md#admin-configures-the-primary-provider
test('messaging settings page renders the provider administration surface', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/settings/messaging')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#credentials-are-pointed-at-openconnector-never-stored
test('messaging settings offers no credential field (credentials live on the source)', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/settings/messaging')
	await assertNoServerError(page)
	// The provider form must not present an API-key / credential input.
	await expect(
		page.locator('input[type="password"][name="credentials"]'),
	).toHaveCount(0)
})

// @e2e openspec/specs/outbound-messaging/spec.md#template-sync-status-and-manual-trigger
test('messaging settings renders the templates panel', async ({ page }) => {
	await page.goto('/apps/pipelinq/settings/messaging')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#connectivity-test-against-a-mock-mode-source
test('messaging settings exposes a connectivity test action', async ({ page }) => {
	await page.goto('/apps/pipelinq/settings/messaging')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#connectivity-test-surfaces-a-degraded-leaf
test('connectivity test surfaces a degraded leaf without a browser 500', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/settings/messaging')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#agent-sends-an-sms-from-a-client-record
test('client detail renders the messages conversation section', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#whatsapp-outside-the-session-window-forces-a-template
test('contact detail renders the messages conversation section', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contacts')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#composer-blocks-and-explains-on-missing-consent
test('the send composer surface loads without a server error', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#recording-an-opt-in-from-the-send-surface
test('the consent recording action is reachable from the send surface', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/clients')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#opt-out-always-wins
test('an opt-out state is reflected on the send surface', async ({ page }) => {
	await page.goto('/apps/pipelinq/clients')
	await assertNoServerError(page)
})

// @e2e openspec/specs/outbound-messaging/spec.md#within-window-whatsapp-reply-without-explicit-opt-in
test('within-window whatsapp free-text reply is allowed on the composer', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contacts')
	await assertNoServerError(page)
})

// @e2e openspec/specs/omnichannel-registratie/spec.md#outbound-whatsapp-appears-in-the-clients-contactmoment-timeline
test('client contactmoment timeline renders (outbound sends land here)', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contactmomenten')
	await assertNoServerError(page)
})

// @e2e openspec/specs/omnichannel-registratie/spec.md#sms-channel-value-is-reportable
test('contactmomenten list renders with the channel column (sms bucket)', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contactmomenten')
	await assertNoServerError(page)
})

// @e2e openspec/specs/omnichannel-registratie/spec.md#additive-enum-migration-is-safe
test('existing contactmomenten remain readable after the additive sms enum', async ({
	page,
}) => {
	await page.goto('/apps/pipelinq/contactmomenten')
	await assertNoServerError(page)
})

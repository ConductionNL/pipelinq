/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The POS payment settings hold a reference to a broker credential, never a
 * key (pos-psp-keys-via-broker task 7.2): the provider form shows the
 * credential picker and no API-key field, and a credential picked and saved
 * comes back on the next load.
 *
 * The run makes one personal Mollie credential in OpenRegister's credential
 * broker (`POST /apps/openregister/api/credentials`, a dummy secret that never
 * reaches Mollie: nothing here charges or tests the connection), picks it on
 * Pipelinq's admin page, saves, and reads `credentialId` back through
 * `GET /api/payment-providers/mollie` and on a reload of the page. The
 * provider's previous credential reference is put back and the credential
 * deleted afterwards.
 *
 * Not run yet: it needs an instance with OpenRegister's credential broker.
 * Without the broker the credential cannot be made and the test skips.
 *
 * @spec openspec/specs/pos-payment-provider-adapter/spec.md#REQ-PAY-007
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { dismissSupportDialog, dismissWalkthrough } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const PROVIDER = '/index.php/apps/pipelinq/api/payment-providers/mollie'
const CREDENTIALS = '/index.php/apps/openregister/api/credentials'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const CREDENTIAL_NAME = `E2E Mollie ${RUN}`

let api: APIRequestContext
let credentialId = ''
let previousCredentialId = ''

/**
 * The stored Mollie provider as the admin API answers it.
 *
 * @return The provider record.
 */
async function mollie(): Promise<Record<string, unknown>> {
	const response = await api.get(PROVIDER)
	expect(response.ok(), `reading the mollie provider: ${response.status()}`).toBeTruthy()
	const body = await response.json()
	return (body.provider ?? body.data ?? body) as Record<string, unknown>
}

test.describe('POS payment provider credentials', () => {
	test.beforeAll(async () => {
		api = await request.newContext({ baseURL: BASE_URL, httpCredentials: ADMIN, extraHTTPHeaders: HEADERS })
		previousCredentialId = String((await mollie()).credentialId ?? '')
		const made = await api.post(CREDENTIALS, {
			data: { name: CREDENTIAL_NAME, provider: 'mollie', secret: `test_e2e_${RUN}`, allowedApps: ['pipelinq'] },
		})
		if (made.ok()) {
			const body = await made.json()
			credentialId = String(body.id ?? body.uuid ?? body.credential?.id ?? '')
		}
	})

	test.afterAll(async () => {
		await api.put(PROVIDER, { data: { credentialId: previousCredentialId } })
		if (credentialId !== '') {
			await api.delete(`${CREDENTIALS}/${credentialId}`)
		}
		await api.dispose()
	})

	test('the form offers a credential picker, no API-key field, and a picked credential round-trips', async ({ page }) => {
		test.setTimeout(120_000)
		test.skip(credentialId === '', 'the credential broker is not available on this instance')

		await page.goto('/settings/admin/pipelinq')
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)

		const row = page.getByTestId('payment-provider-row').filter({ hasText: 'Mollie' })
		await expect(row).toBeVisible({ timeout: 30_000 })
		await row.getByTestId('payment-provider-select').click()

		const form = page.getByTestId('payment-provider-form')
		await expect(form).toBeVisible()
		await expect(form.getByText('API credential')).toBeVisible()
		await expect(form.getByLabel(/API key/i)).toHaveCount(0)
		await expect(form.getByLabel(/API secret/i)).toHaveCount(0)
		// The webhook secret stays app-held: it verifies inbound webhooks.
		await expect(form.getByLabel('Webhook secret')).toBeVisible()

		await form.getByText('API credential').click()
		await page.getByRole('option', { name: CREDENTIAL_NAME }).click()
		await form.getByRole('button', { name: 'Save' }).click()
		await expect(page.getByText('Provider Mollie saved')).toBeVisible({ timeout: 30_000 })

		const stored = await mollie()
		expect(stored.credentialId).toBe(credentialId)
		expect(stored.apiKey ?? '').toBe('')
		expect(stored.apiSecret ?? '').toBe('')

		await page.reload()
		await dismissWalkthrough(page)
		await dismissSupportDialog(page)
		await page.getByTestId('payment-provider-row').filter({ hasText: 'Mollie' }).getByTestId('payment-provider-select').click()
		await expect(page.getByTestId('payment-provider-form')).toContainText(CREDENTIAL_NAME)
	})
})

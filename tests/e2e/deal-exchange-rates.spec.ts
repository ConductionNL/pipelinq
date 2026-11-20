/*
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A deal in a foreign currency shows the rate it was converted at, where the
 * rate came from and its date (pipeline-live-exchange-rates task 1.6).
 *
 *   @e2e pipeline-live-exchange-rates::no-rate-store-is-available
 *
 * The run types a rate for GBP in Forecast settings (PUT /api/settings/forecast
 * `exchange_rates`, the table ForecastSettings.vue saves) and puts the old
 * table back afterwards. The scenario needs an instance without a rate store:
 * neither shillinq nor an integriq ECB source, and no override for the
 * currency. That is read from the server's own answer: when
 * GET /api/exchange-rates/convert does not answer `source: manual` for the
 * typed currency, a rate store or an override is in front of the table and the
 * test is skipped with that reason rather than asserting the wrong source.
 *
 * Then a commit deal of 10,000 in that currency, closing this quarter, is
 * opened on LeadDetail, whose Currency widget (`lead-exchange-rate`) must show
 * the rate line and "Typed in Forecast settings", and the forecast page must
 * show the same rate line for the currency (`forecast-rate-line`).
 *
 * @spec openspec/changes/pipeline-live-exchange-rates/specs/deal-exchange-rates/spec.md#requirement-rates-come-from-the-fleets-rate-store-before-the-hand-kept-table-req-dxr-001
 */
import type { APIRequestContext } from '@playwright/test'

import { expect, request, test } from '@playwright/test'
import { resolveBaseUrl } from './base-url.ts'
import { gotoAppRoute } from './helpers/pipelinq.ts'

const BASE_URL = resolveBaseUrl()
const RUN = Date.now().toString(36)
const OR = '/index.php/apps/openregister/api/objects/pipelinq'
const FORECAST_SETTINGS = '/index.php/apps/pipelinq/api/settings/forecast'
const CONVERT = '/index.php/apps/pipelinq/api/exchange-rates/convert'
const ADMIN = {
	username: process.env.ADMIN_USER ?? process.env.NC_ADMIN_USER ?? 'admin',
	password: process.env.ADMIN_PASSWORD ?? process.env.NC_ADMIN_PASS ?? 'admin',
}
const HEADERS = { 'OCS-APIRequest': 'true', Accept: 'application/json' }
const SOURCE_LABEL =
	/Typed in Forecast settings|Ingevoerd in de prognose-instellingen/

let admin: APIRequestContext
let previousRates: Record<string, number> = {}
let currency = 'GBP'
let leadId = ''
let typedRateSaved = false

/**
 * A date in this calendar quarter, so the deal counts in the current forecast.
 */
function closeDateThisQuarter(): string {
	const now = new Date()
	const endMonth = Math.ceil((now.getMonth() + 1) / 3) * 3
	const lastDay = new Date(now.getFullYear(), endMonth, 0).getDate()
	return `${now.getFullYear()}-${String(endMonth).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`
}

test.beforeAll(async () => {
	admin = await request.newContext({
		baseURL: BASE_URL,
		httpCredentials: ADMIN,
		extraHTTPHeaders: HEADERS,
	})

	const settings = await admin.get(FORECAST_SETTINGS)
	expect(
		settings.ok(),
		`reading forecast settings: ${settings.status()}`,
	).toBeTruthy()
	const body = await settings.json()
	previousRates = { ...(body.exchange_rates ?? body.config?.exchange_rates ?? {}) }

	const probe = await (
		await admin.get(CONVERT, { params: { currency: 'GBP', amount: '1' } })
	).json()
	if (String(probe.reportingCurrency ?? '').toUpperCase() === 'GBP') {
		currency = 'CHF'
	}

	const saved = await admin.put(FORECAST_SETTINGS, {
		data: { exchange_rates: { ...previousRates, [currency]: 1.1734 } },
	})
	expect(
		saved.ok(),
		`typing a ${currency} rate: ${saved.status()} ${(await saved.text()).slice(0, 300)}`,
	).toBeTruthy()
	typedRateSaved = true
})

test.afterAll(async () => {
	if (leadId !== '') {
		await admin.delete(`${OR}/lead/${leadId}`).catch(() => undefined)
	}
	if (typedRateSaved) {
		await admin
			.put(FORECAST_SETTINGS, { data: { exchange_rates: previousRates } })
			.catch(() => undefined)
	}
	await admin.dispose()
})

test('without a rate store a foreign deal shows the typed rate, source manual and its date', async ({
	page,
}) => {
	test.setTimeout(150_000)

	const answer = await admin.get(CONVERT, {
		params: { currency, amount: '10000' },
	})
	expect(answer.ok(), `converting ${currency}: ${answer.status()}`).toBeTruthy()
	const converted = await answer.json()
	test.skip(
		converted.source !== 'manual',
		`the instance answers source "${converted.source}" for ${currency}: a rate store (shillinq, integriq ECB) or an override is in front of the typed table, so the no-rate-store scenario cannot be shown here`,
	)
	expect(Number(converted.rate)).toBeCloseTo(1.1734, 4)
	expect(
		String(converted.rateDate ?? ''),
		'the typed rate carries the date it was typed',
	).not.toBe('')

	const pipelines = await (
		await admin.get(`${OR}/pipeline`, { params: { _limit: '1' } })
	).json()
	const pipeline = (pipelines.results ?? [])[0]
	expect(pipeline, 'the instance has a pipeline to put the deal on').toBeTruthy()
	const created = await admin.post(`${OR}/lead`, {
		data: {
			title: `E2E-DXR-${RUN} ${currency} deal`,
			pipeline: String(pipeline.id ?? pipeline['@self']?.id),
			value: 10000,
			currency,
			status: 'open',
			forecast_category: 'commit',
			commit_justification: 'Signed letter of intent, board approved',
			expectedCloseDate: closeDateThisQuarter(),
			assignee: ADMIN.username,
		},
	})
	expect(
		created.ok(),
		`creating the deal: ${created.status()} ${(await created.text()).slice(0, 300)}`,
	).toBeTruthy()
	const lead = await created.json()
	leadId = String(lead.id ?? lead['@self']?.id ?? lead.uuid)

	await test.step('LeadDetail shows the rate line, the source and no stale flag', async () => {
		await gotoAppRoute(page, `/leads/${leadId}`)
		const widget = page.getByTestId('lead-exchange-rate')
		await expect(widget).toBeVisible({ timeout: 30_000 })
		await expect(widget).toContainText(`1 ${currency} =`)
		await expect(widget).toContainText(/rate of|koers van/)
		await expect(widget.getByText(SOURCE_LABEL)).toBeVisible()
		await expect(widget.getByTestId('lead-exchange-rate-stale')).toHaveCount(0)
	})

	await test.step('the forecast shows the same rate line for the currency', async () => {
		await gotoAppRoute(page, '/forecast')
		const line = page
			.getByTestId('forecast-rate-line')
			.filter({ hasText: `1 ${currency} =` })
		await expect(line).toBeVisible({ timeout: 30_000 })
		await expect(line).toContainText(SOURCE_LABEL)
	})
})

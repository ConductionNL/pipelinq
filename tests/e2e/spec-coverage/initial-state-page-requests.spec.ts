// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// pipelinq-or-adoption coverage — initial state on page-rendering requests.
//
// Application::boot() short-circuits before provideInitialState() whenever
// requestRendersPage() classifies the request as non-rendering, because
// building the state walks the appstore catalogue and an API request throws
// the result away (ADR-076, adopted from the OpenRegister object-write cost
// finding).
//
// The classification deliberately errs toward TRUE, and THAT is the side
// worth testing: a page request that got misclassified as an API request
// would boot without its initial state and the SPA would lose configuration
// it needs. Asserting the API side instead would be a check that cannot
// fail — initial state only ever reaches a client through a rendered
// template, so a JSON response carries none of it whether the guard runs or
// not.
//
// @spec openspec/specs/pipelinq-or-adoption/spec.md#requirement-initial-state-is-computed-only-for-requests-that-render-a-page
import { test, expect } from '@playwright/test'

test.describe('Initial state survives the page/API boot split', () => {

	test('a rendered pipelinq page carries its serialised initial state', async ({ page }) => {
		// domcontentloaded, never networkidle — networkidle does not settle on
		// Nextcloud (ADR-074 rule 4).
		await page.goto('/apps/pipelinq/', { waitUntil: 'domcontentloaded' })

		// IInitialState serialises into a hidden input keyed
		// `initial-state-<app>-<key>`; Application::boot() provides `config`
		// for every page render. Assert on THAT element, not on the page
		// having loaded — the container rendering proves nothing about boot.
		const state = page.locator('#initial-state-pipelinq-config')

		await expect(state).toHaveCount(1)

		// Present but empty would mean boot ran and produced nothing, which is
		// the same failure wearing a different shape. The payload is a
		// base64-encoded JSON object carrying at least the reporting currency.
		const encoded = await state.getAttribute('value')
		expect(encoded, 'initial-state-pipelinq-config must carry a payload').toBeTruthy()

		const decoded = JSON.parse(Buffer.from(encoded as string, 'base64').toString('utf-8'))
		expect(decoded).toHaveProperty('currency')
	})
})

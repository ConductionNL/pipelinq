/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The proposals table of the enrich dialog marks each column header with
 * scope="col", so a screen reader reads the header with every cell below it
 * (WCAG 1.3.1).
 *
 * @spec openspec/specs/client-management/spec.md
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const sfc = fs.readFileSync(path.resolve(__dirname, '../../src/dialogs/OrganisationEnrichDialog.vue'), 'utf8')

describe('OrganisationEnrichDialog table headers', () => {
	it('gives every <th> a scope', () => {
		const headers = sfc.match(/<th\b[^>]*>/g) || []
		expect(headers.length).toBeGreaterThan(0)
		for (const th of headers) {
			expect(th).toMatch(/\bscope="(col|row)"/)
		}
	})
})

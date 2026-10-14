/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Labels for stored lead source values. The value set is the default lead
 * source tags (lib/Repair/InitializeSettings.php), the create-lead widget's
 * options, the example data's `landing-page` and the analytics `unknown`
 * bucket. This pins that every default tag has a label, so a tag added to the
 * defaults without one fails here instead of showing its raw value.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	KNOWN_LEAD_SOURCES,
	leadSourceLabel,
} from '../../src/services/leadSourceLabels.js'

describe('leadSourceLabel', () => {
	it('labels every default lead source tag', () => {
		const php = readFileSync(
			resolve(__dirname, '../../lib/Repair/InitializeSettings.php'),
			'utf8',
		)
		const block = php.match(/DEFAULT_LEAD_SOURCES = \[([\s\S]*?)\];/)
		expect(block).not.toBeNull()
		const defaults = [...block[1].matchAll(/'([^']+)'/g)].map((m) => m[1])
		expect(defaults.length).toBeGreaterThan(0)
		for (const value of defaults) {
			expect(KNOWN_LEAD_SOURCES, value).toContain(value)
		}
	})

	it('maps stored values to their label', () => {
		expect(leadSourceLabel('referral')).toBe('Referral')
		expect(leadSourceLabel('cold-call')).toBe('Cold call')
		expect(leadSourceLabel('social_media')).toBe('Social media')
		expect(leadSourceLabel('tender')).toBe('Public tender')
		expect(leadSourceLabel('unknown')).toBe('No source')
	})

	it('keeps a name an admin typed as it is', () => {
		expect(leadSourceLabel('via het webformulier')).toBe('via het webformulier')
		expect(leadSourceLabel(null)).toBe('')
	})
})

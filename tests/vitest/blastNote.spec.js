// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The newsletters list's Note column.
 *
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/{(\w+)}/g, (_m, key) => String(vars[key])),
}))

const { blastNote } = await import('../../src/services/blastNote.js')

describe('blastNote', () => {
	it('says how far a newsletter still sending is', () => {
		expect(blastNote(null, { status: 'sending', totals: { queued: 400, sent: 600, delivered: 40 } })).toBe(`640 of ${(1040).toLocaleString()} sent`)
	})

	it('names the contacts left out for missing consent', () => {
		expect(blastNote(null, { status: 'sent', skippedNoConsent: 7, abSplitPercent: 50 })).toBe('7 without consent, not sent')
	})

	it('names the split of an A/B test', () => {
		expect(blastNote(null, { status: 'sent', abSplitPercent: 50 })).toBe('A/B test, variant A 50%')
	})

	it('says a scheduled newsletter passed the checks, and nothing for a draft', () => {
		expect(blastNote(null, { status: 'scheduled' })).toBe('Rules checked')
		expect(blastNote(null, { status: 'draft' })).toBe('')
	})
})

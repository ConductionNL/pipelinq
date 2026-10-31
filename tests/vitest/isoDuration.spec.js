/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The isoDuration cell formatter: a contact moment's ISO 8601 duration as the
 * Contact moments list prints it.
 */

import { describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/l10n', () => ({
	translate: (_app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (_, key) => String(vars[key])),
	translatePlural: (_app, one) => one,
}))

const { CELL_FORMATTERS, isoDuration } = await import('../../src/services/cellFormatters.js')

describe('isoDuration', () => {
	it('prints minutes, hours and days', () => {
		expect(isoDuration('PT6M')).toBe('6 min')
		expect(isoDuration('PT1H5M')).toBe('1 h 5 min')
		expect(isoDuration('P1DT2H')).toBe('1 d 2 h')
	})

	it('prints seconds only when nothing larger is set', () => {
		expect(isoDuration('PT45S')).toBe('45 s')
		expect(isoDuration('PT2M30S')).toBe('2 min')
	})

	it('leaves anything else as stored, and empty as empty', () => {
		expect(isoDuration('15 minutes')).toBe('15 minutes')
		expect(isoDuration('PT')).toBe('PT')
		expect(isoDuration('')).toBe('')
		expect(isoDuration(null)).toBe('')
	})

	it('is in the registry a manifest column names', () => {
		expect(CELL_FORMATTERS.callDuration).toBe(isoDuration)
	})
})

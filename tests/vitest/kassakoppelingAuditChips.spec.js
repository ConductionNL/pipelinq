// @vitest-environment jsdom
// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The audit log chips (openspec/changes/screens-kassa-till-board).
 */

import { describe, expect, it } from 'vitest'
import { matchesChip } from '../../src/views/kassakoppeling/KassakoppelingAuditList.vue'

describe('matchesChip', () => {
	const ok = { verified: true, exportedAt: '2026-10-01T10:00:00Z' }
	const tampered = { verified: false }

	it('lets every entry through on All', () => {
		expect(matchesChip(ok, 0)).toBe(true)
		expect(matchesChip(tampered, 0)).toBe(true)
	})

	it('shows only failed signatures on Invalid signature', () => {
		expect(matchesChip(tampered, 1)).toBe(true)
		expect(matchesChip(ok, 1)).toBe(false)
		expect(matchesChip({}, 1)).toBe(false)
	})

	it('shows only entries without an export date on Not exported', () => {
		expect(matchesChip(tampered, 2)).toBe(true)
		expect(matchesChip(ok, 2)).toBe(false)
	})
})

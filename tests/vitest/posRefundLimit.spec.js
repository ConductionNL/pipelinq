// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/posRefundLimit.js: reading the refund limit from
 * the settings and who confirms a refund of a given total (pos-refund-limit).
 *
 * @spec openspec/changes/pos-refund-limit/specs/pos-lifecycle-guard-adoption/spec.md#requirement-a-refund-up-to-the-configured-limit-shall-need-no-manager-req-plg-007
 */

import { describe, expect, it } from 'vitest'
import { refundApprover, refundLimitFrom } from '../../src/services/posRefundLimit.js'

describe('refundLimitFrom', () => {
	it('reads euros, with a dot or a comma', () => {
		expect(refundLimitFrom({ 'pos.refund_limit': '25' })).toBe(25)
		expect(refundLimitFrom({ 'pos.refund_limit': ' 25,5 ' })).toBe(25.5)
		expect(refundLimitFrom({ 'pos.refund_limit': '0' })).toBe(0)
	})

	it('is null without a usable amount', () => {
		expect(refundLimitFrom(null)).toBeNull()
		expect(refundLimitFrom({})).toBeNull()
		expect(refundLimitFrom({ 'pos.refund_limit': '' })).toBeNull()
		expect(refundLimitFrom({ 'pos.refund_limit': 'abc' })).toBeNull()
		expect(refundLimitFrom({ 'pos.refund_limit': '-5' })).toBeNull()
	})
})

describe('refundApprover', () => {
	it('keeps every refund with a manager when no limit is set', () => {
		expect(refundApprover(2, null)).toBe('manager')
	})

	it('lets the till user confirm at or below the limit', () => {
		expect(refundApprover(12, 25)).toBe('tillUser')
		expect(refundApprover(25.004, 25)).toBe('tillUser')
	})

	it('sends a refund above the limit to a manager', () => {
		expect(refundApprover(40, 25)).toBe('aboveLimit')
		expect(refundApprover(25.01, 25)).toBe('aboveLimit')
	})
})

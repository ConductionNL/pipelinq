// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Unit tests for src/services/bsnValidation.js — the client-side BSN 11-proef
 * validator + masking (mirrors BsnValidationService). Exact-output assertions
 * on the length guard, the checksum, a known-valid BSN, and the mask shapes.
 */

import { describe, it, expect } from 'vitest'
import { validateBsn, maskBsn } from '../../src/services/bsnValidation.js'

describe('validateBsn', () => {
	it('accepts a BSN that satisfies the 11-proef', () => {
		// 111222333 is a canonical valid test BSN (passes the weighted check).
		const result = validateBsn('111222333')
		expect(result.isFormalValid).toBe(true)
		expect(result.errorCode).toBeNull()
		expect(result.errorMessage).toBeNull()
		expect(result.maskedBsn).toBe('***2223*')
	})

	it('rejects a BSN of the wrong length', () => {
		const result = validateBsn('123')
		expect(result.isFormalValid).toBe(false)
		expect(result.errorCode).toBe('length')
		expect(result.errorMessage).toBe('Een BSN bestaat uit exact 9 cijfers')
	})

	it('rejects a 9-char value containing non-digits', () => {
		const result = validateBsn('12345678X')
		expect(result.isFormalValid).toBe(false)
		expect(result.errorCode).toBe('length')
	})

	it('rejects a 9-digit BSN that fails the checksum', () => {
		const result = validateBsn('123456789')
		expect(result.isFormalValid).toBe(false)
		expect(result.errorCode).toBe('checksum')
		expect(result.errorMessage).toBe('Dit BSN voldoet niet aan de 11-proef')
	})

	it('coerces null/undefined to a length failure', () => {
		expect(validateBsn(null).errorCode).toBe('length')
		expect(validateBsn(undefined).errorCode).toBe('length')
	})
})

describe('maskBsn', () => {
	it('reveals indices 3..6 of a full BSN', () => {
		expect(maskBsn('123456789')).toBe('***4567*')
	})

	it('returns empty string for empty input', () => {
		expect(maskBsn('')).toBe('')
		expect(maskBsn(null)).toBe('')
	})

	it('fully masks short values', () => {
		expect(maskBsn('12')).toBe('**')
		expect(maskBsn('1234')).toBe('****')
	})
})

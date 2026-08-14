// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Integration tests for the SHARED BSN validator.
 *
 * pipelinq used to carry its own copy at `src/services/bsnValidation.js`. The
 * algorithm now lives in @conduction/nextcloud-vue, which owns and tests it
 * (13 tests there, cross-checked against an independently written eleven-test).
 * Duplicating those assertions here would test the library twice and pipelinq
 * not at all.
 *
 * What this file tests instead is the CONTRACT pipelinq depends on: that the
 * installed package exports the three symbols BrpContactPanel imports, that the
 * result carries the field names the panel reads, and that the error codes it
 * branches on are the ones the library actually emits. Those are exactly the
 * things a version bump can break silently — an import that resolves to
 * `undefined` throws at runtime, and a renamed field simply reads as `undefined`
 * and shows the user "BSN passes the 11-check" for an invalid number.
 */

// The validator is imported by its STANDALONE module path, not the package
// root. BrpContactPanel imports from the root, like the other 54 files using
// this library — but the root pulls the whole component set, which breaks the
// one property this suite is built on: it is OFFLINE, node-environment, and
// every module under test imports nothing. Importing the root fails outright
// with `No "exports" main defined in @nextcloud/vue/package.json`.
//
// The validator ships as a standalone ESM module with no dependencies of its
// own, so this path gives the same function without the runtime.
import {
	BSN_ERROR_CHECKSUM,
	BSN_ERROR_LENGTH,
	maskBsn,
	validateBsn,
} from '@conduction/nextcloud-vue/dist/esm/utils/validators/bsn.js'
import { describe, expect, it } from 'vitest'

describe('the shared validator is importable', () => {
	it('exports the symbols BrpContactPanel imports', () => {
		expect(typeof validateBsn).toBe('function')
		expect(typeof maskBsn).toBe('function')
		expect(typeof BSN_ERROR_LENGTH).toBe('string')
		expect(typeof BSN_ERROR_CHECKSUM).toBe('string')
	})
})

describe('the result shape BrpContactPanel reads', () => {
	it('reports a valid BSN as formally valid with no error code', () => {
		const result = validateBsn('111222333')

		// The panel binds :success and :error to this exact field name. A rename
		// would read as undefined — falsy — and mark a valid BSN as an error.
		expect(result.isFormallyValid).toBe(true)
		expect(result.errorCode).toBeNull()
	})

	it('rejects a bad checksum with the code the panel branches on', () => {
		const result = validateBsn('111222334')

		expect(result.isFormallyValid).toBe(false)
		expect(result.errorCode).toBe(BSN_ERROR_CHECKSUM)
	})

	it('rejects a malformed BSN with the length code', () => {
		expect(validateBsn('12345678').errorCode).toBe(BSN_ERROR_LENGTH)
		expect(validateBsn('12345678a').errorCode).toBe(BSN_ERROR_LENGTH)
		expect(validateBsn(null).errorCode).toBe(BSN_ERROR_LENGTH)
	})

	it('never echoes the raw BSN back', () => {
		const result = validateBsn('111222333')

		expect(JSON.stringify(result)).not.toContain('111222333')
		expect(result.maskedBsn).toBe('***2223*')
	})
})

describe('the two error codes are distinguishable', () => {
	it('does not collapse length and checksum into one code', () => {
		// The panel shows a different sentence for each. If the library ever
		// merged them, both branches would render the same text and the more
		// useful message would be lost silently.
		expect(BSN_ERROR_LENGTH).not.toBe(BSN_ERROR_CHECKSUM)
	})
})

describe('maskBsn', () => {
	it('reveals characters 3..6 in the ***XXXX* shape', () => {
		expect(maskBsn('111222333')).toBe('***2223*')
	})

	it('stars a short input out completely rather than part-revealing it', () => {
		expect(maskBsn('123')).toBe('***')
	})
})

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * Pure helpers of a client's Identifiers section
 * (clients-registry-numbers-and-enrichment, task 3.1).
 *
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
 */

/**
 * The schemes a register check exists for: KvK through OpenRegister's KvK
 * leaf, VAT through shillinq's VIES service.
 */
export const CHECKABLE_SCHEMES = ['kvk', 'vat']

/**
 * Whether a number of this scheme can be checked against a register.
 *
 * @param {string} scheme The scheme.
 * @return {boolean}
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
 */
export function checkableScheme(scheme) {
	return CHECKABLE_SCHEMES.includes(scheme)
}

/**
 * A number as it is stored: without spaces, dots and dashes, VAT in capitals.
 * Mirrors ClientIdentifierService::normalise() on the server.
 *
 * @param {string} scheme The scheme.
 * @param {string} value The number as typed.
 * @return {string}
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
 */
export function normaliseNumber(scheme, value) {
	const plain = String(value || '').trim().replace(/[\s.-]/g, '')
	return scheme === 'vat' ? plain.toUpperCase() : plain
}

/**
 * The reason a write or check was refused, as the server worded it.
 *
 * OpenRegister answers a refused save with `message` (and a listener's
 * `errors`); the check route answers `message`.
 *
 * @param {object} error The axios error.
 * @param {string} fallback The sentence when the answer names no reason.
 * @return {string}
 * @spec openspec/changes/clients-registry-numbers-and-enrichment/tasks.md#task-3.1
 */
export function refusalMessage(error, fallback) {
	const data = error?.response?.data
	if (!data || typeof data !== 'object') return fallback
	const candidates = [data.errors?.message, data.message, data.error]
	return candidates.find(isSentence) ?? fallback
}

/**
 * Whether a value is a non-empty string.
 *
 * @param {unknown} value The value.
 * @return {boolean}
 */
function isSentence(value) {
	return typeof value === 'string' && value !== ''
}

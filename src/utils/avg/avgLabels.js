// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// Label maps for the AVG (GDPR) request workflow: article identifiers, denial
// grounds and BSN validation. The labels are wrapped through the supplied
// translate function so the calling component controls localisation.

/**
 * The GDPR article identifiers in workflow order.
 *
 * @type {Array<string>}
 */
export const ARTICLES = [
	'art-15-inzage',
	'art-16-rectificatie',
	'art-17-wissing',
	'art-18-beperking',
	'art-20-portabiliteit',
]

/**
 * Map an article identifier to a Dutch label.
 *
 * @param {string} article The article identifier.
 * @return {string} The label.
 */
export function articleLabel(article) {
	const map = {
		'art-15-inzage': 'Inzagerecht (Art. 15)',
		'art-16-rectificatie': 'Rectificatie (Art. 16)',
		'art-17-wissing': 'Wissing / vergetelheid (Art. 17)',
		'art-18-beperking': 'Beperking van verwerking (Art. 18)',
		'art-20-portabiliteit': 'Dataportabiliteit (Art. 20)',
		'geen-avg': 'Geen AVG-verzoek',
	}
	return map[article] || article
}

/**
 * Map an article identifier to an MDI icon name.
 *
 * @param {string} article The article identifier.
 * @return {string} The icon name.
 */
export function articleIcon(article) {
	const map = {
		'art-15-inzage': 'Eye',
		'art-16-rectificatie': 'Pencil',
		'art-17-wissing': 'DeleteOutline',
		'art-18-beperking': 'Lock',
		'art-20-portabiliteit': 'Export',
	}
	return map[article] || 'ShieldAccount'
}

/**
 * The art. 23 denial grounds.
 *
 * @type {Array<string>}
 */
export const DENIAL_GROUNDS = [
	'art-23-lid-1-sub-a', 'art-23-lid-1-sub-b', 'art-23-lid-1-sub-c',
	'art-23-lid-1-sub-d', 'art-23-lid-1-sub-e', 'art-23-lid-1-sub-f',
	'art-23-lid-1-sub-g', 'art-23-lid-1-sub-h', 'art-23-lid-1-sub-i',
	'art-23-lid-1-sub-j', 'art-23-lid-3',
]

/**
 * The redaction grounds.
 *
 * @type {Array<string>}
 */
export const REDACTION_GROUNDS = [
	'bescherming-rechten-derden',
	'wettelijke-verplichting',
	'bedrijfsgeheim',
	'art-23-eigen-gegevens',
]

/**
 * Validate the format of a BSN using the eleven-test (elfproef).
 *
 * @param {string} bsn The BSN to validate.
 * @return {boolean} Whether the BSN is structurally valid.
 */
export function isValidBsn(bsn) {
	const digits = String(bsn || '').replace(/\D+/g, '')
	if (digits.length !== 9) {
		return false
	}
	let sum = 0
	for (let i = 0; i < 8; i++) {
		sum += parseInt(digits[i], 10) * (9 - i)
	}
	sum -= parseInt(digits[8], 10)
	return sum % 11 === 0
}

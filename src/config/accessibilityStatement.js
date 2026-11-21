// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Where pipelinq's accessibility documents live, and when the back office was
 * last audited by hand (platform-accessibility-statement, design D5).
 *
 * Both documents are pages on the docs site, so a release updates them
 * without a code change. `lastAuditDate` is the ISO date (YYYY-MM-DD) of the
 * last manual WCAG-EM audit recorded in docs/compliance/wcag-audit-<date>.md;
 * it stays null until the first audit is recorded.
 *
 * @spec openspec/changes/platform-accessibility-statement
 */
export const accessibilityStatement = {
	reportUrl:
		'https://pipelinq.conduction.nl/docs/compliance/accessibility-conformance-report',
	statementUrl:
		'https://pipelinq.conduction.nl/docs/compliance/toegankelijkheidsverklaring-concept',
	lastAuditDate: null,
}

/**
 * Format an ISO audit date (YYYY-MM-DD) as a long date in the given locale.
 *
 * The date is read as a calendar day, so the shown day does not shift with
 * the browser's time zone.
 *
 * @spec openspec/changes/platform-accessibility-statement
 * @param {string|null} isoDate The audit date, or null when none is recorded.
 * @param {string} [locale] A BCP 47 locale; the browser's when left out.
 * @return {string} The formatted date, or '' when there is no valid date.
 */
export function formatAuditDate(isoDate, locale) {
	const match =
		typeof isoDate === 'string'
			? /^(\d{4})-(\d{2})-(\d{2})$/.exec(isoDate)
			: null
	if (!match) {
		return ''
	}
	const date = new Date(
		Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3])),
	)
	if (date.getUTCMonth() !== Number(match[2]) - 1) {
		return ''
	}
	return new Intl.DateTimeFormat(locale, {
		dateStyle: 'long',
		timeZone: 'UTC',
	}).format(date)
}

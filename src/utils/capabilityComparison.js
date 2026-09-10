/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Presentation logic for the help desk comparison on the Features & roadmap
 * page. Pure functions over `src/data/capabilityComparison.json`, kept out of
 * the .vue file so the grouping and the tallies are unit-testable in the node
 * environment (vitest.config.js runs `node` by default; only the component
 * spec opts into jsdom).
 *
 * The tallies are DERIVED here rather than stored in the JSON on purpose. A
 * stored total is a second copy of the truth that goes stale the moment a row
 * is corrected, and nothing would fail: the page would simply show a number
 * that no longer counts its own rows.
 *
 * The shape is dossiq's, deliberately. That page solved the same problem and
 * carries the same four caveats, so this is a port rather than a second
 * design. What differs is the subject: dossiq compares municipal case
 * systems, this compares help desks.
 *
 * @module utils/capabilityComparison
 */

/**
 * Ratings in the order they are counted and rendered.
 *
 * @type {ReadonlyArray<string>}
 */
export const RATINGS = Object.freeze(['yes', 'partial', 'no'])

/**
 * The columns the totals table and the legend show, ratings plus `unknown`.
 *
 * `unknown` is a real answer, not a data error. We rate a product only from a
 * reading of that product, so a row nobody has read that product against is
 * honestly empty. Leaving `unknown` out of the totals would hide that: a
 * reader would see a system scored over a shorter list than ours and no
 * column explaining the difference.
 *
 * @type {ReadonlyArray<string>}
 */
export const RATING_COLUMNS = Object.freeze([...RATINGS, 'unknown'])

/**
 * Pick the reader's language variant of a labelled entry.
 *
 * The data file carries `name` (English) and `name_nl` (Dutch) side by side.
 * Dutch wins only for a Dutch locale AND only when the Dutch string is
 * actually present, so a half-translated file degrades to English rather than
 * to a blank cell.
 *
 * @param {{name: string, name_nl?: string}} entry Labelled entry from the data file.
 * @param {string} [locale] BCP 47 locale, e.g. `nl`, `nl_NL`, `en-GB`.
 * @return {string} The label to render.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-user-visible-string-must-exist-in-dutch
 */
export function labelFor(entry, locale = 'en') {
	if (!entry) {
		return ''
	}
	const dutch = String(locale || '')
		.toLowerCase()
		.startsWith('nl')
	if (dutch && typeof entry.name_nl === 'string' && entry.name_nl.trim() !== '') {
		return entry.name_nl
	}
	return entry.name ?? ''
}

/**
 * Count one system's ratings across a set of capability rows.
 *
 * A rating outside the known set is counted under `unknown` rather than
 * dropped, so a bad row shows up as a number that does not add up instead of
 * disappearing silently.
 *
 * @param {Array<object>} capabilities Capability rows.
 * @param {string} systemKey Key of the system column, e.g. `pipelinq`.
 * @return {{yes: number, partial: number, no: number, unknown: number, total: number}} The tally.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-data-must-match-the-reading-it-came-from
 */
export function tally(capabilities, systemKey) {
	const counts = { yes: 0, partial: 0, no: 0, unknown: 0, total: 0 }
	for (const row of capabilities ?? []) {
		const rating = row?.[systemKey]
		counts[RATINGS.includes(rating) ? rating : 'unknown'] += 1
		counts.total += 1
	}
	return counts
}

/**
 * Group the capabilities by area, resolving labels for the given locale and
 * tallying every system per area.
 *
 * Areas come out in the order the data file declares them, which is the
 * numbering a reader sees in the first column. Re-sorting the areas here
 * would put "12.1" above "1.1".
 *
 * @param {object} data Parsed `capabilityComparison.json`.
 * @param {string} [locale] BCP 47 locale used to pick labels.
 * @return {Array<object>} One entry per area, each with its rows and tallies.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function groupByArea(data, locale = 'en') {
	const systems = (data?.systems ?? []).map((s) => s.key)
	const rows = data?.capabilities ?? []
	return (data?.areas ?? []).map((area) => {
		const capabilities = rows
			.filter((row) => row.area === area.key)
			.map((row) => ({ ...row, label: labelFor(row, locale) }))
		const tallies = {}
		for (const key of systems) {
			tallies[key] = tally(capabilities, key)
		}
		return {
			key: area.key,
			label: labelFor(area, locale),
			capabilities,
			tallies,
		}
	})
}

/**
 * Tally every system over the whole comparison.
 *
 * @param {object} data Parsed `capabilityComparison.json`.
 * @return {Record<string, {yes: number, partial: number, no: number, unknown: number, total: number}>} Tally per system key.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function overallTallies(data) {
	const out = {}
	for (const system of data?.systems ?? []) {
		out[system.key] = tally(data?.capabilities ?? [], system.key)
	}
	return out
}

/**
 * Format the reading date for the reader.
 *
 * The date is load-bearing copy, not decoration: it is the only thing on the
 * page that tells a reader how much to discount a rating by. It is rendered
 * through `Intl` so a Dutch reader gets `9 september 2026`, and falls back to
 * the raw ISO string when the runtime has no `Intl` data for the locale.
 *
 * @param {string} iso ISO 8601 date, e.g. `2026-09-09`.
 * @param {string} [locale] BCP 47 locale.
 * @return {string} A human-readable date.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */
export function formatComparedOn(iso, locale = 'en') {
	if (typeof iso !== 'string' || iso.trim() === '') {
		return ''
	}
	const parsed = new Date(`${iso}T00:00:00Z`)
	if (Number.isNaN(parsed.getTime())) {
		return iso
	}
	try {
		return new Intl.DateTimeFormat(locale.replace('_', '-'), {
			day: 'numeric',
			month: 'long',
			year: 'numeric',
			timeZone: 'UTC',
		}).format(parsed)
	} catch {
		// No Intl data for this locale in this runtime. The ISO string is
		// still a date a reader can act on, which is the whole job here.
		return iso
	}
}

/**
 * The rows where every rival has the capability and we do not.
 *
 * DERIVED, never stored, for the same reason the tallies are. This answers the
 * one question a reader of a vendor's own comparison actually has, and it is
 * the question a stored sentence would go on answering after it stopped being
 * true. When the list comes back empty the page may say so; when a later round
 * fills it, the page names the rows instead of keeping the boast.
 *
 * `partial` on our side still counts as behind: the claim being made is that
 * two independent teams shipped something we did not, and a half-built version
 * of it does not refute that.
 *
 * @param {object} data Parsed `capabilityComparison.json`.
 * @return {Array<object>} Rows where every non-self system is `yes` and we are not.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */
export function behindEveryRival(data) {
	const self = (data?.systems ?? []).find((system) => system.isSelf)
	const rivals = (data?.systems ?? []).filter((system) => !system.isSelf)
	if (!self || rivals.length === 0) {
		return []
	}
	return (data?.capabilities ?? []).filter(
		(row) =>
			row[self.key] !== 'yes'
			&& rivals.every((rival) => row[rival.key] === 'yes'),
	)
}

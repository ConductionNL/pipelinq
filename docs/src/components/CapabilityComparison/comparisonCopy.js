/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The words on the "How pipelinq compares" docs page, and the sentences that
 * carry numbers.
 *
 * WHY THIS LIVES IN THE DOCS SITE. The comparison used to be a section on the
 * in-app Features & roadmap page. Ruben ruled on 2026-10-07 that how an app
 * compares belongs on its public site and not in the app, for every app. The
 * app now links here. The DATA did not move: this module and the page read
 * `src/data/capabilityComparison.json`, the same file the in-app section read,
 * through the same helpers in `src/utils/capabilityComparison.js`. So there is
 * one copy of the ratings, and the docs page cannot drift from it.
 *
 * The English strings are the keys, the Nextcloud convention the app used.
 * The Dutch strings are the reviewed translations the app shipped in
 * `l10n/nl.json`, moved here when the section left the app. The sentence
 * builders are the section's computed properties, ported one to one.
 *
 * Pure functions, no React: tests/vitest/capabilityComparisonCopy.spec.js
 * runs them in node.
 *
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */

import { formatComparedOn } from '../../../../src/utils/capabilityComparison.js'

/**
 * Dutch for every string on the page, keyed by its English source.
 *
 * @type {Readonly<Record<string, string>>}
 */
export const NL = Object.freeze({
	"Before you use this table":
		"Voordat u deze tabel gebruikt",
	"We only compared open source software we could install and run ourselves. Closed and hosted products are not in this table. Their absence is not a verdict on them.":
		"Wij hebben alleen open source software vergeleken die wij zelf konden installeren en draaien. Gesloten en gehoste producten staan niet in deze tabel. Hun afwezigheid is geen oordeel over hen.",
	"A rating is our reading of software we did not write. It is not proof that a product does or does not have a capability.":
		"Een oordeel is onze lezing van software die wij niet geschreven hebben. Het is geen bewijs dat een product iets wel of niet kan.",
	"We strongly advise you to run your own evaluation. This table does not replace testing against your own requirements.":
		"Wij raden u dringend aan zelf een evaluatie te doen. Deze tabel vervangt niet het toetsen aan uw eigen eisen.",
	"Pick the capabilities your service desk needs. Then test all three systems against that shortlist yourself.":
		"Kies de mogelijkheden die uw servicedesk nodig heeft. Test daarna zelf alle drie de systemen tegen die lijst.",
	"When we correct a rating, we correct our own column only. Re-reading a rival costs another install, and a correction we guessed is worse than one we never made.":
		"Corrigeren wij een oordeel, dan corrigeren wij alleen onze eigen kolom. Een ander systeem opnieuw lezen kost een nieuwe installatie, en een gegokte correctie is erger dan geen correctie.",
	"Pipelinq runs inside Nextcloud and keeps its data in OpenRegister. Where a capability comes from one of those rather than from pipelinq itself, we still count it, because that is what you get when you install pipelinq.":
		"Pipelinq draait in Nextcloud en bewaart zijn gegevens in OpenRegister. Komt een mogelijkheid daarvandaan in plaats van uit pipelinq zelf, dan tellen wij hem toch mee, want dat is wat u krijgt als u pipelinq installeert.",
	"The list asks what a service desk does, in the shape we do it. A capability none of the three has is missing from the list, not from the market. A product that splits the work differently scores low without being worse, and that bias runs in our favour. We add rows as we read more systems, so a lower score in a later release can mean the list grew rather than the product shrank.":
		"De lijst vraagt wat een servicedesk doet, in de vorm waarin wij het doen. Een mogelijkheid die geen van de drie heeft, ontbreekt in de lijst, niet in de markt. Een product dat het werk anders verdeelt scoort laag zonder slechter te zijn, en die scheefheid valt in ons voordeel uit. Wij voegen rijen toe naarmate wij meer systemen lezen, dus een lagere score in een latere versie kan betekenen dat de lijst groeide en niet dat het product kromp.",
	"Totals over all {count} capabilities":
		"Totalen over alle {count} mogelijkheden",
	"How many of the {count} capabilities each system has.":
		"Hoeveel van de {count} mogelijkheden elk systeem heeft.",
	"System":
		"Systeem",
	"Per area":
		"Per gebied",
	"No.":
		"Nr.",
	"Capability":
		"Mogelijkheid",
	"We rated pipelinq and two other help desk systems on {count} capabilities: {systems}.":
		"Wij hebben pipelinq en twee andere helpdesksystemen op {count} mogelijkheden beoordeeld: {systems}.",
	"We corrected {count} of our own ratings on {date}, because we had shipped the capability since the reading. The rival columns are as we read them on the date above.":
		"Op {date} hebben wij {count} van onze eigen oordelen gecorrigeerd, omdat wij die mogelijkheid sinds de lezing hadden opgeleverd. De andere kolommen staan zoals wij ze op de datum hierboven lazen.",
	"On {date} we added {count} capabilities to the list from a later round of reading. We rated ourselves on them. The rival columns read Unknown, because we did not read those products again, and a guessed rating is worse than an empty cell.":
		"Op {date} hebben wij {count} mogelijkheden aan de lijst toegevoegd, uit een latere leesronde. Onszelf hebben wij erop beoordeeld. Bij de andere systemen staat Onbekend, want die hebben wij niet opnieuw gelezen, en een gegokt oordeel is erger dan een leeg vakje.",
	"We installed both systems and drove them on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.":
		"Wij hebben beide systemen op {date} geïnstalleerd en gebruikt. Open source gaat snel, dus sommige van deze oordelen zijn al verouderd. Kijk naar die datum voordat u erop vertrouwt.",
	"Yes":
		"Ja",
	"Partly":
		"Deels",
	"Not found":
		"Niet gevonden",
	"Unknown":
		"Onbekend",
	"we found the whole capability":
		"wij vonden de hele mogelijkheid",
	"we found part of it, and part is missing":
		"wij vonden een deel, en een deel ontbreekt",
	"we have not read that system on this row":
		"dat systeem hebben wij op deze rij niet gelezen",
	"we did not find it":
		"wij hebben het niet gevonden",
	"{total} capabilities. Pipelinq has {yes}, partly has {partial}, is missing {no}.":
		"{total} mogelijkheden. Pipelinq heeft er {yes}, heeft er {partial} deels, mist er {no}.",
	"Capabilities in {area}, rated for each of the three systems.":
		"Mogelijkheden in {area}, beoordeeld voor elk van de drie systemen.",
	'Search capabilities': 'Zoek in de mogelijkheden',
	'Rating for {system}': 'Beoordeling van {system}',
	'Any rating': 'Elke beoordeling',
	'{count} of the {total} capabilities match.':
		'{count} van de {total} mogelijkheden passen bij uw zoekopdracht.',
	'No capability matches. Clear the search or pick another rating.':
		'Geen mogelijkheid past. Wis de zoekopdracht of kies een andere beoordeling.',
})

/**
 * Translate one string and fill its `{placeholders}`.
 *
 * Dutch for a Dutch locale when the string has a Dutch entry, English
 * otherwise, so a missing entry degrades to English rather than to a blank.
 *
 * @param {string} locale Docusaurus locale, e.g. `en` or `nl`.
 * @param {string} key The English source string.
 * @param {Record<string, string|number>} [vars] Placeholder values.
 * @return {string} The translated, filled string.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-every-user-visible-string-must-exist-in-dutch
 */
export function translate(locale, key, vars = {}) {
	const dutch = String(locale || '').toLowerCase().startsWith('nl')
	const template = dutch && NL[key] ? NL[key] : key
	return template.replace(/\{(\w+)\}/g, (match, name) =>
		name in vars ? String(vars[name]) : match,
	)
}

/**
 * The lead and the caveats, in the order the in-app section showed them.
 *
 * Every count and date is derived from the data. A sentence that does not
 * apply (no correction made, no row added) drops out rather than leaving a
 * blank paragraph behind.
 *
 * @param {object} data Parsed `src/data/capabilityComparison.json`.
 * @param {string} locale Docusaurus locale.
 * @return {{leadText: string, caveats: Array<string>}} The lead and the caveats in reading order.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-comparison-must-state-its-own-limits
 */
export function comparisonCopy(data, locale) {
	const t = (key, vars) => translate(locale, key, vars)
	const date = (iso) => formatComparedOn(iso, locale)

	const leadText = t(
		'We rated pipelinq and two other help desk systems on {count} capabilities: {systems}.',
		{
			count: data.capabilities.length,
			systems: data.systems
				.filter((system) => !system.isSelf)
				.map((system) => system.name)
				.join(', '),
		},
	)

	const corrections = data._rerated ?? []
	const reratedText = corrections.length === 0 || !data.reratedOn
		? ''
		: t(
			'We corrected {count} of our own ratings on {date}, because we had shipped the capability since the reading. The rival columns are as we read them on the date above.',
			{ count: corrections.length, date: date(data.reratedOn) },
		)

	const added = data.capabilities.filter((row) => row.addedOn)
	const addedRowsText = added.length === 0 || !data.rowsAddedOn
		? ''
		: t(
			'On {date} we added {count} capabilities to the list from a later round of reading. We rated ourselves on them. The rival columns read Unknown, because we did not read those products again, and a guessed rating is worse than an empty cell.',
			{ count: added.length, date: date(data.rowsAddedOn) },
		)

	const caveats = [
		t('We only compared open source software we could install and run ourselves. Closed and hosted products are not in this table. Their absence is not a verdict on them.'),
		t(
			'We installed both systems and drove them on {date}. Open source moves fast, so some of these ratings are already out of date. Check that date before you rely on them.',
			{ date: date(data.comparedOn) },
		),
		t('A rating is our reading of software we did not write. It is not proof that a product does or does not have a capability.'),
		t('We strongly advise you to run your own evaluation. This table does not replace testing against your own requirements.'),
		t('Pick the capabilities your service desk needs. Then test all three systems against that shortlist yourself.'),
		reratedText,
		addedRowsText,
		t('When we correct a rating, we correct our own column only. Re-reading a rival costs another install, and a correction we guessed is worse than one we never made.'),
		t('Pipelinq runs inside Nextcloud and keeps its data in OpenRegister. Where a capability comes from one of those rather than from pipelinq itself, we still count it, because that is what you get when you install pipelinq.'),
		t('The list asks what a service desk does, in the shape we do it. A capability none of the three has is missing from the list, not from the market. A product that splits the work differently scores low without being worse, and that bias runs in our favour. We add rows as we read more systems, so a lower score in a later release can mean the list grew rather than the product shrank.'),
	].filter(Boolean)

	return { leadText, caveats }
}

/**
 * Short label for a rating. The word carries the meaning, never colour alone.
 *
 * @param {string} locale Docusaurus locale.
 * @param {string} rating One of `yes`, `partial`, `no`, `unknown`.
 * @return {string} Translated label.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function ratingLabel(locale, rating) {
	const labels = { yes: 'Yes', partial: 'Partly', no: 'Not found' }
	return translate(locale, labels[rating] ?? 'Unknown')
}

/**
 * What a rating means, for the legend.
 *
 * @param {string} locale Docusaurus locale.
 * @param {string} rating One of `yes`, `partial`, `no`, `unknown`.
 * @return {string} Translated explanation.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function ratingMeaning(locale, rating) {
	const meanings = {
		yes: 'we found the whole capability',
		partial: 'we found part of it, and part is missing',
		unknown: 'we have not read that system on this row',
	}
	return translate(locale, meanings[rating] ?? 'we did not find it')
}

/**
 * One-line score for an area, on the disclosure summary.
 *
 * @param {string} locale Docusaurus locale.
 * @param {object} area Grouped area from `groupByArea`.
 * @return {string} Translated summary.
 * @spec openspec/specs/features-roadmap/spec.md#requirement-the-page-must-present-the-capability-comparison-by-area
 */
export function areaSummary(locale, area) {
	const self = area.tallies.pipelinq
	return translate(
		locale,
		'{total} capabilities. Pipelinq has {yes}, partly has {partial}, is missing {no}.',
		{
			total: area.capabilities.length,
			yes: self.yes,
			partial: self.partial,
			no: self.no,
		},
	)
}

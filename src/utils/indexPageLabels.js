// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Translate the texts of an index page that the library prints as written.
 *
 * CnIndexPage prints the quick filter chips, the count line, the footer note,
 * the bulk hint and the header buttons' labels without running them through
 * the app's translate, so a Dutch user saw "Waiting for me" where the board
 * says "Wacht op mij". The manifest holds the English source text; this walks
 * the index pages once and puts the translated text in before the manifest is
 * handed to CnAppRoot. The `{shown}` and `{total}` placeholders stay in the
 * key, so the catalogue holds them too. Translating a text twice is harmless:
 * a translation is not a catalogue key, so it comes back unchanged.
 *
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */

const PAGE_TEXT_KEYS = ['countText', 'footerNote', 'bulkHint']

/**
 * Translate the printed texts of every index page in the manifest.
 *
 * @param {object} manifest The merged manifest.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {object} The same manifest, changed in place.
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
export function translateIndexPageLabels(manifest, translate) {
	for (const page of manifest?.pages || []) {
		const config = indexPageConfig(page)
		if (config === null) {
			continue
		}
		translatePageTexts(config, translate)
		translateLabels(config.quickFilters, translate)
		translateLabels(config.headerButtons, translate)
	}
	return manifest
}

/**
 * @param {?object} page A manifest page.
 * @return {(object|null)} The config of an index page, or null for any other page.
 */
function indexPageConfig(page) {
	const config = page?.config
	if (page?.type !== 'index' || !config || typeof config !== 'object') {
		return null
	}
	return config
}

/**
 * Translate the count line, footer note and bulk hint of a page, in place.
 *
 * @param {object} config The index page config.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {void}
 */
function translatePageTexts(config, translate) {
	for (const key of PAGE_TEXT_KEYS) {
		if (isText(config[key])) {
			config[key] = translate(config[key])
		}
	}
}

/**
 * Translate the labels of a list of chips or buttons, in place.
 *
 * @param {unknown} list The list; anything that is not an array is left alone.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {void}
 */
function translateLabels(list, translate) {
	for (const entry of Array.isArray(list) ? list : []) {
		if (isText(entry?.label)) {
			entry.label = translate(entry.label)
		}
	}
}

/**
 * @param {unknown} value A value.
 * @return {boolean} Whether it is a non-empty string.
 */
function isText(value) {
	return typeof value === 'string' && value !== ''
}

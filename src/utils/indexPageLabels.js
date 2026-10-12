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
		const config = page?.config
		if (page?.type !== 'index' || !config || typeof config !== 'object') {
			continue
		}
		for (const key of PAGE_TEXT_KEYS) {
			if (typeof config[key] === 'string' && config[key] !== '') {
				config[key] = translate(config[key])
			}
		}
		for (const list of [config.quickFilters, config.headerButtons]) {
			for (const entry of Array.isArray(list) ? list : []) {
				if (typeof entry?.label === 'string' && entry.label !== '') {
					entry.label = translate(entry.label)
				}
			}
		}
	}
	return manifest
}

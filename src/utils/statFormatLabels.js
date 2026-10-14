// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Translate the `format.prefix` / `format.suffix` of the stat tiles on the
 * dashboard pages.
 *
 * The library's stat tile prints its value's prefix and suffix as written
 * (formatMetricValue appends them without the app's translate), so a Dutch
 * user read "0,0 days" while the catalogue holds " dagen". The manifest keeps
 * the English source text; this walks the dashboard widgets once, also the
 * stat tiles inside a stats-block grid, and puts the translated text in
 * before the manifest is handed to CnAppRoot. A `@config.<key>` token is left
 * alone: it is resolved by the library, not translated.
 *
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */

const AFFIXES = ['prefix', 'suffix']

/**
 * Translate one format spec's affixes in place.
 *
 * @param {object} format A `{ style, prefix?, suffix? }` format spec.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {void}
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
function translateFormat(format, translate) {
	if (!format || typeof format !== 'object') {
		return
	}
	for (const key of AFFIXES) {
		const text = format[key]
		if (
			typeof text === 'string'
			&& text.trim() !== ''
			&& !text.startsWith('@')
		) {
			format[key] = translate(text)
		}
	}
}

/**
 * Translate the stat affixes of every dashboard page in the manifest.
 *
 * @param {object} manifest The merged manifest.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {object} The same manifest, changed in place.
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
export function translateStatFormatLabels(manifest, translate) {
	for (const page of manifest?.pages || []) {
		const widgets = page?.type === 'dashboard' ? page.config?.widgets : null
		for (const widget of Array.isArray(widgets) ? widgets : []) {
			translateFormat(widget?.content?.format, translate)
			const stats = widget?.content?.stats
			for (const stat of Array.isArray(stats) ? stats : []) {
				translateFormat(stat?.format, translate)
			}
		}
	}
	return manifest
}

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Translate the submit label of every dashboard widget in the manifest.
 *
 * A form widget on a dashboard (the contact moment form on the werkplek)
 * printed its `content.submitLabel` as the manifest writes it, so a Dutch
 * user saw "Save contact moment". The manifest keeps the English source
 * text; this puts the app's translation in before the manifest is handed to
 * CnAppRoot, as translateIndexPageLabels does for index pages. Translating
 * twice is harmless: a translation is not a catalogue key.
 *
 * @param {object} manifest The merged manifest.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {object} The same manifest, changed in place.
 * @spec openspec/changes/round6-board-look/specs/board-look/spec.md
 */
export function translateWidgetSubmitLabels(manifest, translate) {
	for (const page of manifest?.pages || []) {
		const widgets = page?.config?.widgets
		for (const widget of Array.isArray(widgets) ? widgets : []) {
			const content = widget?.content
			if (
				content
				&& typeof content.submitLabel === 'string'
				&& content.submitLabel !== ''
			) {
				content.submitLabel = translate(content.submitLabel)
			}
		}
	}
	return manifest
}

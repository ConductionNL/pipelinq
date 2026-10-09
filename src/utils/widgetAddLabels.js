// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Translate the Add button text of manifest object-list widgets.
 *
 * CnObjectListWidget prints `content.addLabel` as it is written, without
 * running it through the app's translate. A manifest therefore had to pick
 * one language, and the client page ended up with a Dutch "Contactpersoon
 * toevoegen" in the English interface. The manifest now holds the English
 * source text, and this seeds the translated text before the manifest is
 * handed to CnAppRoot. Translating a text twice is harmless: a translation
 * is not a catalogue key, so it comes back unchanged.
 *
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/client-management/spec.md
 */

/**
 * Walk the manifest and translate every object-list widget's addLabel.
 *
 * @param {object} manifest The merged manifest.
 * @param {(text: string) => string} translate Translates an English source string.
 * @return {object} The same manifest, changed in place.
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/client-management/spec.md
 */
export function translateWidgetAddLabels(manifest, translate) {
	const seen = new Set()
	const walk = (node) => {
		if (!node || typeof node !== 'object' || seen.has(node)) {
			return
		}
		seen.add(node)
		if (Array.isArray(node)) {
			node.forEach(walk)
			return
		}
		if (
			node.type === 'object-list'
			&& node.content
			&& typeof node.content.addLabel === 'string'
			&& node.content.addLabel !== ''
		) {
			node.content.addLabel = translate(node.content.addLabel)
		}
		Object.values(node).forEach(walk)
	}
	walk(manifest)
	return manifest
}

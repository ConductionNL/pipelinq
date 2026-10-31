// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * What the Advanced page reads about the menu (board PqGeavanceerdHub): the
 * structure profile, the modules in and out of the menu by their translated
 * labels, and how many reports the Reports page offers. It goes into the
 * manifest's `runtime.menu`, where the page's status pills and its menu
 * structure panel read it as `{runtime.menu.<key>}` and `visibleIf`.
 *
 * @spec openspec/changes/advanced-hub-status-panel/specs/admin-settings/spec.md#requirement-the-advanced-page-shows-the-menu-as-it-is-set
 */

/**
 * @param {object} options The inputs.
 * @param {string} options.profile The structure profile (`simple` or `full`).
 * @param {object} options.profileFile The simple profile file, which declares the modules.
 * @param {Array<string>} options.enabled The module keys that are on.
 * @param {object} options.manifest The built manifest.
 * @param {(text: string) => string} options.translate The app's translate function.
 * @return {{profile: string, modulesOn: string, modulesOff: string, modulesOnCount: number, modulesCount: number, reports: number}} The runtime menu facts.
 */
export function menuRuntime({ profile, profileFile, enabled, manifest, translate }) {
	const modules = Object.entries(profileFile?.modules || {})
	const on = new Set(Array.isArray(enabled) ? enabled : [])
	const label = ([, module]) => translate(module.label)
	const list = (entries) => entries.map(label).join(', ')
	const reportsPage = (manifest?.pages || []).find((page) => page.id === 'Reports')
	return {
		profile,
		modulesOn: list(modules.filter(([key]) => on.has(key))),
		modulesOff: list(modules.filter(([key]) => !on.has(key))),
		modulesOnCount: modules.filter(([key]) => on.has(key)).length,
		modulesCount: modules.length,
		// Per module key whether it is in the menu: the full profile shows every module.
		enabled: Object.fromEntries(modules.map(([key]) => [key, profile !== 'simple' || on.has(key)])),
		reports: Array.isArray(reportsPage?.config?.cards) ? reportsPage.config.cards.length : 0,
	}
}

/**
 * The manifest with `runtime.menu` set, keeping any runtime it already has.
 *
 * @param {object} manifest The built manifest.
 * @param {object} menu The facts from {@link menuRuntime}.
 * @return {object} The manifest, changed in place and returned.
 */
export function seedMenuRuntime(manifest, menu) {
	manifest.runtime = { ...(manifest.runtime || {}), menu }
	return manifest
}

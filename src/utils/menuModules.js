// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Modules and the start page of the simple structure profile.
 *
 * pipelinq serves a contact centre and a sales team from one app. The simple
 * menu is the contact centre's. Sales, Marketing, Point of sale, Products,
 * Contracts and Loyalty are MODULES: sets of menu entries that stay out of the
 * simple menu until an administrator names them in the setting
 * `menu_modules`. Off or on, every page of a module opens from the Modules
 * page, so switching a module off never takes a page away.
 *
 * The profile file (`src/menu-layout.simple.json`) declares the modules:
 *
 *   modules         { <key>: { label, ids, menu } }
 *                   `ids` are the manifest menu entries that leave the menu
 *                   while the module is off. `menu` joins the profile's own
 *                   `menu` when it is on.
 *   modulesCaption  The caption the entries of switched-on modules sit under.
 *   home            { page, rootMovesTo }. The page the app opens on.
 *
 * This module only rewrites the profile file and the built manifest. It never
 * looks at who the reader is: every page keeps its own permission checks.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md
 */

/** The app setting, and the initial-state key the page controller provides. */
export const MODULES_SETTING = 'menu_modules'

/**
 * The modules a stored value switches on.
 *
 * The setting is a comma separated list of module keys. A key the profile
 * file does not declare is dropped, so a typing mistake switches nothing on.
 *
 * @param {unknown} raw The stored setting, as initial state hands it over.
 * @param {object} profileFile The profile file that declares the modules.
 * @return {Array<string>} The known keys, in the order the file declares them.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-104
 */
export function resolveMenuModules(raw, profileFile) {
	const known = Object.keys(profileFile?.modules || {})
	const wanted = new Set(
		String(typeof raw === 'string' ? raw : '')
			.split(',')
			.map((key) => key.trim().toLowerCase())
			.filter((key) => key !== ''),
	)
	return known.filter((key) => wanted.has(key))
}

/**
 * The profile file with its modules applied.
 *
 * A module that is off adds its `ids` to `removals`. A module that is on adds
 * its `menu` to the profile menu, takes its `ids` out of `removals` (Loyalty
 * is removed by the full profile too, and must come back when its module is
 * on), and brings the modules caption with it.
 *
 * A file that declares no modules comes back as it went in, which is what
 * keeps the full profile untouched.
 *
 * @param {object} profileFile The profile file.
 * @param {Array<string>} enabled The module keys that are on.
 * @return {object} A new profile file. The one passed in is not changed.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-104
 */
export function applyMenuModules(profileFile, enabled) {
	const modules = profileFile?.modules
	if (!modules || typeof modules !== 'object') {
		return profileFile
	}
	const on = new Set(Array.isArray(enabled) ? enabled : [])
	const removals = new Set(profileFile.removals || [])
	const menu = [...(profileFile.menu || [])]
	let anyOn = false
	for (const key of Object.keys(modules)) {
		const module = modules[key] || {}
		const ids = Array.isArray(module.ids) ? module.ids : []
		if (on.has(key)) {
			anyOn = true
			ids.forEach((id) => removals.delete(id))
			menu.push(...(Array.isArray(module.menu) ? module.menu : []))
		} else {
			ids.forEach((id) => removals.add(id))
		}
	}
	if (anyOn && profileFile.modulesCaption) {
		menu.push(profileFile.modulesCaption)
	}
	return { ...profileFile, removals: [...removals], menu }
}

/**
 * Give the built manifest the profile's start page.
 *
 * The app opens on `/`. In pipelinq that address belongs to the Sales
 * overview, which the simple menu does not show. So the page that owns `/`
 * moves to `home.rootMovesTo`, and the caller redirects `/` to `home.page`.
 * The moved page keeps its id, so every link by route name still opens it.
 *
 * Nothing changes when the profile declares no home, when the home page does
 * not exist, or when it already owns `/`.
 *
 * @param {object} manifest The built manifest.
 * @param {{ page?: string, rootMovesTo?: string }|undefined} home The profile's `home`.
 * @return {{ manifest: object, homePage: string|null }} The manifest, and the
 *   page id `/` must redirect to (null when nothing moved).
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-105
 */
export function applyHomePage(manifest, home) {
	const pages = manifest?.pages || []
	const target = pages.find((page) => page.id === home?.page)
	const movesTo = home?.rootMovesTo
	if (
		!target
		|| target.route === '/'
		|| typeof movesTo !== 'string'
		|| !movesTo.startsWith('/')
		|| pages.some((page) => page.route === movesTo)
	) {
		return { manifest, homePage: null }
	}
	return {
		manifest: {
			...manifest,
			pages: pages.map((page) =>
				page.route === '/' ? { ...page, route: movesTo } : page,
			),
		},
		homePage: target.id,
	}
}

/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The manifest fragments in src/manifest.d against the contract in its
 * README: each fragment adds pages and menu entries, merged onto the bundled
 * manifest in filename order by the library's buildManifest.
 *
 * Covers:
 *  - every fragment carries only pages, menu and annotation keys, and every
 *    page has an id, a route, a type, a title and a config.
 *  - no page id or route is claimed twice across the manifest and fragments.
 *  - the merged manifest holds every fragment page, merges fragment menu
 *    entries that share an id into one, and every menu route names a page.
 *  - the placeholder fragment stays empty.
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { describe, expect, it } from 'vitest'
import bundledManifest from '../../src/manifest.json'

const modules = import.meta.glob('../../src/manifest.d/*.json', { eager: true, import: 'default' })
const names = Object.keys(modules).sort()
const fragments = names.map((name) => modules[name])
const short = (name) => name.split('/').pop()

const ALLOWED_KEYS = ['pages', 'menu', '_meta', '_comment', '_note']
const PAGE_TYPES = ['index', 'detail', 'dashboard', 'custom', 'links', 'reports', 'store', 'flow']

/**
 * Every menu entry, children included.
 *
 * @param {Array<object>} entries The menu entries.
 * @return {Array<object>} The entries, depth first.
 */
function flatten(entries) {
	return (entries || []).flatMap((entry) => [entry, ...flatten(entry.children)])
}

describe('manifest fragments', () => {
	it('loads every fragment in the directory', () => {
		expect(names.length).toBeGreaterThan(20)
		expect(names.map(short)).toContain('_placeholder.json')
	})

	it('gives each fragment only pages, menu and annotation keys', () => {
		const bad = []
		names.forEach((name, i) => {
			for (const key of Object.keys(fragments[i])) {
				if (!ALLOWED_KEYS.includes(key)) bad.push(`${short(name)}: ${key}`)
			}
			for (const key of ['pages', 'menu']) {
				if (fragments[i][key] !== undefined && !Array.isArray(fragments[i][key])) bad.push(`${short(name)}: ${key} is not a list`)
			}
		})
		expect(bad).toEqual([])
	})

	it('gives every fragment page an id, an absolute route, a known type, a title and a config', () => {
		const bad = []
		names.forEach((name, i) => {
			for (const page of fragments[i].pages || []) {
				const where = `${short(name)}: ${page.id}`
				if (typeof page.id !== 'string' || page.id === '') bad.push(`${where}: id`)
				if (typeof page.route !== 'string' || !page.route.startsWith('/')) bad.push(`${where}: route ${page.route}`)
				if (!PAGE_TYPES.includes(page.type)) bad.push(`${where}: type ${page.type}`)
				if (typeof page.title !== 'string' || page.title === '') bad.push(`${where}: title`)
				if (!page.config || typeof page.config !== 'object') bad.push(`${where}: config`)
			}
		})
		expect(bad).toEqual([])
	})

	it('claims no page id or route twice across the manifest and its fragments', () => {
		const pages = [...bundledManifest.pages, ...fragments.flatMap((fragment) => fragment.pages || [])]
		const twice = (values) => values.filter((value, i) => values.indexOf(value) !== i)

		expect(twice(pages.map((page) => page.id))).toEqual([])
		expect(twice(pages.map((page) => page.route))).toEqual([])
	})

	it('merges every fragment page into the manifest, after the bundled pages', () => {
		const merged = buildManifest(bundledManifest, fragments)
		const fragmentIds = fragments.flatMap((fragment) => (fragment.pages || []).map((page) => page.id))
		const mergedIds = merged.pages.map((page) => page.id)

		expect(fragmentIds.length).toBeGreaterThan(40)
		expect(mergedIds.slice(0, bundledManifest.pages.length)).toEqual(bundledManifest.pages.map((page) => page.id))
		expect(mergedIds.slice(bundledManifest.pages.length)).toEqual(fragmentIds)
	})

	it('merges fragment menu entries that share an id into one, keeping every child', () => {
		const merged = buildManifest(bundledManifest, fragments)
		const declaring = fragments.filter((fragment) => (fragment.menu || []).some((entry) => entry.id === 'MarketingGroup'))
		const expectedChildren = declaring.flatMap((fragment) => fragment.menu.find((entry) => entry.id === 'MarketingGroup').children.map((child) => child.id))

		expect(declaring.length).toBeGreaterThan(1)
		expect(merged.menu.filter((entry) => entry.id === 'MarketingGroup')).toHaveLength(1)
		const group = merged.menu.find((entry) => entry.id === 'MarketingGroup')
		expect(group.children.map((child) => child.id).sort()).toEqual([...expectedChildren].sort())
	})

	it('points every merged menu route at a page', () => {
		const merged = buildManifest(bundledManifest, fragments)
		const pageIds = new Set(merged.pages.map((page) => page.id))
		const dangling = flatten(merged.menu)
			.filter((entry) => typeof entry.route === 'string' && !pageIds.has(entry.route))
			.map((entry) => `${entry.id} -> ${entry.route}`)

		expect(dangling).toEqual([])
	})

	it('keeps the placeholder fragment empty', () => {
		expect(modules['../../src/manifest.d/_placeholder.json']).toEqual({ pages: [], menu: [] })
	})
})

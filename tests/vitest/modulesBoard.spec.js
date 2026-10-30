// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Modules page as board PqModules draws it: six module cards (icon, title,
 * an in-the-menu pill, a description, the pages as links, Open), the count
 * beside the heading and a note under the cards; then "Ook in Pipelinq" and
 * "Meer" as list cards. Live drew 31 link cards under seven captions.
 *
 * @spec openspec/specs/modules-page-as-board/spec.md#requirement-the-modules-page-draws-module-cards-and-list-cards
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import CnLinkCardsPage from '@conduction/nextcloud-vue/src/components/CnLinkCardsPage/CnLinkCardsPage.vue'
import CnPageRenderer from '@conduction/nextcloud-vue/src/components/CnPageRenderer/CnPageRenderer.vue'
import { applyHomePage, applyMenuModules } from '../../src/utils/menuModules.js'
import { menuRuntime, seedMenuRuntime } from '../../src/utils/menuRuntime.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const fragments = fs.readdirSync(path.join(ROOT, 'src', 'manifest.d')).filter((name) => name.endsWith('.json')).sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simpleFile = readJson('src', 'menu-layout.simple.json')

/**
 * The simple structure's built manifest with these modules in the menu.
 *
 * @param {string[]} enabled The module keys an administrator put in the menu.
 * @return {object} The manifest.
 */
function manifestWith(enabled) {
	const { manifest } = applyHomePage(
		buildProfiledManifest(buildManifest, readJson('src', 'manifest.json'), fragments, applyMenuModules(simpleFile, enabled)),
		simpleFile.home,
	)
	return seedMenuRuntime(manifest, menuRuntime({ profile: 'simple', profileFile: simpleFile, enabled, manifest, translate: (t) => t }))
}

/**
 * Open /modules through the page renderer.
 *
 * @param {object} manifest The manifest.
 * @return {Promise<object>} The mounted shell.
 */
async function open(manifest) {
	const router = createRouter({
		history: createMemoryHistory('/apps/pipelinq'),
		routes: manifest.pages.map((item) => ({ name: item.id, path: item.route, component: CnPageRenderer })),
	})
	router.push('/modules')
	await router.isReady()
	const wrapper = mount({ template: '<router-view />' }, {
		global: { plugins: [router], provide: { cnManifest: manifest, cnTranslate: (t) => t, cnPageTypes: { links: CnLinkCardsPage } } },
	})
	await flushPromises()
	return wrapper
}

describe('the Modules page as board PqModules', () => {
	const page = manifestWith([]).pages.find((item) => item.id === 'Modules')

	it('declares the six modules in the board order, each with its pages', () => {
		expect(page.config.modules.map((module) => module.id)).toEqual(['sales', 'marketing', 'products', 'pos', 'contracts', 'loyalty'])
		expect(page.config.modules.find((module) => module.id === 'marketing').pages).toHaveLength(15)
		expect(page.config.modules.find((module) => module.id === 'pos').pages).toHaveLength(4)
	})

	it('draws a card per module, says which are in the menu, and the rest as two list cards', async () => {
		const wrapper = await open(manifestWith(['sales', 'marketing', 'products']))
		expect(wrapper.findAll('[data-testid="cn-link-cards-modules"]')).toHaveLength(1)
		expect(wrapper.text()).toContain('3 of 6')
		const groups = wrapper.findAll('[data-testid="cn-link-cards-group"]')
		expect(groups.map((group) => group.attributes('data-group'))).toEqual(['other', 'more'])
		expect(groups.every((group) => group.classes().includes('cn-link-cards-page__group--list'))).toBe(true)
	})

	it('keeps every page reachable: each module page and each list row resolves', async () => {
		const manifest = manifestWith([])
		const routes = new Set(manifest.pages.map((item) => item.id))
		for (const module of page.config.modules) {
			for (const target of module.pages) {
				expect(routes.has(target.route), `${module.id} ${target.route}`).toBe(true)
			}
		}
		for (const card of page.config.cards.filter((item) => item.route)) {
			expect(routes.has(card.route), card.id).toBe(true)
		}
	})
})

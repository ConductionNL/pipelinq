// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Modules page, mounted through the library's page renderer.
 *
 * The first version of this page imported a component the library does not
 * export. The page rendered an empty content area with no error, and every
 * assertion on the manifest still passed, because the manifest was right.
 *
 * The page is now `type: "links"`, the library's typed page of link cards.
 * That page leaves out a card whose route does not resolve, so a manifest
 * assertion still cannot tell 29 cards from none. This spec mounts the
 * library's real CnPageRenderer on the real `/modules` route, with the simple
 * structure's built manifest and a router built from its own pages, and counts
 * what a reader would see.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
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
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const fragments = fs
	.readdirSync(path.join(ROOT, 'src', 'manifest.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.map((name) => readJson('src', 'manifest.d', name))
const simpleFile = readJson('src', 'menu-layout.simple.json')
const { manifest } = applyHomePage(
	buildProfiledManifest(
		buildManifest,
		readJson('src', 'manifest.json'),
		fragments,
		applyMenuModules(simpleFile, []),
	),
	simpleFile.home,
)
const page = manifest.pages.find((item) => item.id === 'Modules')
const GROUPS = [
	'sales',
	'marketing',
	'pos',
	'products',
	'contracts',
	'loyalty',
	'other',
]

/**
 * Open `/modules` the way the app does: every manifest page is a route that
 * renders the library's page renderer, which picks the page type itself.
 *
 * @param {(text: string) => string} [translate] The app's translate function.
 * @return {Promise<{wrapper: object, router: object}>} The mounted app shell.
 */
async function openModules(translate = (text) => text) {
	const router = createRouter({
		history: createMemoryHistory('/apps/pipelinq'),
		routes: manifest.pages.map((item) => ({
			name: item.id,
			path: item.route,
			component: CnPageRenderer,
		})),
	})
	router.push('/modules')
	await router.isReady()
	const wrapper = mount(
		{ template: '<router-view />' },
		{
			global: {
				plugins: [router],
				provide: {
					cnManifest: manifest,
					cnTranslate: translate,
					// The page type under test, resolved now. The library's
					// default map loads every page type lazily.
					cnPageTypes: { links: CnLinkCardsPage },
				},
			},
		},
	)
	await flushPromises()
	return { wrapper, router }
}

describe('the Modules page, through the page renderer', () => {
	it('is a typed links page with no component of our own', () => {
		expect(page.type).toBe('links')
		expect(page.component).toBeUndefined()
		expect(
			fs.existsSync(path.join(ROOT, 'src', 'views', 'ModulesPage.vue')),
		).toBe(false)
		expect(
			fs.readFileSync(path.join(ROOT, 'src', 'registry.js'), 'utf8'),
		).not.toContain('ModulesPage')
	})

	it('names a route that resolves on every one of its 29 cards', async () => {
		const { router } = await openModules()
		expect(page.config.cards).toHaveLength(29)
		for (const card of page.config.cards) {
			expect(router.hasRoute(card.route), card.id).toBe(true)
		}
	})

	it('shows all 29 cards in seven groups, each a link to its own page', async () => {
		const { wrapper } = await openModules()
		expect(wrapper.find('[data-testid="cn-link-cards-page"]').exists()).toBe(
			true,
		)

		const cards = wrapper.findAll('[data-testid="cn-link-card"]')
		expect(cards).toHaveLength(29)
		const hrefs = cards.map((card) => card.attributes('href'))
		const expected = page.config.cards.map(
			(card) =>
				'/apps/pipelinq'
				+ manifest.pages.find((item) => item.id === card.route).route,
		)
		expect([...hrefs].sort()).toEqual([...expected].sort())
		expect(new Set(hrefs).size).toBe(29)
		expect(hrefs).toContain('/apps/pipelinq/leads')
		// The sales overview is reached at the address the start page gave it.
		expect(hrefs).toContain('/apps/pipelinq/sales-overview')

		const groups = wrapper.findAll('[data-testid="cn-link-cards-group"]')
		expect(groups.map((group) => group.attributes('data-group'))).toEqual(GROUPS)
		expect(groups[1].findAll('[data-testid="cn-link-card"]')).toHaveLength(15)
	})

	it('shows every card with its own words', async () => {
		const { wrapper } = await openModules()
		expect(wrapper.text()).toContain('Modules and more')
		expect(wrapper.text()).toContain(page.config.description)
		for (const card of page.config.cards) {
			expect(wrapper.text(), card.id).toContain(card.label)
			expect(wrapper.text(), card.id).toContain(card.description)
		}
	})

	it('has every string in the app translations, and reads Dutch through them', async () => {
		const en = readJson('l10n', 'en.json').translations
		const nl = readJson('l10n', 'nl.json').translations
		const strings = [
			page.title,
			page.config.description,
			...Object.values(page.config.categories),
			...page.config.cards.flatMap((card) => [card.label, card.description]),
		]
		for (const text of strings) {
			expect(en[text], `en "${text}"`).toBeTruthy()
			expect(nl[text], `nl "${text}"`).toBeTruthy()
		}
		const { wrapper } = await openModules((text) => nl[text] ?? text)
		expect(wrapper.text()).toContain('Modules en meer')
		expect(wrapper.text()).toContain('Verkoopoverzicht')
		expect(wrapper.text()).not.toContain('Sales overview')
		expect(wrapper.findAll('[data-testid="cn-link-card"]')).toHaveLength(29)
	})

	it('opens the page a card names', async () => {
		const { wrapper, router } = await openModules()
		await wrapper.find('a[href="/apps/pipelinq/leads"]').trigger('click')
		await flushPromises()
		expect(router.currentRoute.value.name).toBe('Leads')
	})
})

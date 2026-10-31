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
 * The page is now `type: "links"`, the library's typed page of link cards,
 * drawn as board PqModules: module cards, then two list cards. That page
 * leaves out a link whose route does not resolve, so a manifest assertion
 * still cannot tell the links from none. This spec mounts the
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
const GROUPS = ['other', 'more']

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

	it('names a route that resolves on every module page and every list row', async () => {
		const { router } = await openModules()
		for (const module of page.config.modules) {
			for (const target of module.pages) {
				expect(router.hasRoute(target.route), `${module.id} ${target.route}`).toBe(true)
			}
		}
		for (const card of page.config.cards.filter((item) => item.route)) {
			expect(router.hasRoute(card.route), card.id).toBe(true)
		}
	})

	it('shows the six modules and the rest as two list cards, each row a link to its own page', async () => {
		const { wrapper } = await openModules()
		expect(wrapper.find('[data-testid="cn-link-cards-page"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="cn-link-cards-modules"]').exists()).toBe(true)
		const rows = wrapper.findAll('[data-testid="cn-link-card"]')
		expect(rows).toHaveLength(page.config.cards.length)
		const hrefs = rows.map((row) => row.attributes('href'))
		expect(hrefs).toContain('/apps/pipelinq/tasks')
		const groups = wrapper.findAll('[data-testid="cn-link-cards-group"]')
		expect(groups.map((group) => group.attributes('data-group'))).toEqual(GROUPS)
		// Every module page is a link on its module card.
		const html = wrapper.html()
		expect(html).toContain('/apps/pipelinq/leads')
		expect(html).toContain('/apps/pipelinq/sales-overview')
	})

	it('shows every module and row with its own words', async () => {
		const { wrapper } = await openModules()
		expect(wrapper.text()).toContain('Modules and more')
		expect(wrapper.text()).toContain(page.config.description)
		for (const module of page.config.modules) {
			expect(wrapper.text(), module.id).toContain(module.label)
			expect(wrapper.text(), module.id).toContain(module.description)
		}
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
			page.config.modulesTitle,
			page.config.footnote,
			...Object.values(page.config.categories),
			...page.config.modules.flatMap((module) => [module.label, module.description, ...module.pages.map((item) => item.label)]),
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
	})

	it('opens the page a card names', async () => {
		const { wrapper, router } = await openModules()
		await wrapper.find('a[href="/apps/pipelinq/leads"]').trigger('click')
		await flushPromises()
		expect(router.currentRoute.value.name).toBe('Leads')
	})
})

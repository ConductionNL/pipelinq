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
 * assertion still cannot tell its links from none. Since board PqModules the
 * six modules are module cards, each linking its pages, and the rest are two
 * lists (Also in Pipelinq, More). This spec mounts the
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
import { applyHomePage, applyMenuModules, markModuleCards } from '../../src/utils/menuModules.js'
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
	markModuleCards(
		buildProfiledManifest(
			buildManifest,
			readJson('src', 'manifest.json'),
			fragments,
			applyMenuModules(simpleFile, ['sales']),
		),
		['sales'],
		true,
	),
	simpleFile.home,
)
const page = manifest.pages.find((item) => item.id === 'Modules')
const GROUPS = ['other', 'more']
const modulePages = page.config.modules.flatMap((module) => module.pages)

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

	it('names a route that resolves on every page link and in-app card', async () => {
		const { router } = await openModules()
		expect(page.config.modules).toHaveLength(6)
		expect(modulePages).toHaveLength(26)
		for (const link of [...modulePages, ...page.config.cards.filter((card) => !card.href)]) {
			expect(router.hasRoute(link.route), link.label).toBe(true)
		}
	})

	it('shows six module cards, each linking its pages, and two lists', async () => {
		const { wrapper } = await openModules()
		const modules = wrapper.findAll('[data-testid="cn-module-card"]')
		expect(modules).toHaveLength(6)
		const pageLinks = modules.flatMap((card) => card.findAll('a').map((link) => link.attributes('href')))
		expect(pageLinks).toContain('/apps/pipelinq/leads')
		// The sales overview is reached at the address the start page gave it.
		expect(pageLinks).toContain('/apps/pipelinq/sales-overview')

		const groups = wrapper.findAll('[data-testid="cn-link-cards-group"]')
		expect(groups.map((group) => group.attributes('data-group'))).toEqual(GROUPS)
		expect(wrapper.findAll('[data-testid="cn-link-card"]')).toHaveLength(page.config.cards.length)
	})

	it('badges a switched-on module as in the menu and offers the admin settings on one that is off', async () => {
		const { wrapper } = await openModules()
		const card = (id) => wrapper.findAll('[data-testid="cn-module-card"]')[page.config.modules.findIndex((module) => module.id === id)]
		expect(card('sales').find('[data-testid="cn-module-card-settings"]').exists()).toBe(false)
		expect(card('pos').find('[data-testid="cn-module-card-settings"]').exists()).toBe(true)
	})

	it('drops the admin settings link for a reader who is not an administrator', () => {
		const plain = markModuleCards(manifest, [], false)
		const modules = plain.pages.find((item) => item.id === 'Modules').config.modules
		expect(modules.every((module) => module.settings === undefined && module.enabled === false)).toBe(true)
	})

	it('shows every card with its own words', async () => {
		const { wrapper } = await openModules()
		expect(wrapper.text()).toContain('Modules and more')
		expect(wrapper.text()).toContain(page.config.description)
		for (const item of [...page.config.cards, ...page.config.modules]) {
			expect(wrapper.text(), item.id).toContain(item.label)
			expect(wrapper.text(), item.id).toContain(item.description)
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
			...page.config.cards.flatMap((card) => [card.label, card.description]),
			...page.config.modules.flatMap((module) => [module.label, module.description, module.settings?.label]).filter(Boolean),
			...modulePages.map((link) => link.label),
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

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Modules page, mounted.
 *
 * The first version of this page imported a component the library does not
 * export. The import was undefined, the page rendered an empty content area
 * with no error, and every assertion on the manifest still passed, because the
 * manifest was right. So this spec mounts the real view, with the real page
 * config and a real router built from the simple structure's own pages, and
 * counts what a reader would see.
 *
 * @spec openspec/changes/simple-structure-profile/specs/navigation-ia/spec.md#REQ-NIA-103
 */

import { buildManifest } from '@conduction/nextcloud-vue/src/utils/buildManifest.js'
import { flushPromises, mount } from '@vue/test-utils'
import fs from 'fs'
import path from 'path'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import ModulesPage from '../../src/views/ModulesPage.vue'
import { applyHomePage, applyMenuModules } from '../../src/utils/menuModules.js'
import { buildProfiledManifest } from '../../src/utils/structureProfile.js'

const nl = vi.hoisted(() => ({ translations: {} }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => nl.translations[text] ?? text,
}))

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

/**
 * Mount the page the way the page renderer does: the page title plus its
 * config, flattened into props.
 *
 * @return {Promise<object>} The wrapper.
 */
async function mountPage() {
	const router = createRouter({
		history: createMemoryHistory('/apps/pipelinq'),
		routes: manifest.pages.map((item) => ({
			name: item.id,
			path: item.route,
			component: { render: () => null },
		})),
	})
	router.push('/modules')
	await router.isReady()
	const wrapper = mount(ModulesPage, {
		props: { title: page.title, ...page.config },
		global: { plugins: [router] },
	})
	await flushPromises()
	return wrapper
}

describe('the Modules page, mounted', () => {
	it('is the component the registry hands the page renderer', () => {
		const registry = fs.readFileSync(
			path.join(ROOT, 'src', 'registry.js'),
			'utf8',
		)
		expect(page.component).toBe('ModulesPage')
		expect(registry).toContain(
			"import ModulesPage from './views/ModulesPage.vue'",
		)
		expect(registry).toMatch(
			/ModulesPage: \{\s+kind: 'page',\s+component: ModulesPage,/,
		)
	})

	it('shows all 29 cards, in seven groups, each a link to its own page', async () => {
		const wrapper = await mountPage()
		const cards = wrapper.findAll('[data-testid="modules-card"]')
		expect(cards).toHaveLength(29)
		expect(page.config.cards).toHaveLength(29)

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

		expect(
			wrapper
				.findAll('[data-testid^="modules-group-"]')
				.map((group) => group.attributes('data-testid')),
		).toEqual(
			[
				'sales',
				'marketing',
				'pos',
				'products',
				'contracts',
				'loyalty',
				'other',
			].map((key) => `modules-group-${key}`),
		)
		expect(
			wrapper.find('[data-testid="modules-group-marketing"]').findAll('a'),
		).toHaveLength(15)
	})

	it('shows the heading, the lead paragraph and every card with its own words', async () => {
		const wrapper = await mountPage()
		expect(wrapper.find('h2').text()).toBe('Modules and more')
		expect(wrapper.text()).toContain(page.config.description)
		for (const card of page.config.cards) {
			expect(wrapper.text(), card.id).toContain(card.label)
			expect(wrapper.text(), card.id).toContain(card.description)
		}
	})

	it('reads Dutch for a Dutch reader', async () => {
		nl.translations = readJson('l10n', 'nl.json').translations
		try {
			const wrapper = await mountPage()
			expect(wrapper.find('h2').text()).toBe('Modules en meer')
			expect(wrapper.find('#modules-group-sales').text()).toBe('Verkoop')
			expect(wrapper.text()).toContain('Verkoopoverzicht')
			expect(wrapper.text()).not.toContain('Sales overview')
		} finally {
			nl.translations = {}
		}
	})

	it('opens the page a card names', async () => {
		const wrapper = await mountPage()
		await wrapper.find('a[href="/apps/pipelinq/leads"]').trigger('click')
		await flushPromises()
		expect(wrapper.vm.$route.name).toBe('Leads')
	})

	it('draws nothing it was not given: no cards, no groups', async () => {
		const wrapper = mount(ModulesPage, {
			props: { title: 'Modules and more', categories: { sales: 'Sales' } },
			global: { stubs: { 'router-link': true } },
		})
		expect(wrapper.findAll('[data-testid^="modules-group-"]')).toHaveLength(0)
		expect(wrapper.find('h2').text()).toBe('Modules and more')
	})
})

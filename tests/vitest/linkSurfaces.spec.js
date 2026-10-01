// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Surfaces whose only job is to go somewhere behave like links.
 *
 *  - XWikiArticleList with `linkExternal` renders each article as a real
 *    `<a href>` to its xWiki page (new tab), so it can be middle-clicked and
 *    copied; without it, the sidebar keeps its in-panel `select`.
 *  - ComplaintsOverviewWidget stays a card: a plain click routes in place, a
 *    ctrl/cmd/shift or middle click opens the complaints list in a new tab.
 */

import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', async () => ({
	...(await import('@conduction/nextcloud-vue/src/utils/linkNavigation.js')),
	...(await import('@conduction/nextcloud-vue/src/utils/safeHref.js')),
}))

const { default: XWikiArticleList } = await import('../../src/components/xwiki/XWikiArticleList.vue')
const { default: ComplaintsOverviewWidget } = await import('../../src/views/widgets/ComplaintsOverviewWidget.vue')

const t = (app, text) => text

const ARTICLES = [
	{ id: 'a', title: 'Opening hours', url: 'https://wiki.example/opening-hours' },
	{ id: 'b', title: 'Draft', url: '' },
]

describe('XWikiArticleList', () => {
	it('renders articles as new-tab links with linkExternal', async () => {
		const wrapper = mount(XWikiArticleList, {
			props: { articles: ARTICLES, linkExternal: true },
			global: { mocks: { t } },
		})
		const links = wrapper.findAll('a')
		expect(links).toHaveLength(1)
		expect(links[0].attributes('href')).toBe('https://wiki.example/opening-hours')
		expect(links[0].attributes('target')).toBe('_blank')
		expect(links[0].attributes('rel')).toBe('noopener noreferrer')
		expect(wrapper.find('[role="button"]').exists()).toBe(false)

		await links[0].trigger('click')
		expect(wrapper.emitted('select')).toBeUndefined()
	})

	it('neutralises a javascript: article URL', () => {
		const wrapper = mount(XWikiArticleList, {
			props: { articles: [{ id: 'x', title: 'Bad', url: 'javascript:alert(1)' }], linkExternal: true },
			global: { mocks: { t } },
		})
		expect(wrapper.find('a').attributes('href')).toBe('#')
	})

	it('emits select without linkExternal', async () => {
		const wrapper = mount(XWikiArticleList, {
			props: { articles: ARTICLES },
			global: { mocks: { t } },
		})
		expect(wrapper.find('a').exists()).toBe(false)

		const items = wrapper.findAll('[role="button"]')
		await items[0].trigger('click')
		await items[1].trigger('keydown', { key: 'Enter' })
		expect(wrapper.emitted('select')).toEqual([[ARTICLES[0]], [ARTICLES[1]]])
	})
})

describe('ComplaintsOverviewWidget', () => {
	const target = { name: 'Tickets', query: { ticketType: 'complaint' } }

	const mountWidget = () => {
		const router = {
			push: vi.fn(() => Promise.resolve()),
			resolve: vi.fn(() => ({ href: '/index.php/apps/pipelinq/tickets?ticketType=complaint' })),
		}
		const wrapper = mount(ComplaintsOverviewWidget, {
			global: { mocks: { t, $router: router } },
		})
		return { wrapper, router }
	}

	afterEach(() => {
		vi.restoreAllMocks()
	})

	it('routes in place on a plain click', async () => {
		const { wrapper, router } = mountWidget()
		await wrapper.trigger('click', { button: 0 })
		expect(router.push).toHaveBeenCalledWith(target)
	})

	it('opens a new tab on a ctrl click and on a middle click', async () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const { wrapper, router } = mountWidget()

		await wrapper.trigger('click', { button: 0, ctrlKey: true })
		await wrapper.trigger('auxclick', { button: 1 })

		expect(open).toHaveBeenCalledTimes(2)
		expect(open).toHaveBeenCalledWith('/index.php/apps/pipelinq/tickets?ticketType=complaint', '_blank', 'noopener,noreferrer')
		expect(router.push).not.toHaveBeenCalled()
	})

	it('opens a new tab on ctrl+Enter and ctrl+Space, and Space does not scroll', async () => {
		const open = vi.spyOn(window, 'open').mockImplementation(() => null)
		const { wrapper, router } = mountWidget()

		await wrapper.trigger('keydown', { key: 'Enter', ctrlKey: true })
		await wrapper.trigger('keydown', { key: ' ', ctrlKey: true })
		expect(open).toHaveBeenCalledTimes(2)
		expect(router.push).not.toHaveBeenCalled()

		const space = new KeyboardEvent('keydown', { key: ' ', cancelable: true })
		wrapper.element.dispatchEvent(space)
		expect(space.defaultPrevented).toBe(true)
		expect(router.push).toHaveBeenCalledWith(target)
	})
})

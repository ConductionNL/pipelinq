// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The xWiki sidebar tab, widget and article viewer driven through a mocked
 * xwiki store: what each asks the store for on mount and on (debounced)
 * search input, the unavailable / loading states, the space browser, opening
 * an article, the "view more" link, and that the viewer sanitises the remote
 * markup before it renders it.
 *
 * @spec openspec/specs/xwiki-proxy/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h, reactive } from 'vue'

const holder = vi.hoisted(() => ({ store: null }))

vi.mock('../../src/store/modules/xwiki.js', () => ({ useXwikiStore: () => holder.store }))
vi.mock('../../src/components/xwiki/XWikiArticleList.vue', () => ({
	default: {
		name: 'XWikiArticleList',
		props: { articles: Array, linkExternal: Boolean },
		emits: ['select'],
		render() {
			return h('ul', { class: 'list-stub' }, this.articles.map((a) => h('li', a.title)))
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('div', { class: 'loading-stub' }) },
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h('button', { class: 'nc-button', onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
}))

globalThis.t = (app, text) => text
const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: XWikiSidebarTab } = await import('../../src/components/xwiki/XWikiSidebarTab.vue')
const { default: XWikiWidget } = await import('../../src/components/xwiki/XWikiWidget.vue')
const { default: XWikiArticleViewer } = await import('../../src/components/xwiki/XWikiArticleViewer.vue')

beforeEach(() => {
	holder.store = reactive({
		articles: [],
		spaces: [],
		currentArticle: null,
		loading: false,
		error: null,
		available: true,
		status: { ok: true },
		checkStatus: vi.fn().mockResolvedValue(undefined),
		search: vi.fn().mockResolvedValue(undefined),
		getPages: vi.fn().mockResolvedValue(undefined),
		getPageContent: vi.fn().mockResolvedValue(undefined),
	})
})

afterEach(() => {
	vi.useRealTimers()
})

describe('XWikiSidebarTab', () => {
	it('checks the status, then searches with the context query and its props', async () => {
		mount(XWikiSidebarTab, { ...mountOptions, props: { contextQuery: 'tax', space: 'Help', tags: ['a'], limit: 4 } })
		await flushPromises()

		expect(holder.store.checkStatus).toHaveBeenCalledTimes(1)
		expect(holder.store.search).toHaveBeenCalledWith({ q: 'tax', space: 'Help', tags: ['a'], limit: 4 })
	})

	it('shows only the unavailable notice once the status says so', async () => {
		holder.store.available = false
		const wrapper = mount(XWikiSidebarTab, mountOptions)
		await flushPromises()

		expect(wrapper.text()).toBe('xWiki integration unavailable')
		expect(wrapper.find('nav').exists()).toBe(false)
	})

	it('keeps the tabs while the status is still unknown', async () => {
		holder.store.available = false
		holder.store.status = null
		const wrapper = mount(XWikiSidebarTab, mountOptions)
		await flushPromises()

		expect(wrapper.find('nav').exists()).toBe(true)
	})

	it('shows the loader instead of the list while loading', async () => {
		holder.store.loading = true
		const wrapper = mount(XWikiSidebarTab, mountOptions)
		await flushPromises()

		expect(wrapper.find('.loading-stub').exists()).toBe(true)
		expect(wrapper.find('.list-stub').exists()).toBe(false)
	})

	it('searches once, 300 ms after the last keystroke', async () => {
		const wrapper = mount(XWikiSidebarTab, { ...mountOptions, props: { limit: 3 } })
		await flushPromises()
		holder.store.search.mockClear()
		vi.useFakeTimers()

		const input = wrapper.find('input[type="search"]')
		await input.setValue('per')
		vi.advanceTimersByTime(200)
		await input.setValue('permit')
		vi.advanceTimersByTime(299)
		expect(holder.store.search).not.toHaveBeenCalled()

		vi.advanceTimersByTime(1)
		expect(holder.store.search).toHaveBeenCalledTimes(1)
		expect(holder.store.search).toHaveBeenCalledWith({ q: 'permit', space: '', tags: [], limit: 3 })
	})

	it('lists the spaces and browses one by click, Enter or Space', async () => {
		holder.store.spaces = ['Help', 'Legal']
		const wrapper = mount(XWikiSidebarTab, { ...mountOptions, props: { limit: 7 } })
		await flushPromises()

		await wrapper.findAll('nav button')[1].trigger('click')
		expect(wrapper.findAll('nav button')[1].classes()).toContain('active')
		const spaces = wrapper.findAll('.xwiki-sidebar-tab__space')
		expect(spaces.map((s) => s.text())).toEqual(['Help', 'Legal'])

		await spaces[0].trigger('click')
		await spaces[1].trigger('keydown', { key: 'Enter' })
		await spaces[1].trigger('keydown', { key: ' ' })
		expect(holder.store.getPages.mock.calls).toEqual([
			['Help', { limit: 7 }],
			['Legal', { limit: 7 }],
			['Legal', { limit: 7 }],
		])
	})

	it('says when there are no spaces', async () => {
		const wrapper = mount(XWikiSidebarTab, mountOptions)
		await flushPromises()
		await wrapper.findAll('nav button')[1].trigger('click')

		expect(wrapper.find('.xwiki-sidebar-tab__empty').text()).toBe('No spaces available')
	})

	it('opens a selected article in the viewer and goes back to search', async () => {
		const wrapper = mount(XWikiSidebarTab, mountOptions)
		await flushPromises()

		wrapper.findComponent({ name: 'XWikiArticleList' }).vm.$emit('select', { id: 'Main.Page', wiki: 'kb' })
		await flushPromises()

		const viewer = wrapper.findComponent({ name: 'XWikiArticleViewer' })
		expect(viewer.props()).toEqual({ wiki: 'kb', page: 'Main.Page' })
		expect(holder.store.getPageContent).toHaveBeenCalledWith('kb', 'Main.Page')

		await viewer.find('.nc-button').trigger('click')
		expect(wrapper.findComponent({ name: 'XWikiArticleViewer' }).exists()).toBe(false)
		expect(wrapper.findAll('nav button')[0].classes()).toContain('active')
	})

	it('falls back to the title and the default wiki for an article without them', () => {
		const ctx = { mode: 'search' }
		XWikiSidebarTab.methods.openArticle.call(ctx, { title: 'Untitled page' })

		expect(ctx).toEqual({ mode: 'article', selectedPage: 'Untitled page', selectedWiki: 'xwiki' })
	})
})

describe('XWikiWidget', () => {
	it('asks for one more article than it shows, to know whether there are more', async () => {
		mount(XWikiWidget, { ...mountOptions, props: { query: 'q', space: 'S', limit: 2 } })
		await flushPromises()

		expect(holder.store.checkStatus).toHaveBeenCalledTimes(1)
		expect(holder.store.search).toHaveBeenCalledWith({ q: 'q', space: 'S', tags: [], limit: 3 })
	})

	it('shows at most the limit and a view-more link when there are more', async () => {
		holder.store.articles = [{ title: 'A' }, { title: 'B' }, { title: 'C' }]
		const wrapper = mount(XWikiWidget, { ...mountOptions, props: { limit: 2 } })
		await flushPromises()

		const list = wrapper.findComponent({ name: 'XWikiArticleList' })
		expect(list.props('articles')).toEqual([{ title: 'A' }, { title: 'B' }])
		expect(list.props('linkExternal')).toBe(true)

		await wrapper.find('.xwiki-widget__more').trigger('click')
		expect(wrapper.emitted('viewMore')).toHaveLength(1)
	})

	it('shows no view-more link when everything fits', async () => {
		holder.store.articles = [{ title: 'A' }, { title: 'B' }]
		const wrapper = mount(XWikiWidget, { ...mountOptions, props: { limit: 2 } })
		await flushPromises()

		expect(wrapper.find('.xwiki-widget__more').exists()).toBe(false)
	})

	it('treats missing articles as none', () => {
		holder.store.articles = null
		const ctx = { store: holder.store, limit: 5 }

		expect(XWikiWidget.computed.visibleArticles.call(ctx)).toEqual([])
		expect(XWikiWidget.computed.hasMore.call(ctx)).toBe(false)
	})

	it('draws its own title and header search unless bare', async () => {
		const wrapper = mount(XWikiWidget, { ...mountOptions, props: { title: 'KB', showSearch: true } })
		await flushPromises()

		expect(wrapper.find('.xwiki-widget__title').text()).toBe('KB')
		expect(wrapper.find('.xwiki-widget__search-input').exists()).toBe(true)
		expect(wrapper.find('form[role="search"]').exists()).toBe(false)
	})

	it('draws a search form and no title when bare, searching on submit', async () => {
		const wrapper = mount(XWikiWidget, { ...mountOptions, props: { showSearch: true, bare: true } })
		await flushPromises()
		holder.store.search.mockClear()

		expect(wrapper.classes()).toContain('xwiki-widget--bare')
		expect(wrapper.find('header').exists()).toBe(false)
		await wrapper.find('.xwiki-widget__search-bar-input').setValue('vat')
		await wrapper.find('form').trigger('submit')

		expect(holder.store.search).toHaveBeenCalledWith({ q: 'vat', space: '', tags: [], limit: 6 })
	})

	it('debounces typed searches', async () => {
		const wrapper = mount(XWikiWidget, { ...mountOptions, props: { showSearch: true } })
		await flushPromises()
		holder.store.search.mockClear()
		vi.useFakeTimers()

		await wrapper.find('input').setValue('a')
		await wrapper.find('input').setValue('ab')
		vi.advanceTimersByTime(300)

		expect(holder.store.search).toHaveBeenCalledTimes(1)
		expect(holder.store.search.mock.calls[0][0].q).toBe('ab')
	})

	it('shows the unavailable notice instead of the list', async () => {
		holder.store.available = false
		const wrapper = mount(XWikiWidget, mountOptions)
		await flushPromises()

		expect(wrapper.find('.xwiki-widget__unavailable').text()).toBe('xWiki integration unavailable')
		expect(wrapper.find('.list-stub').exists()).toBe(false)
	})

	it('shows the loader instead of the list while loading', async () => {
		holder.store.loading = true
		const wrapper = mount(XWikiWidget, mountOptions)
		await flushPromises()

		expect(wrapper.find('.loading-stub').exists()).toBe(true)
		expect(wrapper.find('.list-stub').exists()).toBe(false)
	})
})

describe('XWikiArticleViewer', () => {
	it('loads the page content for its wiki and page', () => {
		mount(XWikiArticleViewer, { ...mountOptions, props: { wiki: 'kb', page: 'Main.A' } })

		expect(holder.store.getPageContent).toHaveBeenCalledWith('kb', 'Main.A')
	})

	it('does not load for an empty page and reloads when the page changes', async () => {
		const wrapper = mount(XWikiArticleViewer, { ...mountOptions, props: { page: '' } })
		expect(holder.store.getPageContent).not.toHaveBeenCalled()

		await wrapper.setProps({ page: 'Main.B' })
		expect(holder.store.getPageContent).toHaveBeenCalledWith('xwiki', 'Main.B')
	})

	it('renders the title and sanitised content with an external link', () => {
		holder.store.currentArticle = {
			title: 'Fees',
			url: 'https://wiki.test/Fees',
			content: '<p>Pay <b>now</b></p><img src="x" onerror="alert(1)"><script>alert(2)</script>',
		}
		const wrapper = mount(XWikiArticleViewer, { ...mountOptions, props: { page: 'Fees' } })

		expect(wrapper.find('.xwiki-article-viewer__title').text()).toBe('Fees')
		const content = wrapper.find('.xwiki-article-viewer__content').html()
		expect(content).toContain('<p>Pay <b>now</b></p>')
		expect(content).not.toContain('onerror')
		expect(content).not.toContain('<script')
		const link = wrapper.find('.xwiki-article-viewer__open')
		expect(link.attributes()).toMatchObject({ href: 'https://wiki.test/Fees', target: '_blank', rel: 'noopener noreferrer' })
	})

	it('renders empty content and no link for an article without them', () => {
		holder.store.currentArticle = { title: 'Bare' }
		const wrapper = mount(XWikiArticleViewer, { ...mountOptions, props: { page: 'Bare' } })

		expect(wrapper.find('.xwiki-article-viewer__content').html()).not.toContain('<p')
		expect(wrapper.find('.xwiki-article-viewer__open').exists()).toBe(false)
	})

	it('shows the loader, then the error', async () => {
		holder.store.loading = true
		const wrapper = mount(XWikiArticleViewer, { ...mountOptions, props: { page: 'P' } })
		expect(wrapper.find('.loading-stub').exists()).toBe(true)

		holder.store.loading = false
		holder.store.error = 'timeout'
		await flushPromises()
		expect(wrapper.find('.xwiki-article-viewer__error').text()).toBe('Failed to load article: timeout')
	})

	it('emits back from its back button', async () => {
		const wrapper = mount(XWikiArticleViewer, { ...mountOptions, props: { page: 'P' } })
		await wrapper.find('.nc-button').trigger('click')

		expect(wrapper.emitted('back')).toHaveLength(1)
	})
})

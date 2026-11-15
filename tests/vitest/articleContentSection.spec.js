// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The article content section: it reads the article by the given id or the
 * page's object id when none is passed, shows its status and the moves that
 * status allows, posts a move to the matching endpoint and reloads, reports a
 * refused move, and resolves the hero image from a URL, an app image or a
 * file path.
 *
 * @spec openspec/specs/marketing-articles/spec.md
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axios = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn(), subscribe: vi.fn(), unsubscribe: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@conduction/nextcloud-vue', () => ({
	cnRenderMarkdown: (text) => '<p>' + text + '</p>',
	resolveImageUrl: (path) => '/resolved/' + path.slice(4),
}))
vi.mock('../../src/modals/ArticleEditModal.vue', () => ({
	default: { name: 'ArticleEditModal', props: ['article'], emits: ['close', 'saved'], render: () => h('div', { class: 'edit-modal' }) },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('div', { class: 'spinner' }) },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], render() { return h('div', { class: 'note' }, this.$slots.default?.()) } },
}))

const translate = (_app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)
globalThis.t = translate

const { default: ArticleContentSection } = await import('../../src/components/marketing/ArticleContentSection.vue')

/**
 * @param {object} options Props and the injected section context.
 * @return {object} The wrapper.
 */
function mountSection({ props = {}, context = null } = {}) {
	return mount(ArticleContentSection, {
		props,
		global: { mocks: { t: translate }, provide: { cnSectionContext: context } },
	})
}

/**
 * @param {object} wrapper The section.
 * @return {Array<string>} The labels of the buttons shown.
 */
function buttons(wrapper) {
	return wrapper.findAll('button').map((b) => b.text())
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
	bus.emit.mockReset()
})

describe('reading the article', () => {
	it('reads the article by the page object id and renders it', async () => {
		axios.get.mockResolvedValueOnce({ data: { article: { id: 'a1', status: 'draft', title: 'Afval', summary: 'Kort', body: 'Tekst', language: 'nl', links: [{ url: 'https://x.nl' }, { url: 'https://y.nl', label: 'Y' }] } } })
		const wrapper = mountSection({ context: { value: { objectId: 'a1' } } })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/articles/a1')
		expect(wrapper.find('.article-preview__title').text()).toBe('Afval')
		expect(wrapper.find('.article-preview__summary').text()).toBe('Kort')
		expect(wrapper.find('.article-preview__body').html()).toContain('<p>Tekst</p>')
		expect(wrapper.find('article').attributes('lang')).toBe('nl')
		expect(wrapper.findAll('.article-preview__links a').map((a) => a.text())).toEqual(['https://x.nl', 'Y'])
		expect(wrapper.find('.article-content__chip').text()).toBe('Draft')
		expect(buttons(wrapper)).toEqual(['Submit for review', 'Publish', 'Edit'])
	})

	it('says when the article cannot be read', async () => {
		axios.get.mockRejectedValueOnce(new Error('404'))
		const wrapper = mountSection({ props: { articleId: 'gone' } })
		await flushPromises()

		expect(wrapper.find('.note').text()).toBe('This article could not be loaded.')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('does not read anything when the article is passed in or there is no id', async () => {
		mountSection({ props: { article: { id: 'a1', status: 'published', title: 'T' } } })
		mountSection()
		await flushPromises()

		expect(axios.get).not.toHaveBeenCalled()
	})

	it('names the agent that drafted it, or "an agent", and marks an unknown status', () => {
		const named = mountSection({ props: { article: { id: 'a', status: 'odd', agentAuthored: true, agentAuthoredBy: 'Writer' } } })
		const anonymous = mountSection({ props: { article: { id: 'b', status: 'archived', agentAuthored: true } } })

		expect(named.find('.article-content__agent-mark').text()).toBe('Drafted by Writer')
		expect(named.find('.article-content__chip').text()).toBe('Unknown')
		expect(anonymous.find('.article-content__agent-mark').text()).toBe('Drafted by an agent')
		expect(buttons(anonymous)).toEqual(['Restore as draft', 'Edit'])
	})
})

describe('moving the article', () => {
	it.each([
		['published', 'Archive', '/a1/archive', undefined],
		['draft', 'Publish', '/a1/publish', undefined],
		['review', 'Return to draft', '/a1/transition', { transition: 'returnToDraft' }],
	])('posts a %s article\'s "%s" to its endpoint, then tells the page', async (status, label, path, body) => {
		axios.post.mockResolvedValueOnce({ data: {} })
		const wrapper = mountSection({ props: { article: { id: 'a1', status } } })

		await wrapper.findAll('button').find((b) => b.text() === label).trigger('click')
		await flushPromises()

		const call = axios.post.mock.calls[0]
		expect(call[0]).toBe('/index.php/apps/pipelinq/api/articles' + path)
		expect(call[1]).toEqual(body)
		expect(wrapper.emitted('refresh')).toHaveLength(1)
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', { source: 'article-content' })
		expect(axios.get).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('reloads an article it read itself after a move', async () => {
		axios.get
			.mockResolvedValueOnce({ data: { article: { id: 'a1', status: 'draft' } } })
			.mockResolvedValueOnce({ data: { article: { id: 'a1', status: 'review' } } })
		axios.post.mockResolvedValueOnce({ data: {} })
		const wrapper = mountSection({ props: { articleId: 'a1' } })
		await flushPromises()

		await wrapper.vm.runTransition({ id: 'submitForReview', endpoint: 'transition' })
		await flushPromises()

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.article-content__chip').text()).toBe('In review')
	})

	it('shows the server\'s refusal, else a general message', async () => {
		axios.post
			.mockRejectedValueOnce({ response: { data: { error: 'Missing title.' } } })
			.mockRejectedValueOnce(new Error('network'))
		const wrapper = mountSection({ props: { article: { id: 'a1', status: 'draft' } } })

		await wrapper.vm.runTransition({ id: 'publish', endpoint: 'publish' })
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Missing title.')

		await wrapper.vm.runTransition({ id: 'publish', endpoint: 'publish' })
		expect(wrapper.vm.actionError).toBe('That move could not be applied.')
		expect(wrapper.vm.busy).toBe(false)
		expect(bus.emit).not.toHaveBeenCalled()
	})

	it('does nothing without an id, and names an unknown move by its id', async () => {
		const wrapper = mountSection({ props: { article: { status: 'draft' } } })

		await wrapper.vm.runTransition({ id: 'publish', endpoint: 'publish' })

		expect(axios.post).not.toHaveBeenCalled()
		expect(wrapper.vm.actionLabel('teleport')).toBe('teleport')
	})
})

describe('refreshing and editing', () => {
	it('listens for page refreshes while mounted', () => {
		const wrapper = mountSection({ props: { article: { id: 'a1' } } })
		expect(bus.subscribe).toHaveBeenCalledWith('cn:page:refresh', wrapper.vm.onPageRefresh)
		wrapper.unmount()
		expect(bus.unsubscribe).toHaveBeenCalledWith('cn:page:refresh', expect.any(Function))
	})

	it('reloads quietly on another widget\'s refresh and lets the page wait for it, but not on its own', async () => {
		axios.get.mockResolvedValue({ data: { article: { id: 'a1', status: 'draft' } } })
		const wrapper = mountSection({ props: { articleId: 'a1' } })
		await flushPromises()
		const waitUntil = vi.fn()

		wrapper.vm.onPageRefresh({ source: 'article-content', waitUntil })
		expect(axios.get).toHaveBeenCalledTimes(1)

		wrapper.vm.onPageRefresh({ source: 'other', waitUntil })
		expect(wrapper.vm.loading).toBe(false)
		expect(waitUntil).toHaveBeenCalledWith(expect.any(Promise))
		await flushPromises()
		expect(axios.get).toHaveBeenCalledTimes(2)
	})

	it('opens the edit dialog on Edit and closes it after a save', async () => {
		axios.get.mockResolvedValue({ data: { article: { id: 'a1', status: 'draft' } } })
		const wrapper = mountSection({ props: { articleId: 'a1' } })
		await flushPromises()

		await wrapper.findAll('button').find((b) => b.text() === 'Edit').trigger('click')
		expect(wrapper.find('.edit-modal').exists()).toBe(true)
		await wrapper.findComponent({ name: 'ArticleEditModal' }).vm.$emit('saved')
		await flushPromises()

		expect(wrapper.find('.edit-modal').exists()).toBe(false)
		expect(wrapper.emitted('refresh')).toHaveLength(1)
	})
})

describe('the hero image', () => {
	it.each([
		['https://cdn.nl/hero.jpg', 'https://cdn.nl/hero.jpg'],
		['app:pipelinq/img/hero.svg', '/resolved/pipelinq/img/hero.svg'],
		['/Marketing/hero 1.png', '/index.php/core/preview.png?file=%2FMarketing%2Fhero%201.png&x=1200&y=630&a=1'],
	])('resolves %s', (heroImage, src) => {
		const wrapper = mountSection({ props: { article: { id: 'a1', heroImage, title: 'T' } } })

		expect(wrapper.find('img').attributes('src')).toBe(src)
		expect(wrapper.find('img').attributes('alt')).toBe('T')
	})

	it('shows the path when the image fails, and tries again for a new image', async () => {
		const wrapper = mountSection({ props: { article: { id: 'a1', heroImage: '/a.png' } } })

		await wrapper.find('img').trigger('error')
		expect(wrapper.find('.article-preview__hero--missing code').text()).toBe('/a.png')

		await wrapper.setProps({ article: { id: 'a1', heroImage: '/b.png' } })
		await flushPromises()
		expect(wrapper.find('img').exists()).toBe(true)
	})

	it('shows no hero without an image', () => {
		expect(mountSection({ props: { article: { id: 'a1' } } }).find('.article-preview__hero').exists()).toBe(false)
	})
})

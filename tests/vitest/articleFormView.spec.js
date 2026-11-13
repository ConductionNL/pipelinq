// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The new-article route: closing the form returns to the article list, and a
 * saved article opens its own page by id or uuid, else the list.
 *
 * @spec openspec/specs/marketing-articles/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/modals/ArticleEditModal.vue', () => ({
	default: { name: 'ArticleEditModal', emits: ['close', 'saved'], render: () => h('div') },
}))

const { default: ArticleFormView } = await import('../../src/views/marketing/ArticleFormView.vue')

/**
 * @return {{wrapper: object, push: Function}} The view and its router push.
 */
function mountView() {
	const push = vi.fn()
	const wrapper = mount(ArticleFormView, { global: { mocks: { $router: { push } } } })
	return { wrapper, push }
}

describe('the new-article route', () => {
	it('returns to the article list when the form closes', async () => {
		const { wrapper, push } = mountView()

		await wrapper.findComponent({ name: 'ArticleEditModal' }).vm.$emit('close')

		expect(push).toHaveBeenCalledWith({ name: 'Articles' })
	})

	it.each([
		[{ id: 'a1', uuid: 'u1' }, { name: 'ArticleDetail', params: { id: 'a1' } }],
		[{ uuid: 'u1' }, { name: 'ArticleDetail', params: { id: 'u1' } }],
		[{}, { name: 'Articles' }],
		[null, { name: 'Articles' }],
	])('after saving %j goes to %j', async (article, route) => {
		const { wrapper, push } = mountView()

		await wrapper.findComponent({ name: 'ArticleEditModal' }).vm.$emit('saved', article)

		expect(push).toHaveBeenCalledTimes(1)
		expect(push).toHaveBeenCalledWith(route)
	})
})

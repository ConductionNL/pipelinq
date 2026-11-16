// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "New post" route wrapper: dismissing the composer returns to the
 * posts list, saving goes to the saved post's detail page by id or uuid,
 * and a save that reports no id falls back to the list.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('../../src/modals/SocialPostComposeModal.vue', () => ({
	default: {
		name: 'SocialPostComposeModal',
		emits: ['close', 'saved'],
		render: () => h('div'),
	},
}))

const { default: SocialPostFormView } =
	await import('../../src/views/social/SocialPostFormView.vue')

function mountView() {
	const push = vi.fn()
	const wrapper = mount(SocialPostFormView, {
		global: { mocks: { $router: { push } } },
	})
	return {
		push,
		composer: wrapper.findComponent({ name: 'SocialPostComposeModal' }),
	}
}

describe('the new social post route', () => {
	it('goes back to the posts list when the composer closes', async () => {
		const { push, composer } = mountView()
		await composer.vm.$emit('close')
		expect(push).toHaveBeenCalledWith({ name: 'SocialPosts' })
	})

	it('opens the saved post by id, falling back to its uuid', async () => {
		const { push, composer } = mountView()
		await composer.vm.$emit('saved', { id: 7, uuid: 'u-7' })
		await composer.vm.$emit('saved', { uuid: 'u-8' })
		expect(push.mock.calls).toEqual([
			[{ name: 'SocialPostDetail', params: { id: 7 } }],
			[{ name: 'SocialPostDetail', params: { id: 'u-8' } }],
		])
	})

	it('returns to the list when the save names no post', async () => {
		const { push, composer } = mountView()
		await composer.vm.$emit('saved', null)
		await composer.vm.$emit('saved', {})
		expect(push.mock.calls).toEqual([
			[{ name: 'SocialPosts' }],
			[{ name: 'SocialPosts' }],
		])
	})
})

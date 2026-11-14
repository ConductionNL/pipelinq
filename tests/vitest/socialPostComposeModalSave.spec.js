// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The post composer: it offers the active accounts, counts each chosen
 * network's text against its limit with a per-network variant that falls
 * back to the body, lists what blocks a submit, saves a draft or submits it
 * for approval (create for a new post, update for an existing one), and
 * names a failed load or save.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-a-marketer-composes-one-post-for-several-networks
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({
	createPost: vi.fn(),
	fetchAccounts: vi.fn(),
	movePost: vi.fn(),
	updatePost: vi.fn(),
}))
vi.mock('../../src/services/socialApi.js', () => api)
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				[this.$slots.icon?.(), this.$slots.default?.()],
			)
		},
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		render: () => h('i', { class: 'loading' }),
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options'],
		emits: ['update:modelValue'],
		render: () => h('div'),
	},
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['name'],
		emits: ['closing'],
		render() {
			return h('div', [
				this.$slots.default?.(),
				h('footer', this.$slots.actions?.()),
			])
		},
	},
}))

const t = (app, text) => text
globalThis.t = t
const global = { mocks: { t } }

const { default: SocialPostComposeModal } =
	await import('../../src/modals/SocialPostComposeModal.vue')

const accounts = [
	{ id: 'a-x', network: 'x', handle: '@gem' },
	{
		uuid: 'a-m',
		network: 'mastodon',
		displayName: 'Gemeente',
		handle: '@gem@m.nl',
	},
	{ id: 'a-old', network: 'linkedin', handle: 'old', active: false },
]

const byId = (wrapper, id) => wrapper.find(`[data-testid="social-compose-${id}"]`)

async function compose(props = {}) {
	const wrapper = mount(SocialPostComposeModal, { props, global })
	await flushPromises()
	return wrapper
}

describe('the post composer', () => {
	beforeEach(() => {
		Object.values(api).forEach((fn) => fn.mockReset())
		api.fetchAccounts.mockResolvedValue({ data: accounts })
	})

	it('offers only the active accounts, labelled with their network', async () => {
		const wrapper = await compose()
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe(
			'Compose a post',
		)
		expect(wrapper.findComponent({ name: 'NcSelect' }).props('options')).toEqual(
			[
				{ value: 'a-x', label: '@gem (x)' },
				{ value: 'a-m', label: 'Gemeente (mastodon)' },
			],
		)
	})

	it('lists what blocks a submit until there is a body and an account', async () => {
		const wrapper = await compose()
		expect(
			byId(wrapper, 'problems')
				.findAll('p')
				.map((p) => p.text()),
		).toEqual([
			'A post needs something to say.',
			'Pick at least one account for this post to go to.',
		])
		expect(byId(wrapper, 'submit').attributes('disabled')).toBeDefined()

		await byId(wrapper, 'body').setValue('Hallo')
		await wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', ['a-x'])
		expect(byId(wrapper, 'problems').exists()).toBe(false)
		expect(byId(wrapper, 'submit').attributes('disabled')).toBeUndefined()
	})

	it('counts each network against its limit and takes a variant over the body', async () => {
		const wrapper = await compose()
		await byId(wrapper, 'body').setValue('a'.repeat(290))
		await wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', ['a-x', 'a-m'])

		expect(byId(wrapper, 'count-x').text()).toBe('290 / 280')
		expect(byId(wrapper, 'count-x').classes()).toContain(
			'social-compose__count--over',
		)
		expect(byId(wrapper, 'count-mastodon').text()).toBe('290 / 500')
		expect(byId(wrapper, 'problems').text()).toContain('accepts 280')

		await byId(wrapper, 'variant-x').setValue('Short for X')
		expect(byId(wrapper, 'count-x').text()).toBe('11 / 280')
		expect(byId(wrapper, 'problems').exists()).toBe(false)

		await byId(wrapper, 'variant-x').setValue('   ')
		expect(byId(wrapper, 'variant-x').element.value).toBe('')
		expect(byId(wrapper, 'count-x').text()).toBe('290 / 280')
	})

	it('saves a new draft without submitting it', async () => {
		api.createPost.mockResolvedValue({ id: 'p-1', status: 'draft' })
		const wrapper = await compose()
		await byId(wrapper, 'title').setValue('Launch')
		await byId(wrapper, 'body').setValue('Hallo')
		await byId(wrapper, 'save').trigger('click')
		await flushPromises()
		expect(api.createPost).toHaveBeenCalledWith({
			title: 'Launch',
			body: 'Hallo',
			link: '',
			scheduledFor: '',
			variants: {},
			media: [],
			accountIds: [],
		})
		expect(api.movePost).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'p-1', status: 'draft' }]])
	})

	it('updates an existing post and submits it for approval', async () => {
		api.updatePost.mockResolvedValue({ uuid: 'p-2' })
		api.movePost.mockResolvedValue({ uuid: 'p-2', status: 'pending' })
		const post = {
			uuid: 'p-2',
			title: 'T',
			body: 'Hallo',
			accountIds: ['a-m'],
			variants: { mastodon: { body: 'Toot' } },
			media: ['m1'],
		}
		const wrapper = await compose({ post })
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe(
			'Edit post',
		)
		expect(byId(wrapper, 'variant-mastodon').element.value).toBe('Toot')

		await byId(wrapper, 'submit').trigger('click')
		await flushPromises()
		expect(api.updatePost).toHaveBeenCalledWith(
			'p-2',
			expect.objectContaining({
				body: 'Hallo',
				accountIds: ['a-m'],
				media: ['m1'],
			}),
		)
		expect(api.movePost).toHaveBeenCalledWith('p-2', 'submit')
		expect(wrapper.emitted('saved')).toEqual([
			[{ uuid: 'p-2', status: 'pending' }],
		])
	})

	it('names a failed save and a failed account load', async () => {
		api.createPost.mockRejectedValueOnce({
			response: { data: { error: 'Title taken' } },
		})
		const wrapper = await compose()
		await byId(wrapper, 'save').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note-error').text()).toBe('Title taken')

		api.createPost.mockRejectedValueOnce(new Error('x'))
		await byId(wrapper, 'save').trigger('click')
		await flushPromises()
		expect(wrapper.find('.note-error').text()).toBe(
			'The post could not be saved.',
		)

		api.fetchAccounts.mockRejectedValueOnce(new Error('down'))
		const failed = await compose()
		expect(failed.find('.note-error').text()).toBe(
			'The accounts could not be loaded.',
		)
	})

	it('closes from cancel', async () => {
		const wrapper = await compose()
		await wrapper.findAll('footer button')[0].trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

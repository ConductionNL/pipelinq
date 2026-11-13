// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The article dialog: it starts from the article (or the defaults), saves a
 * new one through create and an existing one through update by id, uuid or
 * @self id, keeps save off without a title or while saving, names a failed
 * save, and fills the hero image from the file picker — quietly when the
 * picker is dismissed, with a message when it cannot open.
 *
 * @spec openspec/specs/marketing-articles/spec.md#requirement-a-marketer-writes-and-reads-an-article-in-the-interface
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const api = vi.hoisted(() => ({ createArticle: vi.fn(), updateArticle: vi.fn() }))
const picker = vi.hoisted(() => ({ pickNodes: vi.fn() }))
vi.mock('../../src/services/articlesApi.js', () => api)
vi.mock('@nextcloud/dialogs', () => {
	class FilePickerClosed extends Error {}
	const builder = {
		setMultiSelect: () => builder,
		setMimeTypeFilter: () => builder,
		allowDirectories: () => builder,
		addButton: () => builder,
		build: () => picker,
	}
	return { FilePickerClosed, getFilePickerBuilder: () => builder }
})
vi.mock('../../src/components/LabelSelect.vue', () => ({
	default: {
		name: 'LabelSelect',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render: () => h('div'),
	},
}))
vi.mock('vue-material-design-icons/FolderImage.vue', () => ({
	default: { render: () => h('i') },
}))
function field (name) {
  return {
	name,
	props: ['modelValue', 'label', 'id', 'options'],
	emits: ['update:modelValue'],
	render: () => h('div'),
}
}
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
		render() {
			return h('div', { class: 'note' }, this.$slots.default?.())
		},
	},
	NcSelect: field('NcSelect'),
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
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
	CnMarkdownEditor: field('CnMarkdownEditor'),
}))

const t = (app, text) => text
const global = { mocks: { t } }

const { default: ArticleEditModal } =
	await import('../../src/modals/ArticleEditModal.vue')
const { FilePickerClosed } = await import('@nextcloud/dialogs')

function input (wrapper, id) {
  return wrapper
		.findAllComponents({ name: 'NcTextField' })
		.find((f) => f.props('id') === id)
}
const saveButton = (wrapper) => wrapper.find('[data-testid="article-edit-save"]')

describe('the article dialog', () => {
	beforeEach(() => {
		api.createArticle.mockReset()
		api.updateArticle.mockReset()
		picker.pickNodes.mockReset()
	})

	it('creates a new article from what was typed', async () => {
		api.createArticle.mockResolvedValue({ id: 'a-1' })
		const wrapper = mount(ArticleEditModal, { global })
		expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe(
			'New article',
		)
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

		await input(wrapper, 'article-edit-title').vm.$emit(
			'update:modelValue',
			'Nieuwe dienst',
		)
		await wrapper
			.findComponent({ name: 'NcTextArea' })
			.vm.$emit('update:modelValue', 'Kort')
		await wrapper
			.findComponent({ name: 'CnMarkdownEditor' })
			.vm.$emit('update:modelValue', '# Body')
		await wrapper
			.findComponent({ name: 'NcSelect' })
			.vm.$emit('update:modelValue', 'en')
		await wrapper
			.findComponent({ name: 'LabelSelect' })
			.vm.$emit('update:modelValue', ['news'])
		expect(saveButton(wrapper).text()).toBe('Create article')
		await saveButton(wrapper).trigger('click')
		await flushPromises()

		expect(api.createArticle).toHaveBeenCalledWith({
			title: 'Nieuwe dienst',
			slug: '',
			summary: 'Kort',
			body: '# Body',
			heroImage: '',
			language: 'en',
			portalPageRef: '',
			tags: ['news'],
			links: [],
		})
		expect(wrapper.emitted('saved')).toEqual([[{ id: 'a-1' }]])
	})

	it('falls back to Dutch when the language is cleared', async () => {
		const wrapper = mount(ArticleEditModal, {
			props: { article: { title: 'A', language: 'en' } },
			global,
		})
		const select = wrapper.findComponent({ name: 'NcSelect' })
		expect(select.props('modelValue')).toBe('en')
		await select.vm.$emit('update:modelValue', null)
		expect(select.props('modelValue')).toBe('nl')
	})

	it('updates an existing article by id, uuid or @self id', async () => {
		api.updateArticle.mockResolvedValue({ ok: true })
		const articles = [
			[
				{
					id: 'i-1',
					uuid: 'u-1',
					title: 'A',
					tags: ['x'],
					links: [{ url: 'https://a' }],
				},
				'i-1',
			],
			[{ uuid: 'u-2', title: 'B' }, 'u-2'],
			[{ '@self': { id: 's-3' }, title: 'C' }, 's-3'],
		]
		for (const [article, id] of articles) {
			const wrapper = mount(ArticleEditModal, { props: { article }, global })
			expect(wrapper.findComponent({ name: 'CnDialog' }).props('name')).toBe(
				'Edit article',
			)
			expect(saveButton(wrapper).text()).toBe('Save changes')
			await saveButton(wrapper).trigger('click')
			await flushPromises()
			expect(api.updateArticle).toHaveBeenLastCalledWith(
				id,
				expect.objectContaining({ title: article.title }),
			)
		}
		expect(api.updateArticle.mock.calls[0][1]).toMatchObject({
			tags: ['x'],
			links: [{ url: 'https://a' }],
			language: 'nl',
		})
		expect(api.createArticle).not.toHaveBeenCalled()
	})

	it('keeps save off while saving and names a failed save', async () => {
		let reject
		api.createArticle.mockReturnValue(
			new Promise((_, r) => {
				reject = r
			}),
		)
		const wrapper = mount(ArticleEditModal, { props: {}, global })
		await input(wrapper, 'article-edit-title').vm.$emit('update:modelValue', 'T')
		await saveButton(wrapper).trigger('click')
		expect(saveButton(wrapper).attributes('disabled')).toBeDefined()
		expect(wrapper.find('.loading').exists()).toBe(true)

		reject({ response: { data: { error: 'Slug taken' } } })
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('Slug taken')
		expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

		api.createArticle.mockRejectedValueOnce(new Error('x'))
		await saveButton(wrapper).trigger('click')
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe('This article could not be saved.')
	})

	it('fills the hero image from the picked file', async () => {
		picker.pickNodes.mockResolvedValueOnce([{ path: '/Photos/hero.jpg' }])
		const wrapper = mount(ArticleEditModal, { global })
		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Browse…')
			.trigger('click')
		await flushPromises()
		expect(input(wrapper, 'article-edit-hero').props('modelValue')).toBe(
			'/Photos/hero.jpg',
		)

		picker.pickNodes.mockResolvedValueOnce({ path: '/Photos/other.png' })
		await wrapper
			.findAll('button')
			.find((b) => b.text() === 'Browse…')
			.trigger('click')
		await flushPromises()
		expect(input(wrapper, 'article-edit-hero').props('modelValue')).toBe(
			'/Photos/other.png',
		)
	})

	it('stays quiet when the picker is dismissed and says when it cannot open', async () => {
		const consoleError = vi.spyOn(console, 'error').mockImplementation(() => {})
		const wrapper = mount(ArticleEditModal, { global })
		const browse = () =>
			wrapper
				.findAll('button')
				.find((b) => b.text() === 'Browse…')
				.trigger('click')

		picker.pickNodes.mockRejectedValueOnce(new FilePickerClosed())
		await browse()
		await flushPromises()
		expect(wrapper.find('.note').exists()).toBe(false)

		picker.pickNodes.mockRejectedValueOnce(new Error('no files app'))
		await browse()
		await flushPromises()
		expect(wrapper.find('.note').text()).toBe(
			'The file picker could not be opened.',
		)
		expect(consoleError).toHaveBeenCalledTimes(1)
		consoleError.mockRestore()
	})

	it('closes from cancel', async () => {
		const wrapper = mount(ArticleEditModal, { global })
		await wrapper.findAll('footer button')[0].trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

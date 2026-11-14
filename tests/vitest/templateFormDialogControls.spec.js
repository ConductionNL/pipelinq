// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The mail template dialog driven through its rendered controls: the sender
 * and subject fields, the blocks / HTML switch and its confirmation, the
 * preview with its width buttons and the address placed in the HTML, the SMS
 * channel, the article picker, load and save refusals, and Cancel.
 *
 * Mounted with the HTTP layer replaced and small stand-ins for the Nextcloud
 * controls, as in templateFormReplyTo.spec.js.
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-html-templates-keep-working-req-mbe-004
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), patch: vi.fn() }))
const articles = vi.hoisted(() => ({ list: [] }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('../../src/services/articlesApi.js', () => ({ fetchArticles: () => Promise.resolve(articles.list) }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		emits: ['closing'],
		render() {
			return h('div', { class: 'nc-dialog' }, [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	CnMarkdownEditor: { render: () => null },
}))
vi.mock('../../src/components/templates/MailBlockEditor.vue', () => ({
	default: {
		name: 'MailBlockEditor',
		props: ['modelValue'],
		emits: ['update:modelValue'],
		render() {
			return h('div', { 'data-stub': 'block-editor', 'data-count': String((this.modelValue || []).length) })
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'pressed'],
		emits: ['click'],
		render() {
			return h('button', { 'data-testid': this.$attrs['data-testid'], disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
		},
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'inputLabel'],
		emits: ['update:modelValue'],
		methods: {
			pick(value) {
				this.$emit('update:modelValue', value)
			},
		},
		render: () => h('div'),
	},
	NcTextArea: textInput('textarea'),
	NcTextField: textInput('input'),
}))

/**
 * A text field stand-in: one native element, found by its label, carrying v-model.
 *
 * @param {string} tag The native element to render.
 * @return {object} The stub component.
 */
function textInput(tag) {
	return {
		props: ['modelValue', 'label', 'helperText'],
		emits: ['update:modelValue'],
		render() {
			return h(tag, {
				'data-label': this.label,
				'data-helper': this.helperText,
				value: this.modelValue,
				onInput: (event) => this.$emit('update:modelValue', event.target.value),
			})
		},
	}
}

const { default: TemplateFormDialog } = await import('../../src/dialogs/TemplateFormDialog.vue')

/**
 * Mount the dialog open.
 *
 * @param {object|null} item The row being edited, or null to create.
 * @param {object} [props] Extra props.
 * @return {Promise<object>} The wrapper.
 */
async function mountForm(item = null, props = {}) {
	const wrapper = mount(TemplateFormDialog, { props: { show: true, item, ...props }, global: { mocks: { t: (app, text) => text } } })
	await flushPromises()
	return wrapper
}

const field = (w, label) => w.find(`[data-label="${label}"]`)
const button = (w, text) => w.findAll('button').find((b) => b.text() === text)
const byTestId = (w, id) => w.find(`[data-testid="${id}"]`)
const select = (w, label) => w.findAllComponents({ name: 'NcSelect' }).find((s) => s.props('inputLabel') === label)

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.post.mockReset()
	axiosMock.patch.mockReset()
	articles.list = []
})

afterEach(() => {
	vi.useRealTimers()
})

describe('TemplateFormDialog controls', () => {
	it('writes the sender, subject, plain-text and address fields of an HTML template and saves them', async () => {
		axiosMock.get.mockResolvedValue({ data: { name: 'Renewal', channel: 'email', bodyHtml: '<p>Hi {{unsubscribe_link}}</p>' } })
		axiosMock.patch.mockResolvedValue({ data: {} })
		const refresh = vi.fn()
		const w = await mountForm({ id: 't-1' }, { refresh })

		await field(w, 'Sender name').setValue('Gemeente')
		await field(w, 'Sender email').setValue('info@example.nl')
		await field(w, 'Subject').setValue('Your renewal')
		await field(w, 'Plain-text body').setValue('Hi')
		await field(w, 'Physical address').setValue('Main 1')
		await field(w, 'HTML body').setValue('<p>Hello {{unsubscribe_link}}</p>')
		await button(w, 'Save changes').trigger('click')
		await flushPromises()

		expect(axiosMock.patch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/templates/t-1', expect.objectContaining({
			senderName: 'Gemeente',
			senderEmail: 'info@example.nl',
			subject: 'Your renewal',
			bodyText: 'Hi',
			footerOverride: 'Main 1',
			bodyHtml: '<p>Hello {{unsubscribe_link}}</p>',
			editorMode: 'html',
			blocks: [],
		}))
		expect(refresh).toHaveBeenCalledTimes(1)
	})

	it('previews the HTML with the address appended, and hides the editor while previewing', async () => {
		axiosMock.get.mockResolvedValue({ data: { name: 'N', bodyHtml: '<html><body><p>Hi</p></body></html>', footerOverride: 'Main 1\nTown' } })
		const w = await mountForm({ id: 't-1' })

		await button(w, 'Preview').trigger('click')
		const frame = w.find('iframe')
		expect(frame.attributes('srcdoc')).toContain('<p>Hi</p><p>Main 1<br>\nTown</p></body>')
		expect(field(w, 'HTML body').exists()).toBe(false)

		await button(w, 'Edit HTML').trigger('click')
		expect(w.find('iframe').exists()).toBe(false)
	})

	it('builds a new template from blocks, previews it rendered at desktop or phone width', async () => {
		vi.useFakeTimers()
		axiosMock.post.mockResolvedValue({ data: { bodyHtml: '<p>Rendered</p>' } })
		const w = await mountForm()
		expect(byTestId(w, 'template-block-editor').exists()).toBe(true)

		await button(w, 'Preview').trigger('click')
		await vi.advanceTimersByTimeAsync(600)
		await flushPromises()
		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/templates/render', expect.objectContaining({ articleIds: [], footerOverride: '' }))
		expect(w.find('iframe').attributes('srcdoc')).toContain('<p>Rendered</p>')

		await button(w, 'Phone').trigger('click')
		expect(w.find('iframe').attributes('style')).toContain('max-width: 407px')
		await button(w, 'Desktop').trigger('click')
		expect(w.find('iframe').attributes('style')).toContain('max-width: 632px')
		expect(button(w, 'Edit blocks').exists()).toBe(true)
	})

	it('switches blocks to HTML only after confirming, keeping the rendered message', async () => {
		axiosMock.post.mockResolvedValue({ data: { renderedHtml: '<p>From blocks</p>', renderedText: 'From blocks' } })
		const w = await mountForm()

		await byTestId(w, 'template-mode-html').trigger('click')
		await button(w, 'Keep blocks').trigger('click')
		expect(byTestId(w, 'template-mode-html-confirm').exists()).toBe(false)

		await byTestId(w, 'template-mode-html').trigger('click')
		await byTestId(w, 'template-mode-html-confirm').trigger('click')
		await flushPromises()

		expect(field(w, 'HTML body').element.value).toBe('<p>From blocks</p>')
		expect(field(w, 'Plain-text body').element.value).toBe('From blocks')
		expect(byTestId(w, 'template-mode-blocks').attributes('disabled')).toBeDefined()
		expect(w.text()).toContain('Blocks are offered for an empty message body.')
	})

	it('reports a failed switch to HTML and changes nothing', async () => {
		axiosMock.post.mockRejectedValue(new Error('500'))
		const w = await mountForm()

		await byTestId(w, 'template-mode-html').trigger('click')
		await byTestId(w, 'template-mode-html-confirm').trigger('click')
		await flushPromises()

		expect(w.find('.note-error').text()).toBe('The blocks could not be turned into HTML. Nothing was changed.')
		expect(byTestId(w, 'template-block-editor').exists()).toBe(true)
	})

	it('goes back to blocks from an empty HTML body', async () => {
		axiosMock.get.mockResolvedValue({ data: { name: 'N', bodyHtml: '' } })
		const w = await mountForm({ id: 't-1' })
		expect(byTestId(w, 'template-block-editor').exists()).toBe(false)

		await byTestId(w, 'template-mode-blocks').trigger('click')

		expect(byTestId(w, 'template-block-editor').attributes('data-count')).not.toBe('0')
	})

	it('drops the email-only fields and the blocks for an SMS template', async () => {
		const w = await mountForm()

		select(w, 'Channel').vm.pick({ value: 'sms' })
		await flushPromises()

		expect(field(w, 'Subject').exists()).toBe(false)
		expect(field(w, 'Message body').exists()).toBe(true)
		expect(byTestId(w, 'template-mode-blocks').exists()).toBe(false)
		select(w, 'Channel').vm.pick(null)
		await flushPromises()
		expect(field(w, 'Subject').exists()).toBe(true)
	})

	it('embeds picked published articles and warns while the body has no articles marker', async () => {
		articles.list = [{ id: 'a1', title: 'News', status: 'published' }]
		axiosMock.get.mockResolvedValue({ data: { name: 'N', bodyHtml: '<p>x</p>' } })
		const w = await mountForm({ id: 't-1' })

		select(w, 'Articles').vm.pick([{ id: 'a1', title: 'News' }])
		await flushPromises()

		expect(w.vm.model.articleIds).toEqual(['a1'])
		expect(w.find('.note-warning').text()).toContain('The body has no {{articles}} marker')
	})

	it('shows a load refusal, puts a save refusal on its field, and closes from Cancel and the dialog', async () => {
		axiosMock.get.mockRejectedValueOnce({ response: { data: { error: 'Not yours' } } })
		const close = vi.fn()
		const broken = await mountForm({ id: 't-9' }, { close })
		expect(broken.find('.note-error').text()).toBe('Not yours')

		axiosMock.post.mockRejectedValue({ response: { data: { error: 'The subject is required' } } })
		const w = await mountForm(null, { close })
		expect(w.text()).toContain('Give the template a name.')
		await field(w, 'Template name').setValue('Welcome')
		await button(w, 'Create template').trigger('click')
		await flushPromises()
		expect(field(w, 'Subject').attributes('data-helper')).toBe('The subject is required')
		await field(w, 'Subject').setValue('Hello')
		expect(field(w, 'Subject').attributes('data-helper')).toBe('')

		await button(w, 'Cancel').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(close).toHaveBeenCalledTimes(2)
	})
})

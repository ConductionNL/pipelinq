// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template dialog's own logic: the preview places the postal address
 * where it will be on send, switching between Blocks and HTML, the debounced
 * blocks preview, loading a stored template, and how a refused save is shown.
 *
 * Only HTTP, the dialogs toast and the component libraries are replaced.
 *
 * @spec openspec/specs/marketing-compliance/spec.md#scenario-the-physical-address-renders-where-the-template-marks-it
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({
	get: vi.fn(),
	post: vi.fn(),
	patch: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn() }))
vi.mock('../../src/services/articlesApi.js', () => ({
	fetchArticles: () => Promise.resolve([]),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		render() {
			return h('div', { class: 'nc-dialog' }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	},
	CnMarkdownEditor: { render: () => null },
}))
// The block editor is tested on its own (mailBlockEditor.spec.js); here it
// only has to show that it is mounted and hand back what it is given.
vi.mock('../../src/components/templates/MailBlockEditor.vue', () => ({
	default: {
		name: 'MailBlockEditor',
		props: ['modelValue'],
		render() {
			return h('div', {
				'data-stub': 'block-editor',
				'data-count': String((this.modelValue || []).length),
			})
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'pressed'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					class: 'nc-button--' + this.variant,
					'data-testid': this.$attrs['data-testid'],
					disabled: this.disabled,
					onClick: () => this.$emit('click'),
				},
				this.$slots.default?.(),
			)
		},
	},
	NcDialog: {
		name: 'NcDialog',
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcNoteCard: {
		name: 'NcNoteCard',
		render() {
			return h('div', this.$slots.default?.())
		},
	},
	NcSelect: { name: 'NcSelect', render: () => h('div') },
	NcTextArea: textInput('textarea'),
	NcTextField: textInput('input'),
}))

/**
 * A text field stub: one native element, found by its label, that carries
 * `v-model` the way the Nextcloud field does.
 *
 * @param {string} tag The native element to render.
 * @return {object} The stub component.
 */
function textInput(tag) {
	return {
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		render() {
			return h(tag, {
				'data-label': this.label,
				value: this.modelValue,
				onInput: (event) =>
					this.$emit('update:modelValue', event.target.value),
			})
		},
	}
}

const { default: TemplateFormDialog } =
	await import('../../src/dialogs/TemplateFormDialog.vue')

/**
 * Mount the dialog open, as the Templates page's form-dialog slot does.
 *
 * @param {object|null} item The row being edited, or null to create.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountForm(item = null) {
	const wrapper = mount(TemplateFormDialog, {
		props: { show: true, item },
		global: {
			mocks: {
				t: (app, text) => text,
			},
		},
	})
	await flushPromises()
	return wrapper
}


/**
 * The body part of the preview document.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {string} What the preview's body holds.
 */
function previewBody(wrapper) {
	const doc = wrapper.vm.previewDocument
	return doc.slice(doc.indexOf('<body>') + 6, doc.lastIndexOf('</body></html>'))
}

beforeEach(() => {
	axiosMock.get.mockReset()
	axiosMock.post.mockReset()
	axiosMock.patch.mockReset()
	axiosMock.post.mockResolvedValue({ data: {} })
	axiosMock.patch.mockResolvedValue({ data: {} })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('TemplateFormDialog preview of the postal address', () => {
	/**
	 * A new template switched to HTML with this body and address.
	 *
	 * @param {string} bodyHtml The body.
	 * @param {string} address The postal address.
	 * @return {Promise<object>} The wrapper.
	 */
	async function htmlForm(bodyHtml, address) {
		const wrapper = await mountForm()
		wrapper.vm.model.editorMode = 'html'
		wrapper.vm.model.blocks = []
		wrapper.vm.model.bodyHtml = bodyHtml
		wrapper.vm.model.footerOverride = address
		return wrapper
	}

	it('puts the escaped address, one line per line, where every address token is', async () => {
		const wrapper = await htmlForm(
			'<p>Hi</p>{{physical_address}}<hr>{{company_address}}',
			'Straat 1 & <b>2</b>\n1234 AB Stad',
		)

		const rendered = 'Straat 1 &amp; &lt;b&gt;2&lt;/b&gt;<br>\n1234 AB Stad'
		expect(previewBody(wrapper)).toBe(`<p>Hi</p>${rendered}<hr>${rendered}`)
	})

	it('adds the address before the closing body tag when no token marks it', async () => {
		const wrapper = await htmlForm('<html><body><p>Hi</p></BODY></html>', 'Straat 1')

		expect(previewBody(wrapper)).toBe('<html><body><p>Hi</p><p>Straat 1</p></BODY></html>')
	})

	it('adds the address at the end of a fragment', async () => {
		const wrapper = await htmlForm('<p>Hi</p>', '  Straat 1  ')

		expect(previewBody(wrapper)).toBe('<p>Hi</p><p>Straat 1</p>')
	})

	it('leaves the body alone without an address', async () => {
		const wrapper = await htmlForm('<p>Hi</p>{{physical_address}}', '   ')

		expect(previewBody(wrapper)).toBe('<p>Hi</p>{{physical_address}}')
	})

	it('sends the preview with a policy that only allows inline styles and images', async () => {
		const wrapper = await htmlForm('<p>Hi</p>', '')

		expect(wrapper.vm.previewDocument).toContain(
			"content=\"default-src 'none'; style-src 'unsafe-inline'; img-src data: https:; font-src data: https:\"",
		)
	})
})

describe('TemplateFormDialog switching editors', () => {
	it('turns the blocks into HTML once the marketer confirms', async () => {
		const wrapper = await mountForm()
		wrapper.vm.askSwitchToHtml()
		expect(wrapper.vm.confirmingHtml).toBe(true)
		axiosMock.post.mockResolvedValue({ data: { renderedHtml: '<p>Hi</p>', renderedText: 'Hi' } })

		await wrapper.vm.switchToHtml()

		const [url, body] = axiosMock.post.mock.calls.at(-1)
		expect(url).toBe('/index.php/apps/pipelinq/api/templates/render')
		expect(body.footerOverride).toBe('')
		expect(body.articleIds).toEqual([])
		expect(wrapper.vm.model.editorMode).toBe('html')
		expect(wrapper.vm.model.blocks).toEqual([])
		expect(wrapper.vm.model.bodyHtml).toBe('<p>Hi</p>')
		expect(wrapper.vm.model.bodyText).toBe('Hi')
		expect(wrapper.vm.confirmingHtml).toBe(false)
	})

	it('changes nothing when the blocks cannot be rendered', async () => {
		const wrapper = await mountForm()
		const blocks = wrapper.vm.model.blocks
		axiosMock.post.mockRejectedValue(new Error('offline'))

		await wrapper.vm.switchToHtml()

		expect(wrapper.vm.model.editorMode).toBe('blocks')
		expect(wrapper.vm.model.blocks).toEqual(blocks)
		expect(wrapper.vm.saveError).toBe('The blocks could not be turned into HTML. Nothing was changed.')
	})

	it('offers blocks again only for an empty HTML body', async () => {
		const wrapper = await mountForm()
		wrapper.vm.model.editorMode = 'html'
		wrapper.vm.model.blocks = []
		wrapper.vm.model.bodyHtml = '<p>Kept</p>'

		wrapper.vm.switchToBlocks()
		expect(wrapper.vm.model.editorMode).toBe('html')

		wrapper.vm.model.bodyHtml = '  '
		wrapper.vm.switchToBlocks()
		expect(wrapper.vm.model.editorMode).toBe('blocks')
		expect(wrapper.vm.model.blocks.length).toBeGreaterThan(0)
	})

	it('drops Blocks mode when the template becomes an SMS', async () => {
		const wrapper = await mountForm()

		wrapper.vm.model.channel = 'sms'
		await flushPromises()

		expect(wrapper.vm.model.editorMode).toBe('html')
		expect(wrapper.vm.model.blocks).toEqual([])
	})
})

describe('TemplateFormDialog blocks preview', () => {
	it('renders the blocks half a second after the last change while previewing', async () => {
		const wrapper = await mountForm()
		vi.useFakeTimers()
		axiosMock.post.mockResolvedValue({ data: { bodyHtml: '<p>Rendered</p>' } })

		wrapper.vm.previewing = true
		await wrapper.vm.$nextTick()
		wrapper.vm.refreshBlocksPreview()
		await vi.advanceTimersByTimeAsync(499)
		expect(axiosMock.post).not.toHaveBeenCalled()

		await vi.advanceTimersByTimeAsync(1)

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		expect(wrapper.vm.blocksPreviewHtml).toBe('<p>Rendered</p>')
		expect(previewBody(wrapper)).toBe('<p>Rendered</p>')
	})

	it('shows an empty preview when rendering fails', async () => {
		const wrapper = await mountForm()
		vi.useFakeTimers()
		wrapper.vm.blocksPreviewHtml = '<p>Old</p>'
		axiosMock.post.mockRejectedValue(new Error('offline'))

		wrapper.vm.previewing = true
		await wrapper.vm.$nextTick()
		await vi.advanceTimersByTimeAsync(500)

		expect(wrapper.vm.blocksPreviewHtml).toBe('')
	})

	it('renders nothing while the preview is closed', async () => {
		const wrapper = await mountForm()
		vi.useFakeTimers()

		wrapper.vm.refreshBlocksPreview()
		await vi.advanceTimersByTimeAsync(1000)

		expect(axiosMock.post).not.toHaveBeenCalled()
	})
})

describe('TemplateFormDialog loading a stored template', () => {
	it('keeps a blocks template in Blocks mode', async () => {
		axiosMock.get.mockResolvedValue({
			data: { name: 'News', channel: 'email', editorMode: 'blocks', blocks: [{ type: 'text', text: 'Hi' }] },
		})

		const wrapper = await mountForm({ id: 't-9' })

		expect(axiosMock.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/templates/t-9')
		expect(wrapper.vm.model.editorMode).toBe('blocks')
		expect(wrapper.vm.model.blocks.length).toBeGreaterThan(0)
		expect(wrapper.vm.model.subject).toBe('')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('opens a template saved before blocks existed as HTML', async () => {
		axiosMock.get.mockResolvedValue({ data: { name: 'Old', bodyHtml: '<p>Old</p>', editorMode: 'blocks', blocks: [] } })

		const wrapper = await mountForm({ id: 't-9' })

		expect(wrapper.vm.model.editorMode).toBe('html')
		expect(wrapper.vm.model.blocks).toEqual([])
		expect(wrapper.vm.model.bodyHtml).toBe('<p>Old</p>')
	})

	it('shows the server\'s reason when the template cannot be loaded', async () => {
		axiosMock.get.mockRejectedValue({ response: { data: { error: 'Template not found' } } })

		const wrapper = await mountForm({ id: 't-9' })

		expect(wrapper.vm.loadError).toBe('Template not found')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('falls back to its own words without a reason', async () => {
		axiosMock.get.mockRejectedValue(new Error('offline'))

		const wrapper = await mountForm({ id: 't-9' })

		expect(wrapper.vm.loadError).toBe('Could not load this template.')
	})
})

describe('TemplateFormDialog a refused save', () => {
	/**
	 * A new HTML template ready to save.
	 *
	 * @return {Promise<object>} The wrapper.
	 */
	async function filledForm() {
		const wrapper = await mountForm()
		wrapper.vm.model.name = 'Newsletter'
		wrapper.vm.model.editorMode = 'html'
		wrapper.vm.model.blocks = []
		wrapper.vm.model.bodyHtml = '<p>Hi</p>'
		return wrapper
	}

	it.each([
		['Missing {{unsubscribe_link}} in the body', 'bodyHtml'],
		['A physical address is required', 'footerOverride'],
		['The subject is too long', 'subject'],
	])('shows "%s" on its field, not as a banner', async (message, field) => {
		const wrapper = await filledForm()
		wrapper.vm.previewing = true
		axiosMock.post.mockRejectedValue({ response: { data: { error: message } } })

		await wrapper.vm.save()

		expect(wrapper.vm.fieldErrors).toEqual({ [field]: message })
		expect(wrapper.vm.saveError).toBe('')
		expect(wrapper.vm.saving).toBe(false)
		// Only a body error closes the preview, so the editor shows it.
		expect(wrapper.vm.previewing).toBe(field !== 'bodyHtml')
	})

	it('shows any other reason as a banner', async () => {
		const wrapper = await filledForm()
		axiosMock.post.mockRejectedValue(new Error('offline'))

		await wrapper.vm.save()

		expect(wrapper.vm.fieldErrors).toEqual({})
		expect(wrapper.vm.saveError).toBe('Could not save this template.')
	})

	it('clears a field error once that field is edited', async () => {
		const wrapper = await filledForm()
		wrapper.vm.fieldErrors = { subject: 'Too long', footerOverride: 'Required' }

		wrapper.vm.model.subject = 'Shorter'
		await flushPromises()

		expect(wrapper.vm.fieldErrors).toEqual({ footerOverride: 'Required' })
	})

	it('does not send a template without a name', async () => {
		const wrapper = await filledForm()
		wrapper.vm.model.name = '  '

		await wrapper.vm.save()

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.vm.saveHint).toBe('Give the template a name.')
	})
})

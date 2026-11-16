// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The template dialog's states: where the physical address lands in the
 * preview, what the save button asks for, how a refused save is routed to the
 * field it concerns, and how loading and the block preview degrade.
 *
 * The dialog is mounted with the HTTP layer replaced, as in
 * templateFormReplyTo.spec.js.
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
vi.mock('../../src/services/portaliqArticles.js', () => ({
	fetchPortaliqArticles: () => Promise.resolve({ available: true, data: [] }),
	portaliqArticleUrl: (id) => '/index.php/apps/portaliq/articles/' + id,
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

const compliantBody =
	'<p>Hello</p><p>{{physical_address}}</p><p>{{unsubscribe_link}}</p>'

/**
 * Mount the dialog open, as the Templates page's form-dialog slot does.
 *
 * @param {object|null} item The row being edited, or null to create.
 * @param {object} props Extra props.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountForm(item = null, props = {}) {
	const wrapper = mount(TemplateFormDialog, {
		props: { show: true, item, ...props },
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
 * Mount an existing HTML template with the given stored fields.
 *
 * @param {object} data The stored template.
 * @param {object} props Extra props.
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountStored(data, props = {}) {
	axiosMock.get.mockResolvedValue({ data })
	return mountForm({ id: 't-1' }, props)
}

/**
 * The body of the preview document.
 *
 * @param {object} wrapper The mounted dialog.
 * @return {string} What sits between the body tags.
 */
function previewBody(wrapper) {
	const doc = wrapper.vm.previewDocument
	return doc.slice(doc.indexOf('<body>') + 6, doc.lastIndexOf('</body></html>'))
}

describe('TemplateFormDialog states', () => {
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

	it('puts the escaped address at every address token in the preview', async () => {
		const wrapper = await mountStored({
			name: 'N',
			channel: 'email',
			bodyHtml: '<p>{{physical_address}}</p><p>{{sender_address}}</p>',
			footerOverride: ' Main St 1 & Co\nUtrecht ',
		})

		expect(previewBody(wrapper)).toBe(
			'<p>Main St 1 &amp; Co<br>\nUtrecht</p><p>Main St 1 &amp; Co<br>\nUtrecht</p>',
		)
		expect(wrapper.vm.previewDocument).toContain("default-src 'none'")
	})

	it('appends the address before the closing body tag, or at the end, when there is no token', async () => {
		const withBody = await mountStored({
			name: 'N',
			channel: 'email',
			bodyHtml: '<html><BODY><p>Hi</p></BODY></html>',
			footerOverride: 'Main St 1',
		})
		expect(previewBody(withBody)).toBe('<html><BODY><p>Hi</p><p>Main St 1</p></BODY></html>')

		const fragment = await mountStored({ name: 'N', channel: 'email', bodyHtml: '<p>Hi</p>', footerOverride: 'Main St 1' })
		expect(previewBody(fragment)).toBe('<p>Hi</p><p>Main St 1</p>')

		const noAddress = await mountStored({ name: 'N', channel: 'email', bodyHtml: '<p>Hi</p>', footerOverride: '  ' })
		expect(previewBody(noAddress)).toBe('<p>Hi</p>')
	})

	it('asks for a name, then a body, before it can save', async () => {
		const wrapper = await mountStored({ name: '', channel: 'email', bodyHtml: '' })

		expect(wrapper.vm.canSave).toBe(false)
		expect(wrapper.vm.saveHint).toBe('Give the template a name.')
		wrapper.vm.model.name = 'Renewal'
		expect(wrapper.vm.saveHint).toBe('Add a message body.')
		wrapper.vm.model.bodyHtml = compliantBody
		expect(wrapper.vm.saveHint).toBe('')
		expect(wrapper.vm.canSave).toBe(true)

		await wrapper.vm.save()
		expect(axiosMock.patch).toHaveBeenCalledTimes(1)
	})

	it('does not save while it cannot', async () => {
		const wrapper = await mountStored({ name: '', channel: 'email', bodyHtml: compliantBody })

		await wrapper.vm.save()

		expect(axiosMock.patch).not.toHaveBeenCalled()
		expect(axiosMock.post).not.toHaveBeenCalled()
	})

	it('routes a refused save to the field it names, and closes the preview for the body', async () => {
		const close = vi.fn()
		const refresh = vi.fn()
		const wrapper = await mountStored({ name: 'N', channel: 'email', bodyHtml: compliantBody }, { close, refresh })
		const refuse = (error) => axiosMock.patch.mockRejectedValueOnce({ response: { data: { error } } })

		wrapper.vm.previewing = true
		refuse('The body needs an Unsubscribe link.')
		await wrapper.vm.save()
		expect(wrapper.vm.fieldErrors).toEqual({ bodyHtml: 'The body needs an Unsubscribe link.' })
		expect(wrapper.vm.previewing).toBe(false)
		expect(wrapper.vm.saveError).toBe('')

		refuse('A physical address is required.')
		await wrapper.vm.save()
		expect(wrapper.vm.fieldErrors).toEqual({ footerOverride: 'A physical address is required.' })

		refuse('Subject is too long.')
		await wrapper.vm.save()
		expect(wrapper.vm.fieldErrors).toEqual({ subject: 'Subject is too long.' })

		refuse('Storage is full.')
		await wrapper.vm.save()
		expect(wrapper.vm.fieldErrors).toEqual({})
		expect(wrapper.vm.saveError).toBe('Storage is full.')

		axiosMock.patch.mockRejectedValueOnce(new Error('network'))
		await wrapper.vm.save()
		expect(wrapper.vm.saveError).toBe('Could not save this template.')
		expect(wrapper.vm.saving).toBe(false)
		expect(close).not.toHaveBeenCalled()
		expect(refresh).not.toHaveBeenCalled()
	})

	it('clears a field error once that field is edited', async () => {
		const wrapper = await mountStored({ name: 'N', channel: 'email', bodyHtml: compliantBody })
		wrapper.vm.fieldErrors = { subject: 'x', footerOverride: 'y', bodyHtml: 'z' }

		wrapper.vm.model.subject = 'New subject'
		await flushPromises()
		expect(wrapper.vm.fieldErrors).toEqual({ footerOverride: 'y', bodyHtml: 'z' })

		wrapper.vm.model.footerOverride = 'Main St 2'
		wrapper.vm.model.bodyHtml = compliantBody + ' '
		await flushPromises()
		expect(wrapper.vm.fieldErrors).toEqual({})
	})

	it('a saved template refreshes the list and closes the dialog', async () => {
		const close = vi.fn()
		const refresh = vi.fn()
		const wrapper = await mountStored({ name: 'N', channel: 'email', bodyHtml: compliantBody }, { close, refresh })

		await wrapper.vm.save()

		expect(refresh).toHaveBeenCalledTimes(1)
		expect(close).toHaveBeenCalledTimes(1)
	})

	it('says why a template could not be loaded', async () => {
		axiosMock.get.mockRejectedValueOnce({ response: { data: { error: 'Template not found.' } } })
		const missing = await mountForm({ id: 't-9' })
		expect(missing.vm.loadError).toBe('Template not found.')
		expect(missing.vm.loading).toBe(false)
		expect(missing.vm.saveHint).toBe('')

		axiosMock.get.mockRejectedValueOnce(new Error('network'))
		const offline = await mountForm({ '@self': { id: 't-8' } })
		expect(axiosMock.get.mock.calls.at(-1)[0]).toBe('/index.php/apps/pipelinq/api/templates/t-8')
		expect(offline.vm.loadError).toBe('Could not load this template.')
	})

	it('opens stored blocks with the footer moved last', async () => {
		const wrapper = await mountStored({
			name: 'N',
			channel: 'email',
			editorMode: 'blocks',
			blocks: [{ type: 'footer', id: 'f' }, { type: 'text', id: 'a' }],
		})

		expect(wrapper.vm.isBlocks).toBe(true)
		expect(wrapper.vm.model.blocks.map((b) => b.id)).toEqual(['a', 'f'])
		expect(wrapper.vm.canSave).toBe(true)
	})

	it('switching to SMS leaves Blocks mode and drops the blocks', async () => {
		const wrapper = await mountForm()
		expect(wrapper.vm.isBlocks).toBe(true)
		expect(wrapper.vm.channelOption.value).toBe('email')

		wrapper.vm.channelOption = { value: 'sms', label: 'SMS' }
		await flushPromises()

		expect(wrapper.vm.model.channel).toBe('sms')
		expect(wrapper.vm.model.editorMode).toBe('html')
		expect(wrapper.vm.model.blocks).toEqual([])
		expect(wrapper.vm.bodyHint).toBe('')

		wrapper.vm.channelOption = null
		expect(wrapper.vm.model.channel).toBe('email')
	})

	it('switches to Blocks only from an empty HTML body', async () => {
		const wrapper = await mountStored({ name: 'N', channel: 'email', bodyHtml: '<p>kept</p>' })

		wrapper.vm.switchToBlocks()
		expect(wrapper.vm.isBlocks).toBe(false)

		wrapper.vm.model.bodyHtml = ' '
		wrapper.vm.switchToBlocks()
		expect(wrapper.vm.isBlocks).toBe(true)
		expect(wrapper.vm.model.blocks.map((b) => b.type)).toEqual(['heading', 'text', 'footer'])
	})

	it('keeps the blocks when they cannot be turned into HTML', async () => {
		axiosMock.post.mockRejectedValueOnce(new Error('500'))
		const wrapper = await mountForm()
		wrapper.vm.askSwitchToHtml()
		expect(wrapper.vm.confirmingHtml).toBe(true)

		await wrapper.vm.switchToHtml()

		expect(wrapper.vm.isBlocks).toBe(true)
		expect(wrapper.vm.confirmingHtml).toBe(true)
		expect(wrapper.vm.saveError).toBe('The blocks could not be turned into HTML. Nothing was changed.')
	})

	it('renders the block preview half a second after the last change, and blanks it on failure', async () => {
		vi.useFakeTimers()
		axiosMock.post.mockResolvedValueOnce({ data: { bodyHtml: '<table>preview</table>' } })
		const wrapper = await mountForm()

		wrapper.vm.previewing = true
		await flushPromises()
		expect(axiosMock.post).not.toHaveBeenCalled()
		await vi.advanceTimersByTimeAsync(500)
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		expect(axiosMock.post.mock.calls[0][0]).toBe('/index.php/apps/pipelinq/api/templates/render')
		expect(axiosMock.post.mock.calls[0][1].footerOverride).toBe('')
		expect(previewBody(wrapper)).toBe('<table>preview</table>')

		axiosMock.post.mockRejectedValueOnce(new Error('500'))
		wrapper.vm.model.footerOverride = 'Main St 1'
		await flushPromises()
		await vi.advanceTimersByTimeAsync(500)
		await flushPromises()
		expect(wrapper.vm.blocksPreviewHtml).toBe('')
	})
})

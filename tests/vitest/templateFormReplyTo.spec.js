// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Reply-to email on a mail template is saved (pipelinq#2075). The form
 * showed the field, bound to `model.replyTo`, but its save payload left the
 * field out, so the address a marketer typed never reached the server.
 *
 * The template dialog is mounted with the HTTP layer replaced, the field is
 * filled the way a marketer fills it, and the request body it sends is read
 * back.
 *
 * @spec openspec/changes/marketing-block-editor/specs/mail-block-editor/spec.md#requirement-html-templates-keep-working-req-mbe-004
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
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
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{
					class: 'nc-button--' + this.variant,
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
	NcNoteCard: { name: 'NcNoteCard', render: () => h('div') },
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
 * @param {object} wrapper The mounted dialog.
 * @param {string} label The field's label.
 * @return {object} The field's native element.
 */
function field(wrapper, label) {
	return wrapper.find(`[data-label="${label}"]`)
}

describe('TemplateFormDialog reply-to', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		axiosMock.patch.mockReset()
		axiosMock.post.mockResolvedValue({ data: {} })
		axiosMock.patch.mockResolvedValue({ data: {} })
	})

	it('sends the reply-to a marketer typed on a new template', async () => {
		const wrapper = await mountForm()

		await field(wrapper, 'Template name').setValue('Renewal reminder')
		await field(wrapper, 'HTML body').setValue(compliantBody)
		await field(wrapper, 'Reply-to email').setValue('reply@example.nl')
		await wrapper.find('button.nc-button--primary').trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, payload] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/api/templates')
		expect(payload.replyTo).toBe('reply@example.nl')
	})

	it('keeps the stored reply-to when an existing template is saved', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				name: 'Renewal reminder',
				channel: 'email',
				bodyHtml: compliantBody,
				replyTo: 'renewals@example.nl',
			},
		})
		const wrapper = await mountForm({ id: 't-1' })

		expect(field(wrapper, 'Reply-to email').element.value).toBe(
			'renewals@example.nl',
		)
		await wrapper.find('button.nc-button--primary').trigger('click')
		await flushPromises()

		expect(axiosMock.patch).toHaveBeenCalledTimes(1)
		const [url, payload] = axiosMock.patch.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/api/templates/t-1')
		expect(payload.replyTo).toBe('renewals@example.nl')
	})
})

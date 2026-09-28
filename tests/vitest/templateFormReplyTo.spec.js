// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Reply-to email on a mail template is saved (pipelinq#2075). The form
 * showed the field, bound to `model.replyTo`, but its save payload left the
 * field out, so the address a marketer typed never reached the server.
 *
 * The form is mounted with the HTTP layer replaced, the field is filled the
 * way a marketer fills it, and the request body the form sends is read back.
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
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcSelect: { name: 'NcSelect', render: () => h('div') },
}))

const { default: TemplateForm } =
	await import('../../src/views/templates/TemplateForm.vue')

const compliantBody =
	'<p>Hello</p><p>{{physical_address}}</p><p>{{unsubscribe_link}}</p>'

/**
 * Mount the form on a route.
 *
 * @param {object} params The route params (an `id` means edit mode).
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountForm(params = {}) {
	const wrapper = mount(TemplateForm, {
		global: {
			mocks: {
				t: (app, text) => text,
				$route: { params },
				$router: { push: vi.fn() },
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('TemplateForm reply-to', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		axiosMock.patch.mockReset()
		axiosMock.post.mockResolvedValue({ data: {} })
		axiosMock.patch.mockResolvedValue({ data: {} })
	})

	it('sends the reply-to a marketer typed on a new template', async () => {
		const wrapper = await mountForm()

		await wrapper.find('#template-form-name').setValue('Renewal reminder')
		await wrapper.find('#template-form-body-html').setValue(compliantBody)
		await wrapper.find('#template-form-reply-to').setValue('reply@example.nl')
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

		expect(wrapper.find('#template-form-reply-to').element.value).toBe(
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

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Send again on a failed WhatsApp or SMS message in the Messages section
 * (messaging-saved-replies-and-resend, task 5.3): the button shows on failed
 * and expired outbound rows only, a sent resend reloads the list, a closed
 * WhatsApp window opens the composer on WhatsApp, and any other refusal shows
 * the reason on the row.
 *
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-sends-a-failed-message-again-req-msr-006
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const storeMock = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	fetchCollection: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => String(text),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/modals/SendMessageModal.vue', () => ({
	default: {
		name: 'SendMessageModal',
		props: [
			'initialChannel',
			'contactId',
			'clientId',
			'preflight',
			'placeholderValues',
			'language',
		],
		render: () => h('div'),
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['description'],
		render() {
			return h('div', { class: 'empty' }, this.description)
		},
	},
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcSelect: { name: 'NcSelect', render: () => h('div') },
}))

globalThis.t = (app, text) => text

const { default: MessagingConversationSection } =
	await import('../../src/views/messaging/MessagingConversationSection.vue')

function row(id, extra) {
	return {
		id,
		contactId: 'contact-1',
		channel: 'sms',
		direction: 'outbound',
		body: 'We are open until five.',
		deliveryStatus: 'failed',
		sentAt: '2026-10-08T10:00:00Z',
		...extra,
	}
}

let rows = []

/**
 * Mount the section on a contact and let its fetches settle.
 *
 * @return {Promise<object>} The mounted wrapper.
 */
async function mountOnContact() {
	const wrapper = mount(MessagingConversationSection, {
		props: { entityId: 'contact-1', entityType: 'contact' },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

function resendButtons(wrapper) {
	return wrapper.findAll('button').filter((b) => b.text() === 'Send again')
}

describe('Send again in the Messages section', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.get.mockResolvedValue({ data: {} })
		axiosMock.post.mockReset()
		storeMock.fetchObject.mockReset()
		storeMock.fetchObject.mockResolvedValue({
			id: 'contact-1',
			name: 'Jan de Vries',
		})
		storeMock.fetchCollection.mockReset()
		storeMock.fetchCollection.mockImplementation((slug) =>
			Promise.resolve(slug === 'channelMessage' ? rows : []),
		)
	})

	it('offers Send again on failed and expired outbound rows only', async () => {
		rows = [
			row('m-failed'),
			row('m-expired', { channel: 'whatsapp', deliveryStatus: 'expired' }),
			row('m-sent', { deliveryStatus: 'sent' }),
			row('m-in', { direction: 'inbound' }),
			row('m-done', { metadata: { resentAs: 'm-new' } }),
		]
		const wrapper = await mountOnContact()

		expect(resendButtons(wrapper)).toHaveLength(2)
	})

	it('posts the resend and reloads the list when it was sent', async () => {
		rows = [row('m-failed')]
		axiosMock.post.mockResolvedValue({
			data: { status: 'sent', messageId: 'm-new' },
		})
		const wrapper = await mountOnContact()
		storeMock.fetchCollection.mockClear()

		await resendButtons(wrapper)[0].trigger('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/messaging/messages/{id}/resend',
		)
		expect(storeMock.fetchCollection.mock.calls.map(([slug]) => slug)).toContain(
			'channelMessage',
		)
	})

	it('opens the composer on WhatsApp when a template is required', async () => {
		rows = [row('m-wa', { channel: 'whatsapp' })]
		axiosMock.post.mockRejectedValue({
			response: { status: 422, data: { status: 'template-required' } },
		})
		const wrapper = await mountOnContact()

		await resendButtons(wrapper)[0].trigger('click')
		await flushPromises()

		const composer = wrapper.findComponent({ name: 'SendMessageModal' })
		expect(composer.exists()).toBe(true)
		expect(composer.props('initialChannel')).toBe('whatsapp')
	})

	it("shows the server's reason on the row after a second failure", async () => {
		rows = [row('m-failed')]
		axiosMock.post.mockRejectedValue({
			response: { status: 422, data: { status: 'consent-missing' } },
		})
		const wrapper = await mountOnContact()

		await resendButtons(wrapper)[0].trigger('click')
		await flushPromises()

		expect(wrapper.find('[role="alert"]').text()).toBe(
			'Not sent: the contact has not given consent for this channel.',
		)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * "Bericht sturen" in the client page's contact strip opens the Messages
 * section's composer for that client, and a sent message makes the client's
 * Activity card read again.
 *
 * @spec openspec/changes/client-activity-card/specs/client-detail/spec.md#requirement-the-contact-strip-sends-a-message
 */

import { emit, subscribe } from '@nextcloud/event-bus'
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


async function mountOnClient() {
	const wrapper = mount(MessagingConversationSection, {
		props: { entityId: 'client-1', entityType: 'client' },
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

describe('Send a message from the contact strip', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.get.mockResolvedValue({ data: {} })
		storeMock.fetchObject.mockReset()
		storeMock.fetchObject.mockResolvedValue({ id: 'client-1', name: 'Anna de Vries' })
		storeMock.fetchCollection.mockReset()
		storeMock.fetchCollection.mockImplementation((slug) =>
			Promise.resolve(slug === 'contact' ? [{ id: 'contact-1', name: 'Anna de Vries' }] : []),
		)
	})

	it('opens the composer for this client, not for another one', async () => {
		const wrapper = await mountOnClient()
		emit('pipelinq:messaging:compose', { entityId: 'client-2' })
		await flushPromises()
		expect(wrapper.findComponent({ name: 'SendMessageModal' }).exists()).toBe(false)
		emit('pipelinq:messaging:compose', { entityId: 'client-1' })
		await flushPromises()
		const modal = wrapper.findComponent({ name: 'SendMessageModal' })
		expect(modal.exists()).toBe(true)
		expect(modal.props('contactId')).toBe('contact-1')
		wrapper.unmount()
	})

	it('tells the Activity card of the client to read again once a message is sent', async () => {
		const seen = vi.fn()
		subscribe('cn:timeline:changed', seen)
		const wrapper = await mountOnClient()
		emit('pipelinq:messaging:compose', { entityId: 'client-1' })
		await flushPromises()
		wrapper.findComponent({ name: 'SendMessageModal' }).vm.$emit('sent')
		await flushPromises()
		expect(seen).toHaveBeenCalledWith(expect.objectContaining({ objectId: 'client-1' }))
		wrapper.unmount()
	})
})

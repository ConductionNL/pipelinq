// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The website chat on its ticket (requests-web-chat-and-bot task 3.2): the
 * transcript with who said what, Take this chat, a reply and End chat; nothing
 * on a ticket that is not a website chat.
 *
 * @spec openspec/changes/requests-web-chat-and-bot/specs/web-chat-and-handover/spec.md#requirement-the-chat-goes-to-a-colleague-through-the-queue-req-wch-004
 */
import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path, params = {}) => path.replace(/\{(\w+)\}/g, (m, key) => params[key]) }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'type'],
		emits: ['click'],
		render() {
			return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcTextArea: {
		name: 'NcTextArea',
		props: ['modelValue', 'label', 'disabled'],
		emits: ['update:modelValue'],
		render() {
			return h('textarea', { value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
}))

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, key) => vars[key])
const { default: WebChatSection, chatConversationOf } = await import('../../src/components/WebChatSection.vue')

function view (extra = {}) {
  return {
	conversation: 'c-1',
	status: 'open',
	assignedTo: '',
	ticket: 't-1',
	messages: [
		{ id: 'm1', author: 'visitor', body: 'Paspoort?', at: '1' },
		{ id: 'm2', author: 'assistant', body: 'Op zaterdag.', at: '2', sources: [{ title: 'Paspoort', url: 'https://www.gemeente.nl/paspoort' }] },
	],
	...extra,
}
}

/**
 * Mount the section on a ticket.
 *
 * @param {object} props The ticket's channel and thread key.
 * @return {object} The wrapper.
 */
function mountOn(props) {
	return mount(WebChatSection, { props, global: { mocks: { t: globalThis.t } } })
}

afterEach(() => {
	vi.clearAllMocks()
})

describe('the website chat section', () => {
	it('takes the conversation from a chat ticket only', () => {
		expect(chatConversationOf('chat', 'conversation:c-1')).toBe('c-1')
		expect(chatConversationOf('email', 'conversation:c-1')).toBe('')
		expect(chatConversationOf('chat', 'mail:<id@x>')).toBe('')
		expect(chatConversationOf('chat', undefined)).toBe('')
	})

	it('renders nothing on a ticket that is not a website chat, and reads nothing', () => {
		const wrapper = mountOn({ channel: 'email', threadKey: 'mail:1' })

		expect(wrapper.find('section').exists()).toBe(false)
		expect(http.get).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('shows the transcript with who said what, and the sources', async () => {
		http.get.mockResolvedValue({ data: view() })
		const wrapper = mountOn({ channel: 'chat', threadKey: 'conversation:c-1' })
		await flushPromises()

		expect(http.get).toHaveBeenCalledWith('/apps/pipelinq/api/web-chat/conversations/c-1')
		expect(wrapper.findAll('.web-chat-section__author').map((n) => n.text())).toEqual(['Visitor', 'Assistant'])
		expect(wrapper.find('.web-chat-section__sources a').attributes('href')).toBe('https://www.gemeente.nl/paspoort')
		expect(wrapper.find('.web-chat-section__state').text()).toBe('Waiting for a colleague.')
		wrapper.unmount()
	})

	it('takes the chat, then sends a reply and clears the box', async () => {
		http.get.mockResolvedValue({ data: view() })
		http.post.mockImplementation(async (url, body) => ({
			data: url.endsWith('/take')
				? view({ status: 'assigned', assignedTo: 'anna' })
				: view({ status: 'assigned', assignedTo: 'anna', messages: [...view().messages, { id: 'm3', author: 'agent', body: body.text, at: '3' }] }),
		}))
		const wrapper = mountOn({ channel: 'chat', threadKey: 'conversation:c-1' })
		await flushPromises()

		await wrapper.find('[data-testid="web-chat-take"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/web-chat/conversations/c-1/take', {})
		expect(wrapper.find('[data-testid="web-chat-take"]').exists()).toBe(false)
		expect(wrapper.find('.web-chat-section__state').text()).toBe('Taken by anna.')

		await wrapper.find('textarea').setValue('Ik kijk het na')
		await wrapper.find('[data-testid="web-chat-send"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenLastCalledWith('/apps/pipelinq/api/web-chat/conversations/c-1/reply', { text: 'Ik kijk het na' })
		expect(wrapper.findAll('.web-chat-section__author').map((n) => n.text())).toEqual(['Visitor', 'Assistant', 'Colleague'])
		expect(wrapper.find('textarea').element.value).toBe('')
		wrapper.unmount()
	})

	it('shows an ended chat without its controls', async () => {
		http.get.mockResolvedValue({ data: view({ status: 'closed' }) })
		const wrapper = mountOn({ channel: 'chat', threadKey: 'conversation:c-1' })
		await flushPromises()

		expect(wrapper.find('.web-chat-section__state').text()).toBe('This chat has ended.')
		expect(wrapper.find('.web-chat-section__actions').exists()).toBe(false)
		wrapper.unmount()
	})
})

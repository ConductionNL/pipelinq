// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The website chat on its ticket (requests-web-chat-and-bot task 3.2, decision
 * on #3943 option 1): the library's `conversation` widget reads the chat from
 * the conversation route named by the ticket's thread key, every three seconds
 * until it has ended, and replies to it; Take this chat and End chat are header
 * actions. Both ticket page structures carry the same widget, and only a chat
 * ticket shows it.
 *
 * @spec openspec/changes/requests-web-chat-and-bot/tasks.md#task-3.2
 */
import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const read = (file) => JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src', file), 'utf8'))

const full = read('manifest.json').pages.find((page) => page.id === 'TicketDetail').config

/**
 * Every object in the tree with this id.
 *
 * @param {*} node The tree.
 * @param {string} id The id.
 * @param {Array<object>} found What was found so far.
 * @return {Array<object>}
 */
function byId(node, id, found = []) {
	if (Array.isArray(node)) {
		node.forEach((item) => byId(item, id, found))
	} else if (node && typeof node === 'object') {
		if (node.id === id) {
			found.push(node)
		}
		Object.values(node).forEach((value) => byId(value, id, found))
	}
	return found
}

const simple = read('menu-layout.simple.json')
const CHAT = { channel: 'chat', inboundThreadKey: 'conversation:abc', assignee: '' }
const PHONE = { channel: 'phone', inboundThreadKey: '', assignee: '' }

describe('the website chat on its ticket', () => {
	it('is the library conversation widget in both structures, not a pipelinq component', () => {
		const widgets = [
			full.widgets.find((widget) => widget.id === 'ticket-web-chat'),
			...byId(simple, 'ticket-web-chat').filter((node) => node.type),
		]
		expect(widgets).toHaveLength(2)
		for (const widget of widgets) {
			expect(widget.type).toBe('conversation')
			expect(widget.content.source.url).toBe('/apps/pipelinq/api/web-chat/conversations/@object.inboundThreadKey')
			expect(widget.content.source.responsePath).toBe('messages')
			expect(widget.content.endpoint).toBe('/apps/pipelinq/api/web-chat/conversations/@object.inboundThreadKey/reply')
			expect(widget.content.messageKey).toBe('text')
			expect(widget.content.pollSeconds).toBe(3)
			expect(widget.content.pollUntil).toEqual({ path: 'status', in: ['closed'] })
			expect(widget.content).toMatchObject({ textField: 'body', timeField: 'at', authorField: 'authorName', sideField: 'side', usValue: 'us' })
		}
		expect(full.bodyWidgets.some((section) => section.component === 'WebChatSection')).toBe(false)
		expect(fs.existsSync(path.resolve(__dirname, '../../src/components/WebChatSection.vue'))).toBe(false)
	})

	it('shows only on a chat ticket, as its own tab on the full page', () => {
		const widget = full.widgets.find((item) => item.id === 'ticket-web-chat')
		expect(evaluateVisibleWhenLocal(widget.visibleWhen, CHAT)).toBe(true)
		expect(evaluateVisibleWhenLocal(widget.visibleWhen, PHONE)).toBe(false)
		const tabs = full.widgets.find((item) => item.id === 'ticket-panels').content.tabs
		const tab = tabs.find((item) => item.widgetId === 'ticket-web-chat')
		expect(tab.label).toBe('Website chat')
		expect(evaluateVisibleWhenLocal(tab.visibleWhen, PHONE)).toBe(false)
	})

	it('takes and ends the chat through header actions on its conversation route', () => {
		const simpleActions = [...byId(simple, 'web-chat-take'), ...byId(simple, 'web-chat-end')]
		expect(simpleActions).toHaveLength(2)
		for (const actions of [full.headerActions, simpleActions]) {
			const take = actions.find((action) => action.id === 'web-chat-take')
			const end = actions.find((action) => action.id === 'web-chat-end')
			expect(take).toMatchObject({ type: 'api-call', method: 'POST', url: '/apps/pipelinq/api/web-chat/conversations/@object.inboundThreadKey/take' })
			expect(end).toMatchObject({ type: 'api-call', method: 'POST', url: '/apps/pipelinq/api/web-chat/conversations/@object.inboundThreadKey/end', confirm: true })
			expect(evaluateVisibleWhenLocal(take.visibleWhen, CHAT)).toBe(true)
			expect(evaluateVisibleWhenLocal(take.visibleWhen, { ...CHAT, assignee: 'uid:agent.bakker' })).toBe(false)
			expect(evaluateVisibleWhenLocal(end.visibleWhen, CHAT)).toBe(true)
			expect(evaluateVisibleWhenLocal(end.visibleWhen, PHONE)).toBe(false)
		}
	})
})

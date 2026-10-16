/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The webchat page (board PqWebchat): the chats the bot handed over, the
 * conversation, who the visitor is and what the bot did, on the existing
 * channelConversation / channelMessage model. Every field the page reads is a
 * property the register declares.
 */
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
function readJson (...parts) {
  return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}
const fragment = readJson('src', 'manifest.d', '86-webchat.json')
const page = fragment.pages[0]
const widgets = Object.fromEntries(page.config.widgets.map((w) => [w.id, w]))
const registerDir = path.join(ROOT, 'lib', 'Settings', 'register.d')
function properties (schema) {
  return Object.assign(
		{},
		...fs
			.readdirSync(registerDir)
			.filter((name) => name.endsWith('.json'))
			.sort()
			.map(
				(name) =>
					readJson('lib', 'Settings', 'register.d', name).components
						?.schemas?.[schema]?.properties || {},
			),
	)
}
const conversation = properties('channelConversation')
const message = properties('channelMessage')
const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations

describe('the webchat page', () => {
	it('is a dashboard at /webchat with a menu entry', () => {
		expect(page).toMatchObject({
			id: 'Webchat',
			route: '/webchat',
			type: 'dashboard',
		})
		expect(fragment.menu.map((entry) => entry.route)).toContain('Webchat')
		const placed = page.config.layout.map((cell) => cell.widgetId).sort()
		expect(placed).toEqual(Object.keys(widgets).sort())
	})

	it('lists open chats and puts the picked one in the workspace the other cards read', () => {
		const waiting = widgets['chat-waiting'].content
		expect(waiting.schema).toBe('channelConversation')
		expect(conversation.channel.enum).toContain(waiting.filter.channel)
		expect(conversation.status.enum).toContain(waiting.filter.status)
		const key = `@workspace.${waiting.select}`
		expect(widgets['chat-thread'].content.filter.conversationId).toBe(key)
		expect(widgets['chat-visitor'].content.source.id).toBe(key)
		expect(widgets['chat-bot'].content.source.id).toBe(key)
	})

	it('reads only declared fields', () => {
		for (const field of [
			'visitorName',
			'subject',
			'origin',
			'handedOverAt',
			'pageUrl',
			'botSummary',
			'handoverReason',
		]) {
			expect(conversation[field], field).toBeTruthy()
		}
		const thread = widgets['chat-thread'].content
		for (const field of [
			thread.textField,
			thread.timeField,
			thread.authorField,
			thread.sideField,
		]) {
			expect(message[field], field).toBeTruthy()
		}
		for (const key of Object.keys(thread.reply.createDefaults)) {
			expect(message[key], key).toBeTruthy()
		}
		expect(message.author.enum).toEqual(['visitor', 'bot', 'agent'])
	})

	it('has every title and label in English and Dutch', () => {
		const words = [page.title, page.config.description]
		for (const widget of page.config.widgets) {
			words.push(widget.title, widget.content.emptyText)
			for (const field of widget.content.fields || []) {
				words.push(field.label)
			}
		}
		for (const word of words.filter(Boolean)) {
			expect(en[word], word).toBeTruthy()
			expect(nl[word], word).toBeTruthy()
		}
	})
})

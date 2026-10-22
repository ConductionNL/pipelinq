// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client and contact side cards (channels, relationships, BRP, messages)
 * take their props from the manifest instead of a wrapper factory: the
 * detail host resolves `@objectId` and `@object.<field>` (nextcloud-vue
 * CnDetailWidgetHostConsumerProps.spec.js) and draws the titled card for a
 * registry entry with `chrome: "card"`.
 *
 * @spec openspec/specs/contact-relationship-mapping/spec.md#requirement-relationship-management-on-contact-detail-v1
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const registry = read('src', 'registry.js')
const widget = (pageId, id) => manifest.pages.find((p) => p.id === pageId).config.widgets.find((w) => w.id === id)

// The channels, relationships and messages cards are library widgets now
// (channelsFieldListConfig, relationshipsObjectListConfig, messagesConversationConfig).
describe('side cards take their props from the manifest', () => {
	it.each([
		['ContactDetail', 'contact-brp', { contactId: '@objectId' }],
	])('%s %s', (pageId, id, props) => {
		expect(widget(pageId, id).props).toEqual(props)
	})

	it.each([
		['BrpContactWidget', 'BrpContactPanel', false],
	])('%s is the component itself, with the card it had', (key, component, card) => {
		const entry = registry.slice(registry.indexOf(`\t${key}: {`), registry.indexOf('\t},', registry.indexOf(`\t${key}: {`)))
		expect(entry).toContain(`component: ${component},`)
		expect(entry).not.toContain('sectionWidget(')
		expect(entry.includes("chrome: 'card'")).toBe(card)
	})
})

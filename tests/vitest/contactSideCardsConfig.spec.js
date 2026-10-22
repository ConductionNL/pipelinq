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

describe('side cards take their props from the manifest', () => {
	it.each([
		['ClientDetail', 'client-channels', { entityId: '@objectId', entityType: 'client' }],
		['ClientDetail', 'client-relationships', { entityId: '@objectId', entityType: 'client', entityName: '@object.name' }],
		['ContactDetail', 'contact-channels', { entityId: '@objectId', entityType: 'contact' }],
		['ContactDetail', 'contact-relationships', { entityId: '@objectId', entityType: 'contact', entityName: '@object.name' }],
		['ContactDetail', 'contact-brp', { contactId: '@objectId' }],
		['ContactDetail', 'contact-messages', { entityId: '@objectId', entityType: 'contact' }],
	])('%s %s', (pageId, id, props) => {
		expect(widget(pageId, id).props).toEqual(props)
	})

	it.each([
		['ContactRelationshipsWidget', 'ContactRelationships', true],
		['ContactChannelsWidget', 'ContactChannelsSection', true],
		['MessagingConversationWidget', 'MessagingConversationSection', true],
		['BrpContactWidget', 'BrpContactPanel', false],
	])('%s is the component itself, with the card it had', (key, component, card) => {
		const entry = registry.slice(registry.indexOf(`\t${key}: {`), registry.indexOf('\t},', registry.indexOf(`\t${key}: {`)))
		expect(entry).toContain(`component: ${component},`)
		expect(entry).not.toContain('sectionWidget(')
		expect(entry.includes("chrome: 'card'")).toBe(card)
	})
})

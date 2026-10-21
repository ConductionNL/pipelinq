// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The WhatsApp and SMS messages on a contact and a client page are a
 * nextcloud-vue `conversation` over the `channelMessage` list
 * (pipelinq#2073: the slug is the registered one), with each message's
 * delivery status and "Send again" on a failed outbound message
 * (messaging-saved-replies-and-resend). The drawing, the conditions and the
 * refusal texts are nextcloud-vue's (CnConversationReplyTools.spec.js); this
 * file pins the config. The client page lists the messages of all its
 * contacts: the server stamps `clientId` on each message
 * (BackfillConversationFieldsTest).
 *
 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-the-messages-section-lists-the-contacts-messages-req-msr-001
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const page = (id) => manifest.pages.find((p) => p.id === id).config
const contactCard = page('ContactDetail').widgets.find((w) => w.id === 'contact-messages')
const clientCard = page('ClientDetail').bodyWidgets.find((w) => w.id === 'messaging-conversation')

describe.each([
	['contact', contactCard, { contactId: '@objectId' }],
	['client', clientCard, { clientId: '@objectId' }],
])('the messages on a %s page', (kind, card, filterAndProps) => {
	it('lists the registered channelMessage objects of this record', () => {
		expect(card.type).toBe('conversation')
		expect(card.content.source).toMatchObject({ register: 'pipelinq', schema: 'channelMessage', filter: filterAndProps })
		expect(read('src', 'config', 'objectTypes.js')).toContain("slug: 'channelMessage'")
	})

	it('shows each message on its side with channel and delivery status', () => {
		expect(card.content).toMatchObject({ textField: 'body', timeField: 'sentAt', sideField: 'direction', usValue: 'outbound', channelField: 'channel', statusField: 'deliveryStatus' })
		expect(card.content.statusMap.failed).toBe('Failed')
	})

	it('offers Send again on failed and expired outbound SMS and WhatsApp, not twice', () => {
		const [resend] = card.content.messageActions
		expect(resend.endpoint).toBe('/apps/pipelinq/api/messaging/messages/{id}/resend')
		expect(resend.when).toEqual([
			{ field: 'direction', equals: 'outbound' },
			{ field: 'channel', in: ['sms', 'whatsapp'] },
			{ field: 'deliveryStatus', in: ['failed', 'expired'] },
			{ field: 'metadata.resentAs', empty: true },
		])
		expect(Object.keys(resend.errors.map)).toEqual(expect.arrayContaining(['consent-missing', 'budget-exceeded', 'no-provider', 'template-invalid', 'template-required', 'already-resent', 'not-failed']))
	})

	it('opens the Send message dialog for the record', () => {
		expect(card.content.headerAction).toMatchObject({ label: 'Send message', type: 'open-modal', target: 'SendMessageModal', props: filterAndProps })
		expect(read('src', 'registry.js')).toMatch(/SendMessageModal: \{\n\t\tkind: 'modal',\n\t\tcomponent: SendMessageModal,/)
	})
})

describe('the contact page shows the messaging consent', () => {
	it('reads the preflight of the contact', () => {
		const facts = page('ContactDetail').widgets.find((w) => w.id === 'contact-messaging-consent')
		expect(facts.type).toBe('facts')
		expect(facts.content.source.url).toBe('/apps/pipelinq/api/messaging/preflight/@objectId')
		expect(page('ContactDetail').sideColumn).toContain('contact-messaging-consent')
	})
})

describe('every string the cards show is in the catalogues', () => {
	it.each(['en', 'nl'])('%s', (lang) => {
		const catalogue = JSON.parse(read('l10n', `${lang}.json`)).translations
		const texts = new Set()
		for (const card of [contactCard, clientCard]) {
			Object.values(card.content.statusMap).forEach((text) => texts.add(text))
			Object.values(card.content.channelMap).forEach((text) => texts.add(text))
			Object.values(card.content.messageActions[0].errors.map).forEach((text) => texts.add(text))
			texts.add(card.content.messageActions[0].label)
			texts.add(card.content.headerAction.label)
			texts.add(card.content.emptyText)
		}
		for (const text of texts) {
			expect(catalogue[text], text).toBeTruthy()
		}
	})
})

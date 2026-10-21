// @vitest-environment node
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The emails, phone numbers and social profiles of a client or contact are a
 * tab strip of nextcloud-vue `field-list` widgets, with a preferences card
 * beside them. The drawing, add, edit, remove, the exclusive primary flag and
 * the after-save call are nextcloud-vue's (CnFieldListWidgetEdit.spec.js);
 * this file pins the config, including the validation the add and edit forms
 * check. The single `email` and `phone` follow the primary entry on the
 * server (ContactChannelMirrorListenerTest).
 *
 * @spec openspec/changes/channels-as-field-lists/specs/contact-channel-details/spec.md#requirement-detail-pages-show-channels-as-field-lists
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (...parts) => readFileSync(resolve(__dirname, '../..', ...parts), 'utf8')
const manifest = JSON.parse(read('src', 'manifest.json'))
const nl = JSON.parse(read('l10n', 'nl.json')).translations
const page = (id) => manifest.pages.find((p) => p.id === id).config
const widget = (config, id) => config.widgets.find((w) => w.id === id)
const field = (list, key) => list.content.add.fields.find((f) => f.key === key)

describe.each([
	['client', 'ClientDetail', 'clientId'],
	['contact', 'ContactDetail', 'contactId'],
])('the channels of a %s', (kind, pageId, partyProp) => {
	const config = page(pageId)
	const tabs = widget(config, `${kind}-channels`)
	const emails = widget(config, `${kind}-channels-emails`)
	const phones = widget(config, `${kind}-channels-phones`)
	const social = widget(config, `${kind}-channels-social`)

	it('shows Emails, Phones and Social profiles as tabs in the side column, counted off the record', () => {
		expect(config.sideColumn).toEqual(expect.arrayContaining([`${kind}-channels`, `${kind}-preferences`]))
		expect(tabs.type).toBe('tabs')
		expect(tabs.content.tabs).toEqual([
			{ widgetId: `${kind}-channels-emails`, label: 'Emails', countField: 'emails' },
			{ widgetId: `${kind}-channels-phones`, label: 'Phones', countField: 'phones' },
			{ widgetId: `${kind}-channels-social`, label: 'Social profiles', countField: 'socialProfiles' },
		])
		for (const list of [emails, phones, social]) {
			expect(list.type).toBe('field-list')
		}
	})

	it('links an address with mailto: and a number with tel:, with kind and flags', () => {
		expect(emails.content.row.href).toEqual({ path: 'value', scheme: 'mailto' })
		expect(phones.content.row.href).toEqual({ path: 'value', scheme: 'tel' })
		expect(emails.content.row.pill.map.whatsapp).toBe('WhatsApp')
		expect(emails.content.row.meta.map((m) => m.map.true)).toEqual(['Primary', 'Verified'])
		expect(social.content.row).toMatchObject({ title: 'handle', titleFallback: 'url', href: 'url' })
	})

	it('checks an address, a number and a profile link as the old forms did', () => {
		const email = new RegExp(field(emails, 'value').pattern)
		expect(email.test('jan@example.nl')).toBe(true)
		expect(email.test('jan@example')).toBe(false)
		expect(field(emails, 'value')).toMatchObject({ required: true, patternMessage: 'Invalid email format' })

		const phone = new RegExp(field(phones, 'value').pattern)
		expect(phone.test('+31 6 1234 5678')).toBe(true)
		expect(phone.test('12ab')).toBe(false)
		expect(field(phones, 'value').patternMessage).toBe('Invalid phone format')

		const url = new RegExp(field(social, 'url').pattern)
		expect(url.test('https://www.linkedin.com/in/jan')).toBe(true)
		expect(url.test('linkedin')).toBe(false)
		expect(social.content.add).toMatchObject({ requireOneOf: ['handle', 'url'], requireOneOfMessage: 'A handle or a profile URL is required' })
	})

	it('keeps one primary entry, starts a new one as Work or LinkedIn, and edits in place', () => {
		for (const list of [emails, phones]) {
			expect(list.content.add.exclusive).toEqual(['primary'])
			expect(field(list, 'kind').default).toBe('work')
			expect(list.content.edit).toBeTruthy()
			expect(list.content.remove).toBeTruthy()
		}
		expect(field(social, 'network').default).toBe('linkedin')
		expect(social.content.edit).toBeTruthy()
	})

	it('offers Write email on an address, opening the registered composer', () => {
		expect(emails.content.rowActions).toEqual([
			{ id: 'write-email', label: 'Write email', dialog: 'WriteEmailModal', props: { address: '@row.value', [partyProp]: '@objectId' } },
		])
		expect(read('src', 'registry.js')).toMatch(/WriteEmailModal: \{\n\t\tkind: 'modal'/)
	})

	it('writes changed addresses and numbers back to Nextcloud Contacts', () => {
		for (const list of [emails, phones]) {
			expect(list.content.afterSave).toMatchObject({
				url: '/apps/pipelinq/api/contacts-sync/write-back',
				body: { objectType: kind, objectId: '@objectId' },
			})
			expect(list.content.afterSave.failureText).toMatch(/^Your changes are saved here/)
		}
	})

	it('shows the preferred channel, timezone and language in a preferences card', () => {
		const prefs = widget(config, `${kind}-preferences`)
		expect(prefs.type).toBe('facts')
		expect(prefs.content.fields.map((f) => f.path)).toEqual(['preferredChannel', 'timezone', 'correspondenceLanguage'])
	})
})

describe('the channels copy', () => {
	it('is translated into Dutch', () => {
		for (const text of ['Emails', 'Phones', 'Social profiles', 'Write email', 'Invalid email format', 'Invalid phone format', 'Invalid URL format', 'A handle or a profile URL is required', 'Contact preferences', 'Preferred channel', 'Timezone', 'No email addresses yet.', 'No phone numbers yet.', 'No social profiles yet.', 'Followed by us', 'Follows us', 'Email address', 'Phone number', 'Profile URL', 'Handle', 'Network', 'Kind']) {
			expect(nl[text], text).toBeTruthy()
		}
	})
})

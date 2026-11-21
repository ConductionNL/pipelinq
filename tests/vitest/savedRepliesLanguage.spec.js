// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Saved replies in the party's language come first
 * (openspec/changes/specify-capability-rows/specs/messaging-saved-replies/spec.md).
 */

import { describe, expect, it } from 'vitest'
import { fillPlaceholders, repliesForChannel } from '../../src/services/savedReplies.js'

const replies = [
	{ id: 'nl', title: 'Opening hours', language: 'nl', channels: ['email'], body: 'Wij zijn open van 9 tot 5.' },
	{ id: 'en', title: 'Opening hours (EN)', language: 'en', channels: ['email'], body: 'We are open from 9 to 5.' },
	{ id: 'sms-en', title: 'A text', language: 'en', channels: ['sms'], body: 'Hi' },
	{ id: 'off-en', title: 'Old hours', language: 'EN', channels: ['email'], active: false, body: 'x' },
]

describe('a client who asked for English', () => {
	it('lists the English reply for the channel first', () => {
		expect(repliesForChannel(replies, 'email', 'en').map((r) => r.id)).toEqual(['en', 'nl'])
	})

	it('matches the language regardless of case', () => {
		expect(repliesForChannel(replies, 'email', 'EN')[0].id).toBe('en')
	})

	it('puts the English text in the composer when it is picked', () => {
		const [first] = repliesForChannel(replies, 'email', 'en')
		expect(fillPlaceholders(first.body, {})).toBe('We are open from 9 to 5.')
	})
})

describe('a party without a language', () => {
	it('gets the list in its usual title order', () => {
		expect(repliesForChannel(replies, 'email', '').map((r) => r.id)).toEqual(['nl', 'en'])
		expect(repliesForChannel(replies, 'email').map((r) => r.id)).toEqual(['nl', 'en'])
	})
})

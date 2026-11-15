/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The recipients of a mailed report: picker options to stored recipients and back.
 *
 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
 */

import { describe, expect, it } from 'vitest'
import { recipientKey, toOptions, toRecipients } from '../../src/services/mailRecipients.js'

describe('mail recipients', () => {
	it('keeps users and groups, each once, as {type, id}', () => {
		expect(toRecipients([
			{ id: 'anna', type: 'user', label: 'Anna', icon: 'x' },
			{ id: 'teamleads', type: 'group', label: 'Team leads' },
			{ id: 'anna', type: 'user', label: 'Anna again' },
			{ id: 'mail@example.org', type: 'email' },
			{ id: '', type: 'user' },
			null,
		])).toEqual([
			{ type: 'user', id: 'anna' },
			{ type: 'group', id: 'teamleads' },
		])
	})

	it('keeps a user and a group with the same id apart', () => {
		expect(toRecipients([{ id: 'hr', type: 'user' }, { id: 'hr', type: 'group' }])).toHaveLength(2)
		expect(recipientKey({ type: 'group', id: 'hr' })).toBe('group:hr')
	})

	it('has no recipients for nothing picked', () => {
		expect(toRecipients(null)).toEqual([])
		expect(toOptions(undefined)).toEqual([])
	})

	it('labels stored recipients from the options seen, else by id', () => {
		expect(toOptions(
			[{ type: 'group', id: 'teamleads' }, { type: 'user', id: 'bob' }],
			[{ id: 'teamleads', type: 'group', label: 'Team leads' }, { id: 'bob', type: 'group', label: 'Not this one' }],
		)).toEqual([
			{ type: 'group', id: 'teamleads', key: 'group:teamleads', label: 'Team leads' },
			{ type: 'user', id: 'bob', key: 'user:bob', label: 'bob' },
		])
	})
})

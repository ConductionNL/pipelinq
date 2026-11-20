// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The compact channels list on a contact's Overview (board PqContactpersoon
 * Kanalen): one label and line per channel, mobile without its kind, the
 * preferred address marked.
 *
 * @spec openspec/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-is-the-board
 */

import { describe, expect, it } from 'vitest'

const { default: ContactChannelsSection } = await import('../../src/components/ContactChannelsSection.vue')

describe('compact channel rows', () => {
	it('reads as the board draws them', () => {
		const ctx = {
			t: (_a, s) => s,
			kindLabel: (kind) => ({ work: 'Work', home: 'Home' }[kind] || kind),
			networkLabel: (network) => ({ mastodon: 'Mastodon' }[network] || network),
			emails: [{ value: 'j.smit@sportcentrum-zd.nl', kind: 'work', primary: true }],
			phones: [{ value: '078 612 55 20', kind: 'work' }, { value: '06 18 22 40 91', kind: 'mobile' }],
			socialProfiles: [{ network: 'mastodon', handle: '@jokesmit@mastodon.nl' }],
		}
		const rows = ContactChannelsSection.computed.compactRows.call(ctx)
		expect(rows.map((r) => [r.label, r.text])).toEqual([
			['Email', 'j.smit@sportcentrum-zd.nl, work, preferred'],
			['Phone', '078 612 55 20, work'],
			['Mobile', '06 18 22 40 91'],
			['Mastodon', '@jokesmit@mastodon.nl'],
		])
	})
})

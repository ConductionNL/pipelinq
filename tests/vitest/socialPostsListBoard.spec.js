/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqSocial as configuration of the social posts list: Download and
 * New post in the header (New post opens the composer), the All / Waiting
 * for approval / Scheduled / Failed lenses with counts, the Filter menu, the
 * board's columns with the status as a coloured badge, the count line and the
 * footer note. The connected accounts page is a custom view and carries none
 * of the list's keys.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const pages = read('src/manifest.d/78-social-publishing.json').pages
const posts = pages.find((p) => p.id === 'SocialPosts').config
const accounts = pages.find((p) => p.id === 'SocialAccounts').config

describe('social posts list (board PqSocial)', () => {
	it('has Download and New post as the header buttons, New post opening the composer', () => {
		expect(posts.headerButtons.map((b) => nl[b.label])).toEqual(['Downloaden', 'Nieuw bericht'])
		const create = posts.headerButtons.find((b) => b.variant === 'primary')
		const action = posts.headerActions.find((a) => a.id === create.action)
		expect(action).toMatchObject({ handler: 'navigate', route: 'SocialPostNew' })
	})

	it('offers the board lenses with counts and the Filter menu', () => {
		expect(posts.quickFilters.map((q) => nl[q.label])).toEqual(['Alle', 'Wacht op goedkeuring', 'Ingepland', 'Mislukt'])
		expect(posts.quickFilters.every((q) => q.showCount === true)).toBe(true)
		expect(posts.filterMenu).toBe(true)
	})

	it('draws the board columns with the status as a coloured badge', () => {
		expect(posts.columns.map((c) => nl[c.label])).toEqual(['Titel', 'Status', 'Gepland voor', 'Gepubliceerd op'])
		const status = posts.columns[1]
		expect(status.widget).toBe('badge')
		expect(status.widgetProps.colorMap).toMatchObject({ approval: 'purple', scheduled: 'info', published: 'success', failed: 'error' })
	})

	it('has the count line and the footer note', () => {
		expect(nl[posts.countText]).toBe('{shown} van {total} berichten · nieuwste eerst')
		expect(nl[posts.footerNote]).toBe('Een bericht kan tegelijk naar meerdere netwerken, elk met een eigen tekst.')
	})

	it('leaves the list keys off the accounts page', () => {
		for (const key of ['quickFilters', 'headerButtons', 'columns', 'rowRoute', 'countText', 'footerNote']) {
			expect(accounts).not.toHaveProperty(key)
		}
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqSocial under the posts list: "Sociale prestaties", one row per
 * network over the last 30 days read from /api/social-performance/networks,
 * and "Social accounts", each account with its status and the step that
 * fits it, linking to the account.
 *
 * @spec openspec/specs/social-metrics/spec.md#requirement-social-performance-is-summed-per-network-for-a-period
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const posts = read('src/manifest.d/78-social-publishing.json').pages.find((p) => p.id === 'SocialPosts').config

describe('social posts after-list cards (board PqSocial)', () => {
	it('draws the performance table and the accounts card in that order', () => {
		expect(posts.afterList.map((w) => [w.type, nl[w.title]])).toEqual([
			['object-table', 'Sociale prestaties'],
			['record-list', 'Sociale accounts'],
		])
	})

	it('reads the per-network totals for 30 days with the board columns', () => {
		const table = posts.afterList[0].content
		expect(table.endpointSource).toEqual({
			url: '/apps/pipelinq/api/social-performance/networks',
			params: { days: 30 },
			responsePath: 'data',
		})
		expect(table.columns.map((c) => nl[c.label])).toEqual([
			'Netwerk', 'Gepubliceerd', 'Weergaven', 'Likes', 'Reacties', 'Gedeeld', 'Volgers', 'Interactiepercentage',
		])
		expect(table.columns.at(-1).format).toEqual({ style: 'percent', decimals: 1 })
		expect(table.columns.slice(1).every((c) => c.align === 'end')).toBe(true)
	})

	it('lists the accounts with a status pill and a step per status', () => {
		const card = posts.afterList[1].content
		expect(card).toMatchObject({ register: 'pipelinq', schema: 'socialAccount' })
		expect(card.pill.field).toBe('status')
		expect(nl[card.pill.labels.relink_needed]).toBe('Opnieuw koppelen nodig')
		expect(card.pill.colorMap).toMatchObject({ active: 'success', relink_needed: 'warning' })
		expect(card.rowAction).toMatchObject({ type: 'open-page', target: 'SocialAccountDetail', variant: 'outlined' })
		expect(nl[card.rowAction.labels.map.active]).toBe('Intrekken')
		expect(nl[card.rowAction.labels.map.not_configured]).toBe('Koppelen')
	})
})

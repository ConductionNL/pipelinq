/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ticket list in the simple structure shows the newest ticket first.
 *
 * It sorted by waiting time, oldest first, so a resident's question asked a
 * minute ago landed on the last of five pages for the KCC agent (Zuiddrecht
 * live round 3, 14 October 2026). The queue (Wachtrij) is where tickets are
 * worked oldest first; this list is where an agent looks for what just came
 * in. The header still sorts by waiting time on one click.
 *
 * @spec openspec/specs/kcc-werkplek/spec.md#requirement-the-ticket-list-shows-the-newest-ticket-first
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

/**
 * The simple structure's config for the ticket list, found by its count line.
 *
 * @param {*} node Any JSON value.
 * @return {object|undefined} The config.
 */
function ticketListConfig(node) {
	if (!node || typeof node !== 'object') {
		return undefined
	}
	if (
		typeof node.countText === 'string'
		&& node.countText.includes('tickets · customer contact')
	) {
		return node
	}
	for (const value of Object.values(node)) {
		const hit = ticketListConfig(value)
		if (hit) {
			return hit
		}
	}
	return undefined
}

describe('the simple ticket list', () => {
	const config = ticketListConfig(read('src/menu-layout.simple.json'))

	it('sorts the newest ticket first', () => {
		expect(config.sortKey).toBe('occurredAt')
		expect(config.sortOrder).toBe('desc')
	})

	it('says so in Dutch in its count line', () => {
		expect(config.countText).toContain('newest first')
		expect(read('l10n/nl.json').translations[config.countText]).toContain(
			'nieuwste eerst',
		)
	})
})

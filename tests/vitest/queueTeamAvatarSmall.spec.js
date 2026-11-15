/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqWachtrij draws the colleagues in "Team today" with the 32px
 * avatar, as dossiq's team workload card does (pl8 PqWachtrij 7-9: 36 live).
 *
 * @spec openspec/changes/queue-team-avatar-small/specs/dashboard-widgets/spec.md#requirement-the-queue-team-card-draws-the-small-avatar
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const layout = JSON.parse(readFileSync(resolve(__dirname, '../../src/menu-layout.simple.json'), 'utf8'))

/**
 * Every object in a JSON tree with this id.
 *
 * @param {unknown} node The tree.
 * @param {string} id The id.
 * @return {Array<object>} The matches.
 */
function findById(node, id) {
	if (!node || typeof node !== 'object') {
		return []
	}
	const own = node.id === id ? [node] : []
	return own.concat(...Object.values(node).map((child) => findById(child, id)))
}

describe('queue Team today', () => {
	const [card] = findById(layout, 'queue-team')

	it('draws the 32px avatar with the colleague initials', () => {
		expect(card.type).toBe('record-list')
		expect(card.content.avatar).toEqual({ text: '{displayName}', size: 32 })
	})
})

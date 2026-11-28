/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The capability row `work-saved-view` reads built, and stays true to the
 * manifest: the pages its evidence names still save (and, on Tickets, share)
 * OpenRegister saved views through nextcloud-vue.
 *
 * @spec openspec/changes/archive/2026-11-28-my-work-saved-views-and-phone/tasks.md#3-close
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))
const manifest = read('src/manifest.json')

/**
 * Find a capability row by its id anywhere in the parity file.
 *
 * @param {object|Array} node The parsed parity file or a part of it.
 * @param {string} id The row id.
 * @return {object|null} The row, or null when absent.
 */
function findRow(node, id) {
	if (Array.isArray(node)) {
		for (const item of node) {
			const found = findRow(item, id)
			if (found) return found
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.built) return node
		for (const value of Object.values(node)) {
			const found = findRow(value, id)
			if (found) return found
		}
	}
	return null
}

const row = findRow(read('openspec/parity/capabilities.json'), 'work-saved-view')

describe('capability row work-saved-view', () => {
	it('reads built by pipelinq, pointing at the my-work spec and REQ-MW-150', () => {
		expect(row).not.toBeNull()
		expect(row.built.state).toBe('built')
		expect(row.built.owner).toBe('ConductionNL/pipelinq')
		expect(row.built.spec).toBe('openspec/specs/my-work')
		expect(row.built.change).toBeUndefined()
		expect(row.built.requirement.some((r) => r.includes('REQ-MW-150'))).toBe(true)
		expect(row.built.plan).toBeUndefined()
	})

	it('names pages that still save and share views', () => {
		const tickets = manifest.pages.find((p) => p.id === 'Tickets' && p.route === '/tickets')
		expect(tickets.config.allowSavedViews).toBe(true)
		expect(tickets.config.savedViewShare).toBe(true)

		const myWork = manifest.pages.find((p) => p.id === 'MyWork')
		const savedViews = myWork.config.widgets.find((w) => w.id === 'my-saved-views')
		expect(savedViews.type).toBe('saved-views')

		expect(row.built.evidence).toContain('allowSavedViews')
		expect(row.built.evidence).toContain('my-saved-views')
	})
})

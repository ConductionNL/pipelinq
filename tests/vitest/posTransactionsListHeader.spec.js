/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassabonnen: the transactions list has Download and New
 * transaction as its header buttons (no Actions menu) and the Filter menu
 * beside the lenses.
 *
 * @spec openspec/specs/declarative-view-system/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const nl = read('l10n/nl.json').translations
const base = read('src/manifest.json').pages.find((p) => p.id === 'PosTransactions').config
const config = read('src/menu-layout.simple.json').pages.find((p) => p.id === 'PosTransactions').config

describe('transactions list header', () => {
	it('has Download and New transaction as the header buttons', () => {
		expect(config.headerButtons).toEqual([
			{ action: 'export', label: 'Download' },
			{ action: 'create', variant: 'primary', icon: 'Plus', label: 'New transaction' },
		])
		expect(base.headerActions.map((a) => a.id)).toContain('create')
		expect(nl['New transaction']).toBe('Nieuwe transactie')
	})

	it('draws the Filter menu', () => {
		expect(config.filterMenu).toBe(true)
	})
})

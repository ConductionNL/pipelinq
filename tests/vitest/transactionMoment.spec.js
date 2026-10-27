/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqKassabonnen: a transaction's moment is when it was confirmed, else
 * when it was parked, else when it was made. The "Aangemaakt" column shows it
 * and the "Vandaag" lens filters on it, so a seeded or imported sale reads its
 * own time, not the install time.
 *
 * @spec openspec/changes/pos-transaction-moment/specs/pos-transaction-core/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { CELL_FORMATTERS, transactionMoment } from '../../src/services/cellFormatters.js'

const read = (path) => JSON.parse(readFileSync(resolve(__dirname, '../..', path), 'utf8'))
const NOW = new Date('2026-10-07T12:00:00')

describe('the transaction moment', () => {
	it('is the confirmation, else the parking, else the creation', () => {
		expect(transactionMoment(undefined, { confirmedAt: '2026-10-07T11:42:00', parkedAt: '2026-10-07T11:00:00' }, undefined, { now: NOW, locale: 'nl-NL' })).toBe('7 okt, 11:42')
		expect(transactionMoment(undefined, { parkedAt: '2026-10-07T11:20:00' }, undefined, { now: NOW, locale: 'nl-NL' })).toBe('7 okt, 11:20')
		expect(transactionMoment(undefined, { '@self': { created: '2026-03-03T15:30:00' } }, undefined, { now: NOW, locale: 'nl-NL' })).toBe('3 mrt, 15:30')
		expect(transactionMoment(undefined, {}, undefined, { now: NOW })).toBe('')
	})

	it('adds the year outside the current one', () => {
		expect(transactionMoment(undefined, { confirmedAt: '2025-12-30T09:05:00' }, undefined, { now: NOW, locale: 'nl-NL' })).toBe('30 dec 2025, 09:05')
	})

	it('is a registered formatter', () => {
		expect(CELL_FORMATTERS.transactionMoment).toBe(transactionMoment)
	})

	it('drives the Aangemaakt column and the Vandaag lens on the transactions list', () => {
		const simple = read('src/menu-layout.simple.json')
		const config = simple.pages.find((p) => p.id === 'PosTransactions').config
		const column = config.columns.find((c) => c.label === 'Created')
		expect(column).toMatchObject({ key: 'momentAt', formatter: 'transactionMoment' })
		const today = config.quickFilters.find((f) => f.label === 'Today')
		expect(today.filter).toEqual({ 'momentAt[gte]': '@today' })
	})

	it('is stored on the transaction as the confirmation, else the parking', () => {
		const fragment = read('lib/Settings/register.d/93-pos-transaction-moment.json')
		const moment = fragment.components.schemas.posTransaction.properties.momentAt
		expect(moment.computed).toEqual({ expression: '{{ confirmedAt ?: parkedAt }}', evaluateOn: 'save' })
	})
})

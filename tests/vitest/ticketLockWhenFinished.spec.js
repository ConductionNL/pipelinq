// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqTicket (#821): a finished ticket is locked from the menu More and
 * unlocked again; the lock is OpenRegister's, held until it is unlocked.
 *
 * @spec openspec/changes/ticket-lock-when-finished/specs/ticket-detail-page/spec.md#requirement-a-finished-ticket-can-be-locked
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const readJson = (...parts) => JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
const layout = readJson('src/menu-layout.simple.json')
const page = layout.pages.find((p) => p.id === 'TicketDetail')
const action = (id) => page.config.headerActions.find((a) => a.id === id)

describe('ticket lock', () => {
	it('locks through OpenRegister without a duration, so the lock holds until it is unlocked', () => {
		const lock = action('ticket-lock')
		expect(lock.type).toBe('api-call')
		expect(lock.url).toBe('/apps/openregister/api/objects/pipelinq/ticket/@objectId/lock')
		expect(lock.payload).toEqual({})
		expect(action('ticket-unlock').url).toBe('/apps/openregister/api/objects/pipelinq/ticket/@objectId/unlock')
		expect(page.config.quickActions).not.toContain('ticket-lock')
	})

	it('offers Lock only on a finished ticket without a lock, and Unlock on a locked one', () => {
		const lockWhen = action('ticket-lock').visibleWhen
		const unlockWhen = action('ticket-unlock').visibleWhen
		const held = { user: 'pieter', created: '2026-10-12T16:40:00+02:00', duration: null }
		expect(evaluateVisibleWhenLocal(lockWhen, { status: 'completed', '@self': {} })).toBe(true)
		expect(evaluateVisibleWhenLocal(lockWhen, { status: 'in_progress', '@self': {} })).toBe(false)
		expect(evaluateVisibleWhenLocal(lockWhen, { status: 'completed', '@self': { locked: held } })).toBe(false)
		expect(evaluateVisibleWhenLocal(unlockWhen, { status: 'completed', '@self': { locked: held } })).toBe(true)
		expect(evaluateVisibleWhenLocal(unlockWhen, { status: 'completed', '@self': {} })).toBe(false)
	})

	it('words the card and every label in English and Dutch', () => {
		const en = readJson('l10n/en.json').translations
		const nl = readJson('l10n/nl.json').translations
		expect(nl[page.config.lockNotice]).toContain('Vergrendeld door {user} op {date} om {time}.')
		for (const word of [page.config.lockNotice, ...['ticket-lock', 'ticket-unlock'].flatMap((id) => [action(id).label, action(id).successMessage, action(id).errorMessage])]) {
			expect(en[word], word).toBeTruthy()
			expect(nl[word], word).toBeTruthy()
		}
	})
})

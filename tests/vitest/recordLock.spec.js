// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lock and Unlock on the client, lead and ticket detail pages, as manifest
 * configuration over OpenRegister's freeze: Lock posts the freeze, Unlock
 * deletes it, a lead or ticket offers Lock only once it is finished, and a
 * banner names who locked the record and when.
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md
 */

import { evaluateVisibleWhenLocal } from '@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'))
const nl = JSON.parse(fs.readFileSync(path.join(ROOT, 'l10n', 'nl.json'), 'utf8')).translations

const PAGES = [
	{ page: 'ClientDetail', schema: 'client', open: { status: 'active' }, finished: null },
	{ page: 'LeadDetail', schema: 'lead', open: { status: 'open', isFinished: false }, finished: { status: 'won', isFinished: true } },
	{ page: 'TicketDetail', schema: 'ticket', open: { status: 'in_progress', isFinished: false }, finished: { status: 'resolved', isFinished: true } },
]

const config = (id) => manifest.pages.find((p) => p.id === id).config
const action = (id, actionId) => config(id).headerActions.find((a) => a.id === actionId)
const frozen = { by: 'anna', at: '2026-11-12T09:00:00+00:00' }
const visible = (cond, record) => evaluateVisibleWhenLocal(cond, record)

describe.each(PAGES)('$page', ({ page, schema, open, finished }) => {
	const url = `/apps/openregister/api/objects/pipelinq/${schema}/@objectId/freeze`

	it('locks through OpenRegister\'s freeze, behind a confirmation', () => {
		const lock = action(page, 'lock-record')
		expect(lock).toMatchObject({ type: 'api-call', method: 'POST', url, confirm: true })
		expect(nl[lock.label]).toBe('Vergrendelen')
		expect(nl[lock.confirmMessage]).toContain('vergrendeld')
	})

	it('unlocks by deleting the freeze at the same address', () => {
		const unlock = action(page, 'unlock-record')
		expect(unlock).toMatchObject({ type: 'api-call', method: 'DELETE', url })
		expect(unlock.confirm).toBeUndefined()
		expect(nl[unlock.label]).toBe('Ontgrendelen')
	})

	it('offers exactly one of Lock and Unlock', () => {
		const lockable = finished ?? open
		const locked = { ...lockable, '@self': { frozen } }
		const unlocked = { ...lockable, '@self': { frozen: null } }

		expect(visible(action(page, 'lock-record').visibleWhen, unlocked)).toBe(true)
		expect(visible(action(page, 'unlock-record').visibleWhen, unlocked)).toBe(false)
		expect(visible(action(page, 'lock-record').visibleWhen, locked)).toBe(false)
		expect(visible(action(page, 'unlock-record').visibleWhen, locked)).toBe(true)
	})

	it('shows a banner naming who locked the record, only while it is locked', () => {
		const banner = config(page).widgets.find((w) => w.id === `${schema}-locked`)
		expect(banner.type).toBe('banner')
		expect(banner.content.text).toContain('{subject.@self.frozen.by}')
		expect(banner.content.text).toContain('{subject.@self.frozen.at|date}')
		expect(nl[banner.content.text]).toContain('Vergrendeld door {subject.@self.frozen.by}')
		expect(visible(banner.visibleWhen, { '@self': { frozen } })).toBe(true)
		expect(visible(banner.visibleWhen, { '@self': {} })).toBe(false)

		const placed = [...(config(page).band ?? []), ...(config(page).layout ?? [])].map((cell) => cell.widgetId)
		expect(placed).toContain(`${schema}-locked`)
	})
})

describe('a lead or ticket that is not finished', () => {
	it.each(['LeadDetail', 'TicketDetail'])('%s does not offer Lock', (page) => {
		const record = { status: 'open', isFinished: false, '@self': { frozen: null } }
		expect(visible(action(page, 'lock-record').visibleWhen, record)).toBe(false)
	})

	it('a client offers Lock whatever its state', () => {
		expect(visible(action('ClientDetail', 'lock-record').visibleWhen, { '@self': {} })).toBe(true)
	})
})

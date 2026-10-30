// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lock and Unlock on ClientDetail, LeadDetail and TicketDetail: the freeze
 * address each action calls, and which of the two the Actions menu offers for
 * a record, evaluated with nextcloud-vue's own visibleWhen reader.
 *
 * @spec openspec/changes/platform-record-lock/specs/record-locking/spec.md
 */

import { describe, expect, it } from 'vitest'
import { evaluateVisibleWhenLocal } from '../../node_modules/@conduction/nextcloud-vue/src/utils/visibleWhen.js'
import manifest from '../../src/manifest.json'

const PAGES = {
	ClientDetail: 'client',
	LeadDetail: 'lead',
	TicketDetail: 'ticket',
}

const FROZEN = { by: 'admin', at: '2026-10-29T10:00:00+00:00', reason: null }

/**
 * The config of a page.
 *
 * @param {string} id The page id.
 * @return {object} The page config.
 */
function config(id) {
	return manifest.pages.find((page) => page.id === id).config
}

/**
 * A header action of a page.
 *
 * @param {string} id The page id.
 * @param {string} actionId The action id.
 * @return {object} The action.
 */
function action(id, actionId) {
	return config(id).headerActions.find((entry) => entry.id === actionId)
}

/**
 * Whether a header action shows for a record.
 *
 * @param {object} entry The action.
 * @param {object} record The record as OpenRegister returns it.
 * @return {boolean} Whether it shows.
 */
function shows(entry, record) {
	return evaluateVisibleWhenLocal(entry.visibleWhen, record)
}

describe('record lock on the detail pages', () => {
	it.each(Object.entries(PAGES))('%s locks with POST and unlocks with DELETE on the freeze address', (id, schema) => {
		const lock = action(id, 'lock-record')
		const unlock = action(id, 'unlock-record')
		const address = `/apps/openregister/api/objects/pipelinq/${schema}/@objectId/freeze`

		expect(lock).toMatchObject({ type: 'api-call', method: 'POST', url: address, confirm: true })
		expect(unlock).toMatchObject({ type: 'api-call', method: 'DELETE', url: address })
	})

	it.each(Object.keys(PAGES))('%s offers Unlock and the banner, not Lock, on a locked record', (id) => {
		const record = { status: 'won', isFinished: true, '@self': { frozen: FROZEN } }
		const banner = config(id).widgets.find((widget) => widget.id.endsWith('-locked'))

		expect(shows(action(id, 'lock-record'), record)).toBe(false)
		expect(shows(action(id, 'unlock-record'), record)).toBe(true)
		expect(evaluateVisibleWhenLocal(banner.visibleWhen, record)).toBe(true)
		expect(config(id).band.some((entry) => entry.widgetId === banner.id)).toBe(true)
	})

	it.each(Object.keys(PAGES))('%s offers Lock and hides the banner on an unlocked finished record', (id) => {
		const record = { status: 'won', isFinished: true, '@self': { frozen: null } }
		const banner = config(id).widgets.find((widget) => widget.id.endsWith('-locked'))

		expect(shows(action(id, 'lock-record'), record)).toBe(true)
		expect(shows(action(id, 'unlock-record'), record)).toBe(false)
		expect(evaluateVisibleWhenLocal(banner.visibleWhen, record)).toBe(false)
	})

	it.each(['LeadDetail', 'TicketDetail'])('%s does not offer Lock on an open record', (id) => {
		const record = { status: 'in_progress', isFinished: false, '@self': { frozen: null } }

		expect(shows(action(id, 'lock-record'), record)).toBe(false)
	})

	it('ClientDetail offers Lock on any client, which has no finished state', () => {
		expect(shows(action('ClientDetail', 'lock-record'), { name: 'Bakkerij Jansen', '@self': {} })).toBe(true)
	})
})

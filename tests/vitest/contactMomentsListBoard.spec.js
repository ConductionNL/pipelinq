/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact moments list (the tickets list opened with
 * ?ticketType=interaction) as board PqContactmomenten draws it: Log contact
 * is the primary button, a subject carries its reference, the channel reads
 * with its direction ("Telefoon · inkomend"), the time reads as a day and a
 * time ("vandaag, 09.12"), and long texts wrap instead of being cut off.
 *
 * @spec openspec/changes/contact-moments-list-board/specs/contact-moments-list-board/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '..', '..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))

/**
 * The simple structure's config for the ticket list: the one with query presets.
 *
 * @param {*} node Any JSON value.
 * @return {object|undefined} The config.
 */
function listConfig(node) {
	if (!node || typeof node !== 'object') {
		return undefined
	}
	if (Array.isArray(node.queryPresets)) {
		return node
	}
	for (const value of Object.values(node)) {
		const hit = listConfig(value)
		if (hit) {
			return hit
		}
	}
	return undefined
}

const config = listConfig(read('src/menu-layout.simple.json'))
const preset = config.queryPresets.find((entry) => entry.id === 'contact-moments')
const column = (key) => preset.columns.find((entry) => entry.key === key)
const nl = read('l10n/nl.json').translations
const en = read('l10n/en.json').translations

describe('the contact moments list (pl2 PqContactmomenten)', () => {
	it('has Log contact as its primary button, a contact moment form', () => {
		const primary = preset.headerButtons.find((button) => button.variant === 'primary')
		expect(primary.action).toBe('log-contact')
		const action = config.headerActions.find((entry) => entry.id === 'log-contact')
		expect(action).toMatchObject({ type: 'open-form', register: 'pipelinq', schema: 'ticket', props: { ticketType: 'interaction' } })
		expect(nl[action.label]).toBe('Contact vastleggen')
		expect(preset.headerButtons.map((button) => button.action)).toEqual(['export', 'log-contact'])
	})

	it('offers no Add ticket of its own: a contact moment is added with Log contact', () => {
		expect(preset.showAdd).toBe(false)
	})

	it('keeps the requests list\'s menu as it was: the new header action is not in it', () => {
		const menu = config.headerButtons.find((button) => button.action === 'actions-menu')
		expect(menu.items).not.toContain('header-actions')
		expect(menu.items).toContain('import')
		expect(config.headerButtons.find((button) => button.variant === 'primary').action).toBe('add')
	})

	it('shows the subject with its reference, and lets long texts wrap', () => {
		expect(column('title').secondary).toBe('{reference}')
		for (const key of ['title', 'client', 'outcome']) {
			expect(column(key).cellClass, key).toContain('cn-cell--wrap')
		}
	})

	it('reads the channel with its direction, in words', () => {
		const channel = column('channel')
		expect(channel.template).toBe('{channel} · {direction}')
		for (const value of ['telefoon', 'phone', 'balie', 'email', 'portal', 'web', 'chat', 'brief']) {
			expect(channel.secondaryLabels.channel[value], value).toBeTruthy()
		}
		expect(channel.secondaryLabels.direction).toEqual({ inbound: 'incoming', outbound: 'outgoing', internal: 'internal' })
		for (const word of ['incoming', 'outgoing', 'internal']) {
			expect(en[word], word).toBe(word)
		}
		expect([nl.incoming, nl.outgoing, nl.internal]).toEqual(['inkomend', 'uitgaand', 'intern'])
	})

	it('reads when as a day and a time', () => {
		expect(column('occurredAt').template).toBe('{occurredAt|relativetime}')
	})
})

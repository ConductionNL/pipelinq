/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The ticket page's History tab (board PqTicket) tells in words who passed the
 * ticket to whom and what happened to it afterwards, from OpenRegister's audit
 * trail through the shared `audit-trail` widget: configuration, no ticket log
 * of its own.
 *
 * @spec openspec/changes/ticket-handover-history/specs/ticket-handover-history/spec.md#REQ-THH-001
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const ROOT = path.resolve(__dirname, '../..')
const read = (file) => JSON.parse(fs.readFileSync(path.join(ROOT, file), 'utf8'))
const manifest = read('src/manifest.json')
const simple = read('src/menu-layout.simple.json')
const nl = read('l10n/nl.json').translations
const nlJs = fs.readFileSync(path.join(ROOT, 'l10n', 'nl.js'), 'utf8')

/**
 * The ticket schema's status enum, as the import composes it.
 *
 * @return {Array<string>} The statuses.
 */
function ticketStatuses() {
	let status = null
	const files = ['lib/Settings/pipelinq_register.json', ...fs.readdirSync(path.join(ROOT, 'lib/Settings/register.d')).sort().map((f) => `lib/Settings/register.d/${f}`)]
	for (const file of files.filter((f) => f.endsWith('.json'))) {
		const ticket = read(file).components?.schemas?.ticket
		if (ticket?.properties?.status) {
			status = ticket.properties.status
		}
	}
	return status.enum
}

/**
 * Find a widget by id anywhere in a JSON tree.
 *
 * @param {unknown} node The tree.
 * @param {string} id The widget id.
 * @return {object|null} The widget.
 */
function findWidget(node, id) {
	if (Array.isArray(node)) {
		for (const child of node) {
			const found = findWidget(child, id)
			if (found) {
				return found
			}
		}
		return null
	}
	if (node && typeof node === 'object') {
		if (node.id === id && node.type === 'audit-trail') {
			return node
		}
		for (const value of Object.values(node)) {
			const found = findWidget(value, id)
			if (found) {
				return found
			}
		}
	}
	return null
}

const detail = manifest.pages.find((p) => p.id === 'TicketDetail').config
const full = detail.widgets.find((w) => w.id === 'ticket-history')
const lean = findWidget(simple, 'ticket-history')

describe('ticket handover history', () => {
	it('puts a History tab on the ticket page, rendered by the tab strip', () => {
		const panels = detail.widgets.find((w) => w.id === 'ticket-panels')
		expect(panels.content.tabs).toContainEqual({ widgetId: 'ticket-history', label: 'History' })
		expect(full.type).toBe('audit-trail')
		expect(detail.layout.map((cell) => cell.widgetId)).not.toContain('ticket-history')
	})

	it('words creation and a handover', () => {
		const { actionLabels, changeLabels } = full.content
		expect(actionLabels.create).toBe('Created by {actor}')
		expect(actionLabels.update).toBe('Updated by {actor}')
		expect(changeLabels[0]).toEqual({ action: 'update', field: 'assignee', label: 'Passed to {changed.assignee} by {actor}' })
	})

	it('words every status of the ticket schema, for updates only', () => {
		const statusRules = full.content.changeLabels.filter((rule) => rule.field === 'status')
		expect(statusRules.map((rule) => rule.to).sort()).toEqual([...ticketStatuses()].sort())
		for (const rule of full.content.changeLabels) {
			expect(rule.action).toBe('update')
			expect(rule.label).toMatch(/ by \{actor\}$/)
		}
		expect(statusRules.find((rule) => rule.to === 'closed').label).toBe('Closed by {actor}')
	})

	it('leaves read entries out', () => {
		expect(full.content.excludeActions).toEqual(['read'])
	})

	it('says the same in the simple structure', () => {
		expect(lean).not.toBeNull()
		expect(lean.content).toEqual(full.content)
	})

	it('has every word in both Dutch catalogues', () => {
		const words = [...Object.values(full.content.actionLabels), ...full.content.changeLabels.map((rule) => rule.label), full.title]
		for (const text of words) {
			expect(nl[text], text).toBeTruthy()
			expect(nl[text]).not.toBe(text)
			expect(nlJs).toContain(`"${text}": "${nl[text]}"`)
		}
	})
})

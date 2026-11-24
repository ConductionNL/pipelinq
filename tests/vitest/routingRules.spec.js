/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The routing rules turn into the flow's decision table and back: the order
 * survives, the catch-all stays last, cells are exact literals and an empty
 * field is the wildcard.
 *
 * @spec openspec/changes/requests-inbound-to-queue/specs/inbound-ticket-intake/spec.md#requirement-an-administrator-routes-new-tickets-by-rules-req-itq-005
 */
import { describe, expect, it } from 'vitest'
import {
	CATCH_ALL_ID,
	cellFromValue,
	emptyRow,
	findRoutingFlow,
	moveRow,
	nodesWithRows,
	rowProblems,
	rowsFromTable,
	tableFromRows,
	tableOf,
	valueFromCell,
} from '../../src/services/routingRules.js'

const shippedTable = {
	hitPolicy: 'FIRST',
	inputs: ['channel', 'fromAddress', 'fromDomain', 'mailbox', 'clientSegment'].map((name) => ({ name, type: 'string' })),
	outputs: [{ name: 'category' }, { name: 'priority' }],
	rules: [{ id: CATCH_ALL_ID, inputEntries: ['-', '-', '-', '-', '-'], outputEntries: ['', 'normal'] }],
}

const flow = {
	id: 'flow-1',
	name: 'Route incoming tickets',
	app: 'pipelinq',
	enabled: false,
	nodes: [
		{ id: 'ticket-created', type: 'openregister.trigger-object', config: { event: 'object.created' } },
		{ id: 'routing-rules', type: 'openregister.decision-table', config: { table: shippedTable, resultKey: 'channelMetadata.routing' } },
	],
}

const kadaster = { ...emptyRow(), fromDomain: 'Kadaster.nl', category: 'Registratie', priority: 'high' }
const whatsapp = { ...emptyRow(), channel: 'whatsapp', category: 'Belastingen' }

describe('routingRules', () => {
	it('reads the shipped table as no rows', () => {
		expect(rowsFromTable(shippedTable)).toEqual([])
	})

	it('turns rows into a table that ends in the catch-all', () => {
		const table = tableFromRows([kadaster, whatsapp], shippedTable)

		expect(table.hitPolicy).toBe('FIRST')
		expect(table.rules).toEqual([
			{ id: 'rule-1', inputEntries: ['-', '-', '"kadaster.nl"', '-', '-'], outputEntries: ['Registratie', 'high'] },
			{ id: 'rule-2', inputEntries: ['"whatsapp"', '-', '-', '-', '-'], outputEntries: ['Belastingen', 'normal'] },
			{ id: CATCH_ALL_ID, description: 'Everything else: no category, normal priority.', inputEntries: ['-', '-', '-', '-', '-'], outputEntries: ['', 'normal'] },
		])
	})

	it('reads its own table back as the same rows, in order', () => {
		const rows = rowsFromTable(tableFromRows([kadaster, whatsapp], shippedTable))

		expect(rows.map((row) => [row.id, row.channel, row.fromDomain, row.category, row.priority])).toEqual([
			['rule-1', '', 'kadaster.nl', 'Registratie', 'high'],
			['rule-2', 'whatsapp', '', 'Belastingen', 'normal'],
		])
	})

	it('keeps a row id across a save and renumbers a duplicate', () => {
		const table = tableFromRows([{ ...kadaster, id: 'partners' }, { ...whatsapp, id: 'partners' }, { ...whatsapp, id: CATCH_ALL_ID }])

		expect(table.rules.map((rule) => rule.id)).toEqual(['partners', 'rule-2', 'rule-3', CATCH_ALL_ID])
	})

	it('writes an exact literal and reads a wildcard as empty', () => {
		expect(cellFromValue('fromAddress', ' Info@Bakkerij.NL ')).toBe('"info@bakkerij.nl"')
		expect(cellFromValue('mailbox', 'Service@Gemeente.nl')).toBe('"Service@Gemeente.nl"')
		expect(cellFromValue('clientSegment', 'say "hi"')).toBe('"say hi"')
		expect(cellFromValue('channel', '')).toBe('-')
		expect(valueFromCell('-')).toBe('')
		expect(valueFromCell('')).toBe('')
		expect(valueFromCell('"sms"')).toBe('sms')
		expect(valueFromCell('in (a,b)')).toBe('in (a,b)')
	})

	it('falls back to normal for an unknown priority', () => {
		const table = tableFromRows([{ ...kadaster, priority: 'asap' }])

		expect(table.rules[0].outputEntries).toEqual(['Registratie', 'normal'])
	})

	it('names a row without a condition or a category', () => {
		expect(rowProblems([kadaster])).toEqual([])
		expect(rowProblems([{ ...emptyRow(), category: 'Alles' }, { ...emptyRow(), channel: 'sms' }])).toEqual([
			{ row: 1, problem: 'no-condition' },
			{ row: 2, problem: 'no-category' },
		])
	})

	it('finds the shipped flow and its table, and replaces only the table', () => {
		expect(findRoutingFlow([{ name: 'Other', app: 'pipelinq' }, flow])).toBe(flow)
		expect(findRoutingFlow([{ name: 'Route incoming tickets', app: 'other' }])).toBeNull()
		expect(tableOf(flow)).toBe(shippedTable)

		const nodes = nodesWithRows(flow, [kadaster])
		expect(nodes[0]).toBe(flow.nodes[0])
		expect(nodes[1].config.resultKey).toBe('channelMetadata.routing')
		expect(nodes[1].config.table.rules).toHaveLength(2)
		expect(flow.nodes[1].config.table.rules).toHaveLength(1)
	})

	it('moves a row up and down, and not past either end', () => {
		const rows = [kadaster, whatsapp]

		expect(moveRow(rows, 1, -1)).toEqual([whatsapp, kadaster])
		expect(moveRow(rows, 0, 1)).toEqual([whatsapp, kadaster])
		expect(moveRow(rows, 0, -1)).toBe(rows)
		expect(moveRow(rows, 1, 1)).toBe(rows)
	})
})

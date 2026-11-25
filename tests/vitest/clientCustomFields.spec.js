/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A field an administrator adds to the client schema in OpenRegister shows on
 * the client form and the client page without a release of pipelinq: every
 * runtime schema property pipelinq does not ship is offered as an added field.
 *
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	customClientFields,
	customFieldRows,
	customFieldValuesOf,
	customFieldValuesToSave,
	SHIPPED_CLIENT_PROPERTIES,
} from '../../src/services/clientCustomFields.js'

const SETTINGS = join(__dirname, '../../lib/Settings')

/**
 * @return {string[]} Every client property the app's register files declare.
 */
function shippedByTheRegisterFiles() {
	const files = [join(SETTINGS, 'pipelinq_register.json')]
	for (const name of readdirSync(join(SETTINGS, 'register.d')).filter((f) => f.endsWith('.json'))) {
		files.push(join(SETTINGS, 'register.d', name))
	}
	const keys = new Set()
	for (const file of files) {
		const client = JSON.parse(readFileSync(file, 'utf8'))?.components?.schemas?.client
		for (const key of Object.keys(client?.properties ?? {})) {
			keys.add(key)
		}
	}
	return [...keys].sort()
}

describe('the client properties pipelinq ships', () => {
	it('are exactly the ones its register files declare, so a shipped field is never offered as an added one', () => {
		expect([...SHIPPED_CLIENT_PROPERTIES].sort()).toEqual(shippedByTheRegisterFiles())
	})
})

describe('customClientFields', () => {
	const schema = {
		properties: {
			name: { type: 'string', title: 'Name' },
			email: { type: 'string' },
			contractNumber: { type: 'string', title: 'Contract number', description: 'The framework contract.' },
			employees: { type: 'integer', title: 'Employees' },
			vip: { type: 'boolean' },
			tier: { type: 'string', title: 'Tier', enum: ['gold', 'silver'] },
			reviewOn: { type: 'string', format: 'date', title: 'Review on' },
			computed: { type: 'string', readOnly: true },
			hiddenLink: { type: 'string', visible: false },
			addresses: { type: 'array', items: { type: 'string' } },
			profile: { type: 'object' },
			_internal: { type: 'string' },
		},
	}

	it('offers every added single-value property in schema order, typed, and nothing pipelinq ships', () => {
		expect(customClientFields(schema)).toEqual([
			{ key: 'contractNumber', label: 'Contract number', kind: 'text', options: [], description: 'The framework contract.' },
			{ key: 'employees', label: 'Employees', kind: 'number', options: [], description: '' },
			{ key: 'vip', label: 'vip', kind: 'boolean', options: [], description: '' },
			{ key: 'tier', label: 'Tier', kind: 'choice', options: ['gold', 'silver'], description: '' },
			{ key: 'reviewOn', label: 'Review on', kind: 'date', options: [], description: '' },
		])
	})

	it('offers nothing without a schema', () => {
		expect(customClientFields(null)).toEqual([])
		expect(customClientFields({})).toEqual([])
	})
})

describe('saving and showing the added fields', () => {
	const fields = [
		{ key: 'contractNumber', label: 'Contract number', kind: 'text' },
		{ key: 'employees', label: 'Employees', kind: 'number' },
		{ key: 'vip', label: 'VIP', kind: 'boolean' },
	]

	it('sends what was filled in, numbers as numbers, and leaves the rest out on a new client', () => {
		expect(customFieldValuesToSave(fields, { contractNumber: 'RC-1', employees: '12', vip: false }, false))
			.toEqual({ contractNumber: 'RC-1', employees: 12, vip: false })
		expect(customFieldValuesToSave(fields, { contractNumber: ' ', employees: 'many' }, false)).toEqual({})
	})

	it('clears an emptied field on an existing client', () => {
		expect(customFieldValuesToSave(fields, { contractNumber: '' }, true)).toEqual({ contractNumber: null, employees: null, vip: null })
	})

	it('starts the form from the stored values', () => {
		expect(customFieldValuesOf(fields, { contractNumber: 'RC-1', employees: 12 })).toEqual({ contractNumber: 'RC-1', employees: '12', vip: false })
		expect(customFieldValuesOf(fields, null)).toEqual({ contractNumber: '', employees: '', vip: false })
	})

	it('shows the fields that hold a value, a yes/no in words', () => {
		const t = (text) => ({ Yes: 'Ja', No: 'Nee' })[text]
		expect(customFieldRows(fields, { contractNumber: 'RC-1', vip: true }, t)).toEqual([
			{ key: 'contractNumber', label: 'Contract number', value: 'RC-1' },
			{ key: 'vip', label: 'VIP', value: 'Ja' },
		])
		expect(customFieldRows(fields, { employees: 0, vip: false }, t)).toEqual([
			{ key: 'employees', label: 'Employees', value: '0' },
			{ key: 'vip', label: 'VIP', value: 'Nee' },
		])
		expect(customFieldRows(fields, {}, t)).toEqual([])
	})
})

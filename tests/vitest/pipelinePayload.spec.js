// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The pipeline form sends what the real pipeline schema accepts
 * (openspec/changes/review-finish, pipelinq review R5). The schema fragment
 * is read from lib/Settings/pipelinq_register.json, not copied.
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import {
	pipelineMappingsPayload,
	pipelineStagesPayload,
} from '../../src/services/pipelinePayload.js'

const register = JSON.parse(
	readFileSync(
		resolve(__dirname, '../../lib/Settings/pipelinq_register.json'),
		'utf8',
	),
)
const schema = register.components.schemas.pipeline.properties

function typeOk(value, type) {
	return (
		{
			string: typeof value === 'string',
			integer: Number.isInteger(value),
			boolean: typeof value === 'boolean',
			null: value === null,
		}[type] ?? true
	)
}

function violations(rows, itemSchema) {
	return rows.flatMap((row, i) =>
		Object.entries(row)
			.filter(
				([key, value]) =>
					itemSchema.properties[key]
					&& !typeOk(value, itemSchema.properties[key].type),
			)
			.map(([key, value]) => `${i}.${key} ${JSON.stringify(value)}`),
	)
}

describe('pipeline form payload', () => {
	it('leaves an empty totals property out instead of sending null', () => {
		const out = pipelineMappingsPayload([
			{ schemaSlug: 'lead', columnProperty: 'stage', totalsProperty: 'value' },
			{ schemaSlug: 'request', columnProperty: 'stage', totalsProperty: null },
			{ schemaSlug: 'ticket', columnProperty: 'status', totalsProperty: '' },
		])
		expect(out[0].totalsProperty).toBe('value')
		expect('totalsProperty' in out[1]).toBe(false)
		expect('totalsProperty' in out[2]).toBe(false)
		expect(violations(out, schema.propertyMappings.items)).toEqual([])
	})

	it('sends stage probabilities as integers and drops empty ones', () => {
		const out = pipelineStagesPayload([
			{ name: 'New', order: 1, probability: '10', color: '#000' },
			{ name: 'Lost', order: 2, probability: null, isClosed: true },
		])
		expect(out[0].probability).toBe(10)
		expect('probability' in out[1]).toBe(false)
		expect('color' in out[1]).toBe(false)
		expect(violations(out, schema.stages.items)).toEqual([])
	})
})

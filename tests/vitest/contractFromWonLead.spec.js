/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A won lead can be turned into a numbered contract: the lead page offers
 * Create contract once the lead is won, posting the lead's title, client and
 * value to the contract endpoint (which numbers it), and the contract names
 * the lead it was made from.
 *
 * @spec openspec/changes/specify-capability-rows/specs/contract-renewal-tracking/spec.md#requirement-a-won-lead-can-be-turned-into-a-numbered-contract
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

function read(file) {
	return JSON.parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
}

const manifest = read('src/manifest.json')
const fragment = read('lib/Settings/register.d/99-zz-sales-contract-source-lead.json')
const en = read('l10n/en.json').translations
const nl = read('l10n/nl.json').translations

const leadDetail = manifest.pages.find((p) => p.id === 'LeadDetail')
const action = leadDetail.config.headerActions.find((a) => a.id === 'create-contract')

describe('Create contract on a won lead', () => {
	it('shows only on a won lead', () => {
		expect(action.visibleWhen).toEqual({ field: 'status', op: 'eq', value: 'won' })
	})

	it('posts the lead to the numbering contract endpoint', () => {
		expect(action.type).toBe('api-call')
		expect(action.method).toBe('POST')
		expect(action.url).toBe('/apps/pipelinq/api/contracts')
		expect(action.payload).toEqual({
			title: '@object.title',
			clientRef: '@object.client',
			value: '@object.value',
			sourceLeadRef: '@objectId',
		})
	})

	it('lets the contract name the lead it was made from', () => {
		const contract = fragment.components.schemas.salesContract
		expect(contract.version).toBe('1.4.0')
		expect(contract.properties.sourceLeadRef).toMatchObject({ type: 'string', format: 'uuid', $ref: 'lead' })
	})

	it.each([action.label, action.confirmTitle, action.confirmMessage, action.successMessage, action.errorMessage, 'Source lead'])('translates "%s"', (key) => {
		expect(en[key]).toBe(key)
		expect(typeof nl[key]).toBe('string')
		expect(nl[key]).not.toBe('')
	})
})

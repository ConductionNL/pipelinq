/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Phone on the road (platform-phone-on-the-road): the tap links and the
 * visit note.
 *
 * The visit payloads are validated against the REAL `ticket` and `crmTask`
 * schemas, assembled the way OpenRegister merges them: every register file
 * under lib/Settings that declares the schema adds its properties, and the
 * `required` list comes from the files that set one. A field the schema does
 * not know, a wrong enum value or a date where a date-time belongs fails here
 * instead of on a live save.
 *
 * @spec openspec/specs/mobile-experience/spec.md#requirement-a-visit-is-logged-in-one-small-sheet-req-mob-003
 */

import Ajv from 'ajv'
import addFormats from 'ajv-formats'
import { readdirSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { mailtoHref, mapHref, telHref } from '../../src/services/contactLinks.js'
import { buildVisitPayloads, followUpDeadline } from '../../src/services/visitLog.js'

const SETTINGS = resolve(__dirname, '../../lib/Settings')

/**
 * The merged JSON schema of one register schema, `$ref` and `x-` keys
 * dropped (they point at other schemas, not at value shapes).
 *
 * @param {string} name Schema key, e.g. `ticket`.
 * @return {object}
 */
function mergedSchema(name) {
	const files = [
		resolve(SETTINGS, 'pipelinq_register.json'),
		...readdirSync(resolve(SETTINGS, 'register.d'))
			.filter((f) => f.endsWith('.json'))
			.sort()
			.map((f) => resolve(SETTINGS, 'register.d', f)),
	]
	const properties = {}
	let required = []
	for (const file of files) {
		const schema = JSON.parse(readFileSync(file, 'utf8')).components?.schemas?.[
			name
		]
		if (!schema) continue
		for (const [key, def] of Object.entries(schema.properties || {})) {
			const clean = {}
			for (const k of [
				'type',
				'enum',
				'format',
				'minimum',
				'maximum',
				'maxLength',
				'items',
			]) {
				if (def[k] !== undefined) clean[k] = def[k]
			}
			if (clean.items) {
				clean.items = { type: clean.items.type }
			}
			properties[key] = { ...properties[key], ...clean }
		}
		if (Array.isArray(schema.required)) required = schema.required
	}
	return { type: 'object', properties, required, additionalProperties: false }
}

const ajv = new Ajv({ allErrors: true, strict: false })
addFormats(ajv)
const validateTicket = ajv.compile(mergedSchema('ticket'))
const validateTask = ajv.compile(mergedSchema('crmTask'))

const CLIENT = '6f1c1d2e-3b4a-4c5d-8e9f-0a1b2c3d4e5f'
const LEAD = '7a2b3c4d-5e6f-4a1b-9c2d-3e4f5a6b7c8d'

describe('tap links', () => {
	it.each([
		['06 12 34 56 78', 'tel:0612345678'],
		['+31 6-1234 5678', 'tel:+31612345678'],
		['0031 20 123 4567', 'tel:+31201234567'],
		['020/123.45.67', 'tel:0201234567'],
	])('dials %s as %s', (typed, href) => {
		expect(telHref(typed)).toBe(href)
	})

	it.each([null, '', 'n.v.t.'])('makes no call link for %s', (typed) => {
		expect(telHref(typed)).toBeNull()
	})

	it('mails an address and ignores something that is not one', () => {
		expect(mailtoHref(' info@bakkerijdejong.nl ')).toBe(
			'mailto:info@bakkerijdejong.nl',
		)
		expect(mailtoHref('bakkerij')).toBeNull()
	})

	it('hands an address to the map app, one line per comma', () => {
		expect(mapHref('Dorpsstraat 1\n1234 AB Utrecht')).toBe(
			'geo:0,0?q=' + encodeURIComponent('Dorpsstraat 1, 1234 AB Utrecht'),
		)
		expect(mapHref('   ')).toBeNull()
	})
})

describe('the visit payloads', () => {
	const base = {
		note: 'wants a quote for two ovens',
		title: 'Visit',
		taskSubject: 'Follow up on the visit',
		userId: 'pieter',
		now: new Date('2026-09-29T10:00:00Z'),
	}

	it('writes an outbound visit contact moment the ticket schema accepts', () => {
		const { ticket, task } = buildVisitPayloads({ ...base, clientId: CLIENT })
		expect(validateTicket(ticket), JSON.stringify(validateTicket.errors)).toBe(
			true,
		)
		expect(ticket).toMatchObject({
			ticketType: 'interaction',
			channel: 'visit',
			direction: 'outbound',
			client: CLIENT,
			description: 'wants a quote for two ovens',
		})
		expect(task).toBeNull()
	})

	it('adds a follow-up task the crmTask schema accepts, due on the picked day', () => {
		const { ticket, task } = buildVisitPayloads({
			...base,
			clientId: CLIENT,
			leadId: LEAD,
			followUpDate: '2026-10-02',
		})
		expect(validateTicket(ticket), JSON.stringify(validateTicket.errors)).toBe(
			true,
		)
		expect(validateTask(task), JSON.stringify(validateTask.errors)).toBe(true)
		expect(task).toMatchObject({
			type: 'followUpTask',
			status: 'open',
			clientId: CLIENT,
			lead: LEAD,
		})
		const due = new Date(task.deadline)
		expect([due.getFullYear(), due.getMonth() + 1, due.getDate()]).toEqual([
			2026, 10, 2,
		])
	})

	it('reads an empty or broken follow-up date as none', () => {
		expect(followUpDeadline('')).toBeNull()
		expect(followUpDeadline('02-10-2026')).toBeNull()
	})
})

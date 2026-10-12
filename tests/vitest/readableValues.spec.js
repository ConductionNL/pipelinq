// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Client, lead and service values read as words, and the client page speaks
 * the interface language.
 *
 * The cloud check of 9 October 2026 found stored codes on screen: client
 * Type "person"/"organization", lead Status "open" and Priority "normal",
 * and on the service page "free" (cancellation policy) and "staff"
 * (resource type). It also found a Dutch "Contactpersoon toevoegen" button
 * on the English client page.
 *
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/client-management/spec.md
 * @spec openspec/changes/round4-readable-values-and-tour-titles/specs/appointment-booking/spec.md
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'
import {
	CANCELLATION_POLICY_LABELS,
	CLIENT_TYPE_LABELS,
	enumLabel,
	enumOptions,
	LEAD_PRIORITY_LABELS,
	RESOURCE_TYPE_LABELS,
} from '../../src/utils/enumLabels.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

/**
 * Deep-merge like ConfigFileLoaderService::deepMergeConfig: objects merge
 * key by key, everything else (lists included) is replaced.
 *
 * @param {object} base The base.
 * @param {object} override The fragment.
 * @return {object} The base, merged in place.
 */
function merge(base, override) {
	const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v)
	for (const [key, value] of Object.entries(override)) {
		if (isObject(value) && isObject(base[key])) {
			merge(base[key], value)
		} else {
			base[key] = value
		}
	}
	return base
}

function mergedRegister() {
	const register = readJson('lib', 'Settings', 'pipelinq_register.json')
	fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
		.filter((name) => name.endsWith('.json'))
		.sort()
		.forEach((name) =>
			merge(register, readJson('lib', 'Settings', 'register.d', name)),
		)
	return register
}

/**
 * Every enum nextcloud-vue renders from a schema: properties and the items of
 * a list of values, with the path that reaches it. Fields inside a list of
 * objects (contact channels, working hours, a service's steps) are drawn by
 * pipelinq's own editors; the step resource type the service page prints is
 * checked on its own below.
 *
 * @param {object} properties A properties map.
 * @param {string} prefix The path so far.
 * @return {Array<{path: string, values: Array, labels: object}>} The enums.
 */
function enums(properties, prefix = '') {
	const found = []
	for (const [key, prop] of Object.entries(properties || {})) {
		const at = prefix + key
		if (Array.isArray(prop.enum)) {
			found.push({
				path: at,
				values: prop.enum,
				labels: prop['x-enum-labels'],
			})
		}
		if (prop.items && Array.isArray(prop.items.enum)) {
			found.push({
				path: at + '[]',
				values: prop.items.enum,
				labels: prop['x-enum-labels'],
			})
			found.push({
				path: at + '[] (items)',
				values: prop.items.enum,
				labels: prop.items['x-enum-labels'],
			})
		}
	}
	return found
}

const en = readJson('l10n', 'en.json').translations
const nl = readJson('l10n', 'nl.json').translations
const schemas = mergedRegister().components.schemas

describe('enums on the client, lead and service pages', () => {
	for (const schema of [
		'client',
		'lead',
		'appointmentService',
		'appointmentResource',
	]) {
		const found = enums(schemas[schema].properties)

		it(`${schema} has the enums the pages show`, () => {
			// The control: an empty list would make the loop below vacuous.
			expect(found.length).toBeGreaterThan(0)
		})

		for (const { path: at, values, labels } of found) {
			it(`labels every ${schema}.${at} value, in English and Dutch`, () => {
				for (const value of values) {
					const label = (labels || {})[value]
					expect(label, `${at}.${value}`).toBeTruthy()
					expect(label, `${at}.${value}`).not.toBe(value)
					expect(en[label], label).toBe(label)
					expect(nl[label], label).toBeTruthy()
				}
			})
		}
	}

	it('names the values the cloud check saw', () => {
		const p = (schema, key) => schemas[schema].properties[key]['x-enum-labels']
		expect(p('client', 'type')).toMatchObject({
			person: 'Person',
			organization: 'Organisation',
		})
		expect(p('lead', 'status').open).toBe('Open')
		expect(p('lead', 'priority').normal).toBe('Normal')
		expect(p('appointmentService', 'cancellationPolicy').free).toBe('Free')
		expect(
			schemas.appointmentService.properties.multiStep.items.properties
				.resourceType['x-enum-labels'].staff,
		).toBe('Staff')
	})
})

describe('the hand-written forms and the service page use the same labels', () => {
	const cases = [
		[CLIENT_TYPE_LABELS, schemas.client.properties.type],
		[LEAD_PRIORITY_LABELS, schemas.lead.properties.priority],
		[
			CANCELLATION_POLICY_LABELS,
			schemas.appointmentService.properties.cancellationPolicy,
		],
		[RESOURCE_TYPE_LABELS, schemas.appointmentResource.properties.type],
		[
			RESOURCE_TYPE_LABELS,
			schemas.appointmentService.properties.multiStep.items.properties
				.resourceType,
		],
	]
	it('matches the register labels, value for value', () => {
		for (const [map, prop] of cases) {
			expect(Object.keys(map).sort()).toEqual([...prop.enum].sort())
			expect(map).toEqual(prop['x-enum-labels'])
		}
	})

	it('turns a stored value into its translated label', () => {
		const translate = (text) => nl[text] || text
		expect(enumLabel(CANCELLATION_POLICY_LABELS, 'free')).toBe('Free')
		expect(enumLabel(RESOURCE_TYPE_LABELS, 'staff', translate)).toBe(
			'Medewerkers',
		)
		expect(enumLabel(RESOURCE_TYPE_LABELS, 'unknown')).toBe('unknown')
		expect(enumLabel(RESOURCE_TYPE_LABELS, '')).toBe('')
		expect(enumOptions(CLIENT_TYPE_LABELS)).toEqual([
			{ value: 'person', label: 'Person' },
			{ value: 'organization', label: 'Organisation' },
		])
	})

	it('no longer prints the stored code on the service page or in the forms', () => {
		const read = (...parts) => fs.readFileSync(path.join(ROOT, ...parts), 'utf8')
		const detail = read('src', 'views', 'bookings', 'ServiceDetail.vue')
		expect(detail).not.toContain("serviceData.cancellationPolicy || 'free' }}")
		expect(detail).not.toContain("{{ step.resourceType || '-' }}")
		expect(read('src', 'views', 'clients', 'ClientForm.vue')).not.toContain(
			"typeOptions: ['person', 'organization']",
		)
		expect(read('src', 'views', 'leads', 'LeadForm.vue')).not.toContain(
			"priorityOptions: ['low', 'normal', 'high', 'urgent']",
		)
	})
})

describe('object-list Add buttons', () => {
	const manifest = readJson('src', 'manifest.json')
	const labels = []
	JSON.stringify(manifest, (key, value) => {
		// ContactAwareObjectList extends the library widget and draws the same button.
		const listTypes = ['object-list', 'ContactAwareObjectList']
		if (value && listTypes.includes(value.type) && value.content?.addLabel) {
			labels.push(value.content.addLabel)
		}
		return value
	})

	it('are written in English with a Dutch translation', () => {
		expect(labels).toContain('Add contact person')
		expect(labels).not.toContain('Contactpersoon toevoegen')
		for (const label of labels) {
			expect(en[label], label).toBe(label)
			expect(nl[label], label).toBeTruthy()
		}
		expect(nl['Add contact person']).toBe('Contactpersoon toevoegen')
	})

	// @spec openspec/changes/round4-nextcloud-vue-2-76/specs/client-management/spec.md
	it('are not translated a second time by pipelinq', () => {
		const main = fs.readFileSync(path.join(ROOT, 'src', 'main.js'), 'utf8')
		expect(main).not.toContain('translateWidgetAddLabels')
		expect(
			fs.existsSync(path.join(ROOT, 'src', 'utils', 'widgetAddLabels.js')),
		).toBe(false)
		// Were a Dutch label also a catalogue key, a second pass would change it.
		for (const label of labels) {
			expect(nl[nl[label]], label).toBeUndefined()
		}
	})
})

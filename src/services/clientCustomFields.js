// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * A field an administrator adds to the client schema in OpenRegister shows on
 * the client pages without a release of pipelinq. pipelinq draws its own client
 * properties in their own places; every other property the schema carries at
 * runtime is the organisation's own and is offered under "Other details".
 *
 * @module services/clientCustomFields
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */

/**
 * The client properties pipelinq itself ships (lib/Settings register files).
 * tests/vitest/clientCustomFields.spec.js keeps this list equal to them.
 *
 * @type {string[]}
 */
export const SHIPPED_CLIENT_PROPERTIES = [
	'accountOwner',
	'accountStatus',
	'address',
	'churnRisk',
	'contactsUid',
	'correspondenceLanguage',
	'country',
	'customerNumber',
	'description',
	'email',
	'emails',
	'fieldValues',
	'firstTouch',
	'identifiers',
	'identityCheck',
	'identityVerifiedAt',
	'identityVerifiedVia',
	'industry',
	'isMasterRecord',
	'kvkNumber',
	'labels',
	'language',
	'lastTouch',
	'legalForm',
	'lifecycleStage',
	'masterEntityRef',
	'mergeMovedRows',
	'mergedAt',
	'mergedInto',
	'mergedIntoLabel',
	'municipality',
	'name',
	'notes',
	'organisationPath',
	'parentOrganisation',
	'partyKind',
	'phone',
	'phones',
	'place',
	'preferredChannel',
	'preferredContactTime',
	'preferredName',
	'priceList',
	'relationshipEndedAt',
	'salutation',
	'segment',
	'shillinqOrganisationRef',
	'socialProfiles',
	'source',
	'timezone',
	'type',
	'website',
]

const SHIPPED = new Set(SHIPPED_CLIENT_PROPERTIES)

const KIND_BY_TYPE = { boolean: 'boolean', number: 'number', integer: 'number' }
const DATE_FORMATS = new Set(['date', 'date-time'])

/**
 * The input a property's type asks for, or null for a shape the form does not edit.
 *
 * @param {object} property The schema property.
 * @return {string|null} text, number, boolean, choice or date; null for objects and lists.
 */
function kindOf(property) {
	if (Array.isArray(property.enum) && property.enum.length > 0) {
		return 'choice'
	}
	if (property.type === 'string') {
		return DATE_FORMATS.has(property.format) ? 'date' : 'text'
	}
	return KIND_BY_TYPE[property.type] ?? null
}

/**
 * @param {string|number|boolean|null|undefined} value A value typed into the form.
 * @return {boolean} Whether nothing was filled in.
 */
function isUnfilled(value) {
	return value === undefined || value === null || (typeof value === 'string' && value.trim() === '')
}

/**
 * The value to store for a filled-in field: a number for a number field, or
 * undefined when the text is not one.
 *
 * @param {{kind: string}} field The field.
 * @param {string|number|boolean} value The filled-in value.
 * @return {string|number|boolean|undefined} The value to store.
 */
function storedValue(field, value) {
	if (field.kind !== 'number') {
		return value
	}
	const number = Number(value)
	return Number.isNaN(number) ? undefined : number
}

/**
 * Whether a schema property is one a person reads and fills in.
 *
 * @param {string} key The property name.
 * @param {object} property The schema property.
 * @return {boolean} True for a property the form and the page offer.
 */
function isOffered(key, property) {
	if (SHIPPED.has(key) || key.startsWith('_') || key.startsWith('@')) {
		return false
	}
	if (!property || typeof property !== 'object') {
		return false
	}
	return property.readOnly !== true && property.visible !== false && property.hidden !== true
}

/**
 * The fields an administrator added to the client schema: every property the
 * schema carries that pipelinq does not ship, in schema order, that a person
 * can read and write and that is a single value.
 *
 * @param {object|null} schema The client schema as OpenRegister serves it.
 * @return {Array<{key: string, label: string, kind: string, options: string[], description: string}>} The fields.
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
export function customClientFields(schema) {
	const properties = (schema && typeof schema.properties === 'object' && schema.properties !== null) ? schema.properties : {}
	const fields = []
	for (const [key, property] of Object.entries(properties)) {
		const kind = isOffered(key, property) ? kindOf(property) : null
		if (kind === null) {
			continue
		}
		fields.push({
			key,
			label: (typeof property.title === 'string' && property.title.trim() !== '') ? property.title : key,
			kind,
			options: kind === 'choice' ? property.enum.map(String) : [],
			description: typeof property.description === 'string' ? property.description : '',
		})
	}
	return fields
}

/**
 * The values of the added fields to save: the ones filled in, numbers as
 * numbers. An emptied field on an existing client is saved as null, so the
 * old value goes.
 *
 * @param {Array<{key: string, kind: string}>} fields The added fields.
 * @param {object} values The values typed into the form, by key.
 * @param {boolean} existing Whether the client already exists.
 * @return {object} The values to send, by key.
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
export function customFieldValuesToSave(fields, values, existing) {
	const out = {}
	for (const field of (Array.isArray(fields) ? fields : [])) {
		const value = values ? values[field.key] : undefined
		const stored = isUnfilled(value) ? (existing ? null : undefined) : storedValue(field, value)
		if (stored !== undefined) {
			out[field.key] = stored
		}
	}
	return out
}

/**
 * The added fields' values of a stored client, as the form starts with them.
 *
 * @param {Array<{key: string, kind: string}>} fields The added fields.
 * @param {object|null} client The stored client.
 * @return {object} The values, by key (false for an unset yes/no, '' otherwise).
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
export function customFieldValuesOf(fields, client) {
	const values = {}
	for (const field of (Array.isArray(fields) ? fields : [])) {
		const value = client ? client[field.key] : undefined
		if (field.kind === 'boolean') {
			values[field.key] = value === true
			continue
		}
		values[field.key] = (value === undefined || value === null) ? '' : String(value)
	}
	return values
}

/**
 * The added fields of a stored client that hold a value, as label and text.
 *
 * @param {Array<{key: string, label: string, kind: string}>} fields The added fields.
 * @param {object|null} client The stored client.
 * @param {(text: string) => string} translateFn Translates Yes and No.
 * @return {Array<{key: string, label: string, value: string}>} The rows to show.
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
export function customFieldRows(fields, client, translateFn) {
	const rows = []
	for (const field of (Array.isArray(fields) ? fields : [])) {
		const value = client ? client[field.key] : undefined
		if (value === undefined || value === null || value === '') {
			continue
		}
		let text = String(value)
		if (field.kind === 'boolean') {
			text = value ? translateFn('Yes') : translateFn('No')
		}
		rows.push({ key: field.key, label: field.label, value: text })
	}
	return rows
}

// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// The operators the segment editor offers per field. The names are
// SegmentService::OPERATOR_TYPE_MATRIX's own (lib/Service/SegmentService.php):
// they travel to the API unchanged, and a name the service does not know is
// rejected. Labels are English source strings, translated by the caller.
//
// @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#requirement-a-segment-groups-its-rules

const EQUALITY = [
	{ value: 'equals', label: 'Equals' },
	{ value: 'notEquals', label: 'Not equals' },
]

const NUMERIC = [
	...EQUALITY,
	{ value: 'greaterThan', label: 'Greater than' },
	{ value: 'greaterThanOrEqual', label: 'Greater than or equal' },
	{ value: 'lessThan', label: 'Less than' },
	{ value: 'lessThanOrEqual', label: 'Less than or equal' },
]

/**
 * Operators per field type.
 *
 * @type {Object<string, Array<{value: string, label: string}>>}
 */
export const OPERATORS_BY_TYPE = {
	string: [...EQUALITY, { value: 'contains', label: 'Contains' }],
	integer: NUMERIC,
	number: NUMERIC,
	boolean: [
		{ value: 'equals', label: 'Is' },
		{ value: 'notEquals', label: 'Is not' },
	],
	array: [{ value: 'contains', label: 'Contains' }],
}

/**
 * Dates are stored as ISO strings, so a date-formatted string field also
 * takes before and after.
 *
 * @type {Array<{value: string, label: string}>}
 */
export const DATE_OPERATORS = [
	{ value: 'before', label: 'Before' },
	{ value: 'after', label: 'After' },
]

/**
 * The operators of a field: those of its type (text for an unknown type),
 * plus before and after for a date.
 *
 * @param {{type?: string, format?: string}|undefined} field The field option.
 * @return {Array<{value: string, label: string}>} The operators.
 *
 * @spec openspec/changes/segment-groups-its-rules/specs/marketing-ui/spec.md#requirement-a-segment-groups-its-rules
 */
export function segmentOperatorsFor(field) {
	const base = OPERATORS_BY_TYPE[field?.type] || OPERATORS_BY_TYPE.string
	return field?.format === 'date' ? [...base, ...DATE_OPERATORS] : [...base]
}

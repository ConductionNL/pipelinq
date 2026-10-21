/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * A service's composition steps: units, labels and the amount a step shows.
 *
 * A step can name a product with a quantity and a unit ("8 hours
 * Implementation", "1 months Hosting"). The schema stores the unit as one of
 * STEP_UNITS (register.d/45-appointment-booking.json).
 *
 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
 */

import { translate as t } from '@nextcloud/l10n'

/** The units a step quantity can be in, as stored. */
export const STEP_UNITS = ['hour', 'day', 'month', 'year', 'piece']

/**
 * The label for a stored unit.
 *
 * @param {string} unit The stored unit.
 * @return {string} The label, or the raw value for an unknown unit.
 *
 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
 */
export function unitLabel(unit) {
	const labels = {
		hour: t('pipelinq', 'hours'),
		day: t('pipelinq', 'days'),
		month: t('pipelinq', 'months'),
		year: t('pipelinq', 'years'),
		piece: t('pipelinq', 'pieces'),
	}
	return labels[unit] || unit || ''
}

/**
 * The quantity and unit of a step as one short text, or '' without a quantity.
 *
 * @param {{quantity?: number|null, unit?: string}} step The step.
 * @return {string} For example "8 hours".
 *
 * @spec openspec/changes/booking-and-service-pages/specs/appointment-booking/spec.md
 */
export function stepAmount(step) {
	const quantity = step?.quantity
	if (quantity === null || quantity === undefined || quantity === '') {
		return ''
	}
	return [String(quantity), unitLabel(step.unit)].filter(Boolean).join(' ')
}

/**
 * A composition step as it is saved.
 *
 * The create form used to rebuild each step from duration, resource type,
 * skill and gap only, so the product, quantity and unit a user picked were
 * lost on a new service and the composition showed "-" (round3-review-points,
 * review point 3). Product, quantity and unit are kept when set.
 *
 * @param {object} step The step from the editor.
 * @return {object} The step for the schema.
 *
 * @spec openspec/changes/round3-review-points/specs/appointment-booking/spec.md
 */
export function serializeStep(step) {
	const source = step || {}
	const saved = {
		durationMinutes: Number(source.durationMinutes) || 0,
		resourceType: source.resourceType || 'staff',
		skillRequired: String(source.skillRequired || '').trim(),
		allowGap: !!source.allowGap,
	}
	if (source.productId) {
		saved.productId = String(source.productId)
	}
	const quantity = Number(source.quantity)
	if (
		source.quantity !== null
		&& source.quantity !== undefined
		&& source.quantity !== ''
		&& Number.isFinite(quantity)
	) {
		saved.quantity = quantity
	}
	if (STEP_UNITS.includes(source.unit)) {
		saved.unit = source.unit
	}
	return saved
}

/**
 * How a service form edits its composition: rows of the library row editor
 * (CnSubObjectsField, a dialog per step), the product picked from the
 * catalogue, and a line that adds the step minutes up against the service
 * duration (REQ-APT-001 "duration sums to multi-step total").
 *
 * @return {object} The `multiStep` field override, translated.
 * @spec openspec/changes/pl2-booking-row-editors/specs/appointment-booking/spec.md#requirement-booking-rows-are-edited-with-the-library-row-editor
 */
export function serviceStepsOverride() {
	return {
		label: t('pipelinq', 'Multi-step composition'),
		order: 10,
		widget: 'sub-objects',
		itemLabel: t('pipelinq', 'Step'),
		addLabel: t('pipelinq', 'Add step'),
		emptyText: t('pipelinq', 'Single-step service. Add steps to split it across resources.'),
		newItem: { durationMinutes: 0, resourceType: 'staff', allowGap: false },
		summary: t('pipelinq', '{sum:durationMinutes} of {@object.durationMinutes} min'),
		itemOverrides: {
			durationMinutes: { label: t('pipelinq', 'Duration (min)'), order: 1 },
			resourceType: {
				label: t('pipelinq', 'Resource type'),
				order: 2,
				enumLabels: Object.fromEntries(Object.entries({ staff: 'Staff', room: 'Room', equipment: 'Equipment' }).map(([value, label]) => [value, t('pipelinq', label)])),
			},
			skillRequired: { label: t('pipelinq', 'Skill required'), order: 3 },
			allowGap: { label: t('pipelinq', 'Allow gap'), order: 4 },
			productId: {
				label: t('pipelinq', 'Product'),
				order: 5,
				optionsFrom: { register: 'pipelinq', schema: 'product', labelField: 'name' },
			},
			quantity: { label: t('pipelinq', 'Quantity'), order: 6 },
			unit: {
				label: t('pipelinq', 'Unit'),
				order: 7,
				enumLabels: Object.fromEntries(STEP_UNITS.map((unit) => [unit, unitLabel(unit)])),
			},
		},
	}
}

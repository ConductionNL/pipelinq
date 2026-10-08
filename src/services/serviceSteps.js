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

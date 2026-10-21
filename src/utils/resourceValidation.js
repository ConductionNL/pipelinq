/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How a resource's workingHours and vacations are edited in its form: as rows
 * of the library row editor (CnSubObjectsField, cells edited in the row), with
 * the REQ-APT-002 rules "open time before close time" and "start on or before
 * end" as row rules, so the form names the row that breaks one.
 *
 * @spec openspec/changes/pl2-booking-row-editors/specs/appointment-booking/spec.md#requirement-booking-rows-are-edited-with-the-library-row-editor
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * The field overrides of a resource form's working hours and vacations.
 *
 * @return {{workingHours: object, vacations: object}} The overrides, translated.
 * @spec openspec/changes/pl2-booking-row-editors/specs/appointment-booking/spec.md#requirement-booking-rows-are-edited-with-the-library-row-editor
 */
export function resourceRowOverrides() {
	const days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']
	const dayLabels = {
		monday: t('pipelinq', 'Monday'),
		tuesday: t('pipelinq', 'Tuesday'),
		wednesday: t('pipelinq', 'Wednesday'),
		thursday: t('pipelinq', 'Thursday'),
		friday: t('pipelinq', 'Friday'),
		saturday: t('pipelinq', 'Saturday'),
		sunday: t('pipelinq', 'Sunday'),
	}
	return {
		workingHours: {
			label: t('pipelinq', 'Working hours'),
			order: 9,
			widget: 'sub-objects',
			inline: true,
			itemLabel: t('pipelinq', 'Working hours'),
			addLabel: t('pipelinq', 'Add working hours'),
			emptyText: t('pipelinq', 'No working hours yet. The resource is unavailable on days without a row.'),
			newItem: { day: days[0], openTime: '09:00', closeTime: '17:00' },
			itemOverrides: {
				day: { label: t('pipelinq', 'Day'), enumLabels: dayLabels },
				openTime: { label: t('pipelinq', 'Opening time'), format: 'time' },
				closeTime: { label: t('pipelinq', 'Closing time'), format: 'time' },
			},
			rowRules: [{
				when: {
					all: [
						{ field: 'openTime', op: 'notEmpty' },
						{ field: 'closeTime', op: 'notEmpty' },
						{ field: 'openTime', op: 'gte', value: '@row.closeTime' },
					],
				},
				message: t('pipelinq', 'Open time must be before close time.'),
			}],
		},
		vacations: {
			label: t('pipelinq', 'Vacations / unavailable windows'),
			order: 10,
			widget: 'sub-objects',
			inline: true,
			itemLabel: t('pipelinq', 'Vacation'),
			addLabel: t('pipelinq', 'Add vacation'),
			emptyText: t('pipelinq', 'No vacations recorded.'),
			newItem: { startDate: '', endDate: '', label: '' },
			itemOverrides: {
				startDate: { label: t('pipelinq', 'Start date') },
				endDate: { label: t('pipelinq', 'End date') },
				label: { label: t('pipelinq', 'Label') },
			},
			rowRules: [{
				when: {
					all: [
						{ field: 'startDate', op: 'notEmpty' },
						{ field: 'endDate', op: 'notEmpty' },
						{ field: 'startDate', op: 'gt', value: '@row.endDate' },
					],
				},
				message: t('pipelinq', 'Vacation start date must be on or before the end date.'),
			}],
		},
	}
}

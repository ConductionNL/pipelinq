/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Row rules for a resource's workingHours and vacations (REQ-APT-002
 * "workingHours open<close, vacations start<=end"), shared by ResourceForm and
 * the resource edit dialog.
 *
 * Plain string comparison is safe: times are HH:MM and dates YYYY-MM-DD, both
 * enforced by the schema.
 *
 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * @param {Array<{openTime?: string, closeTime?: string}>} rows The working-hours rows.
 * @return {string} An error message, or '' when every row is valid.
 */
export function workingHoursError(rows) {
	const bad = (rows || []).find((r) => r.openTime && r.closeTime && r.openTime >= r.closeTime)
	return bad ? t('pipelinq', 'Open time must be before close time.') : ''
}

/**
 * @param {Array<{startDate?: string, endDate?: string}>} rows The vacation rows.
 * @return {string} An error message, or '' when every row is valid.
 */
export function vacationsError(rows) {
	const bad = (rows || []).find((r) => r.startDate && r.endDate && r.startDate > r.endDate)
	return bad ? t('pipelinq', 'Vacation start date must be on or before the end date.') : ''
}

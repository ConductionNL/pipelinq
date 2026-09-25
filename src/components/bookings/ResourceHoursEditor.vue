<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Editor for a resource's weekly workingHours, on BookingRowsEditor. Used by
  ResourceForm and by the resource edit dialog's `#field-workingHours` slot.

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<BookingRowsEditor
		:modelValue="rows"
		:title="t('pipelinq', 'Working hours')"
		:columns="columns"
		:newRow="newRow"
		:labels="labels"
		:emptyText="t('pipelinq', 'No working hours yet. The resource is unavailable on days without a row.')"
		:addLabel="t('pipelinq', 'Add working hours')"
		:message="error || ruleError"
		messageType="error"
		data-testid="resource-hours-editor"
		@update:modelValue="(v) => $emit('update:modelValue', v)">
		<template #cell-day="{ row, index, update }">
			<NcSelect
				:modelValue="row.day"
				:inputId="`resource-hours-day-${uid}-${index}`"
				:aria-label-combobox="t('pipelinq', 'Row {n} day', { n: index + 1 })"
				labelOutside
				:clearable="false"
				:options="dayOptions"
				:reduce="(o) => o.value"
				label="label"
				@update:modelValue="update" />
		</template>
		<template #cell-openTime="{ row, index, update }">
			<NcTextField
				:modelValue="row.openTime || ''"
				type="time"
				labelOutside
				:aria-label="t('pipelinq', 'Row {n} open time', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
		<template #cell-closeTime="{ row, index, update }">
			<NcTextField
				:modelValue="row.closeTime || ''"
				type="time"
				labelOutside
				:aria-label="t('pipelinq', 'Row {n} close time', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
	</BookingRowsEditor>
</template>

<script>
import { NcSelect, NcTextField } from '@nextcloud/vue'
import BookingRowsEditor from './BookingRowsEditor.vue'
import { workingHoursError } from '../../utils/resourceValidation.js'

let instanceCount = 0

export default {
	name: 'ResourceHoursEditor',
	components: { BookingRowsEditor, NcSelect, NcTextField },

	props: {
		/** The rows: `{ day, openTime, closeTime }[]`. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** An error from the host form, shown instead of the live rule check. */
		error: {
			type: String,
			default: '',
		},
	},

	emits: ['update:modelValue'],

	data() {
		instanceCount += 1
		return { uid: instanceCount }
	},

	computed: {
		rows() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		ruleError() {
			return workingHoursError(this.rows)
		},

		columns() {
			return [
				{ key: 'day', label: t('pipelinq', 'Day'), width: 'minmax(8rem, 1fr)' },
				{ key: 'openTime', label: t('pipelinq', 'Opening time'), width: 'minmax(6rem, 9rem)' },
				{ key: 'closeTime', label: t('pipelinq', 'Closing time'), width: 'minmax(6rem, 9rem)' },
			]
		},

		labels() {
			return {
				moveUp: () => '',
				moveDown: () => '',
				remove: (n) => t('pipelinq', 'Remove working hours row {n}', { n }),
			}
		},

		dayOptions() {
			return [
				{ value: 'monday', label: t('pipelinq', 'Monday') },
				{ value: 'tuesday', label: t('pipelinq', 'Tuesday') },
				{ value: 'wednesday', label: t('pipelinq', 'Wednesday') },
				{ value: 'thursday', label: t('pipelinq', 'Thursday') },
				{ value: 'friday', label: t('pipelinq', 'Friday') },
				{ value: 'saturday', label: t('pipelinq', 'Saturday') },
				{ value: 'sunday', label: t('pipelinq', 'Sunday') },
			]
		},
	},

	methods: {
		newRow() {
			return { day: 'monday', openTime: '09:00', closeTime: '17:00' }
		},
	},
}
</script>

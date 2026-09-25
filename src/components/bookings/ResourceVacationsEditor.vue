<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Editor for a resource's vacations (inclusive date ranges), on
  BookingRowsEditor. Used by ResourceForm and by the resource edit dialog's
  `#field-vacations` slot.

  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<BookingRowsEditor
		:modelValue="rows"
		:title="t('pipelinq', 'Vacations / unavailable windows')"
		:columns="columns"
		:newRow="newRow"
		:labels="labels"
		:emptyText="t('pipelinq', 'No vacations recorded.')"
		:addLabel="t('pipelinq', 'Add vacation')"
		:message="error || ruleError"
		messageType="error"
		data-testid="resource-vacations-editor"
		@update:modelValue="(v) => $emit('update:modelValue', v)">
		<template #cell-startDate="{ row, index, update }">
			<NcTextField
				:modelValue="row.startDate || ''"
				type="date"
				labelOutside
				:aria-label="t('pipelinq', 'Vacation {n} start date', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
		<template #cell-endDate="{ row, index, update }">
			<NcTextField
				:modelValue="row.endDate || ''"
				type="date"
				labelOutside
				:aria-label="t('pipelinq', 'Vacation {n} end date', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
		<template #cell-label="{ row, index, update }">
			<NcTextField
				:modelValue="row.label || ''"
				labelOutside
				:placeholder="t('pipelinq', 'Optional')"
				:aria-label="t('pipelinq', 'Vacation {n} label', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
	</BookingRowsEditor>
</template>

<script>
import { NcTextField } from '@nextcloud/vue'
import BookingRowsEditor from './BookingRowsEditor.vue'
import { vacationsError } from '../../utils/resourceValidation.js'

export default {
	name: 'ResourceVacationsEditor',
	components: { BookingRowsEditor, NcTextField },

	props: {
		/** The rows: `{ startDate, endDate, label }[]`. */
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

	computed: {
		rows() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		ruleError() {
			return vacationsError(this.rows)
		},

		columns() {
			return [
				{ key: 'startDate', label: t('pipelinq', 'Start date'), width: 'minmax(8rem, 11rem)' },
				{ key: 'endDate', label: t('pipelinq', 'End date'), width: 'minmax(8rem, 11rem)' },
				{ key: 'label', label: t('pipelinq', 'Label'), width: 'minmax(8rem, 1fr)' },
			]
		},

		labels() {
			return {
				moveUp: () => '',
				moveDown: () => '',
				remove: (n) => t('pipelinq', 'Remove vacation {n}', { n }),
			}
		},
	},

	methods: {
		newRow() {
			return { startDate: '', endDate: '', label: '' }
		},
	},
}
</script>

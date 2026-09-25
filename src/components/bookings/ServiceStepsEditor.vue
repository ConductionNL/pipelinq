<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Editor for a service's multiStep composition, on BookingRowsEditor. Used by
  ServiceForm and by the service edit dialog's `#field-multiStep` slot.

  Warns when the step total disagrees with the service duration
  (REQ-APT-001 "duration sums to multi-step total").

  @spec openspec/specs/appointment-booking/spec.md
-->
<template>
	<BookingRowsEditor
		:modelValue="steps"
		:title="t('pipelinq', 'Multi-step composition')"
		:columns="columns"
		:newRow="newStep"
		numbered
		reorderable
		:labels="labels"
		:emptyText="t('pipelinq', 'Single-step service. Add steps to split it across resources.')"
		:addLabel="t('pipelinq', 'Add step')"
		:message="totalWarning"
		data-testid="service-steps-editor"
		@update:modelValue="(v) => $emit('update:modelValue', v)">
		<template #summary>
			{{ t('pipelinq', '{sum} of {duration} min', { sum: stepTotal, duration: durationMinutes || 0 }) }}
		</template>
		<template #cell-durationMinutes="{ row, index, update }">
			<NcTextField
				:modelValue="String(row.durationMinutes ?? 0)"
				type="number"
				min="0"
				labelOutside
				:aria-label="t('pipelinq', 'Step {n} duration in minutes', { n: index + 1 })"
				@update:modelValue="(v) => update(Number(v) || 0)" />
		</template>
		<template #cell-resourceType="{ row, index, update }">
			<NcSelect
				:modelValue="row.resourceType"
				:inputId="`service-step-resource-${uid}-${index}`"
				:aria-label-combobox="t('pipelinq', 'Step {n} resource type', { n: index + 1 })"
				labelOutside
				:clearable="false"
				:options="resourceTypeOptions"
				:reduce="(o) => o.value"
				label="label"
				@update:modelValue="update" />
		</template>
		<template #cell-skillRequired="{ row, index, update }">
			<NcTextField
				:modelValue="row.skillRequired || ''"
				labelOutside
				:placeholder="t('pipelinq', 'Any')"
				:aria-label="t('pipelinq', 'Step {n} required skill', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
		<template #cell-allowGap="{ row, index, update }">
			<NcCheckboxRadioSwitch
				:modelValue="!!row.allowGap"
				type="switch"
				:aria-label="t('pipelinq', 'Step {n} allows a gap', { n: index + 1 })"
				@update:modelValue="update" />
		</template>
	</BookingRowsEditor>
</template>

<script>
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'
import BookingRowsEditor from './BookingRowsEditor.vue'

let instanceCount = 0

export default {
	name: 'ServiceStepsEditor',
	components: { BookingRowsEditor, NcCheckboxRadioSwitch, NcSelect, NcTextField },

	props: {
		/** The steps: `{ durationMinutes, resourceType, skillRequired, allowGap }[]`. */
		modelValue: {
			type: Array,
			default: () => [],
		},

		/** The service's total duration, checked against the step total. */
		durationMinutes: {
			type: Number,
			default: null,
		},
	},

	emits: ['update:modelValue'],

	data() {
		instanceCount += 1
		return { uid: instanceCount }
	},

	computed: {
		steps() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		columns() {
			return [
				{ key: 'durationMinutes', label: t('pipelinq', 'Duration (min)'), width: 'minmax(5rem, 7rem)' },
				{ key: 'resourceType', label: t('pipelinq', 'Resource type'), width: 'minmax(8rem, 1fr)' },
				{ key: 'skillRequired', label: t('pipelinq', 'Skill required'), width: 'minmax(8rem, 1fr)' },
				{ key: 'allowGap', label: t('pipelinq', 'Allow gap'), width: '4.5rem' },
			]
		},

		labels() {
			return {
				moveUp: (n) => t('pipelinq', 'Move step {n} up', { n }),
				moveDown: (n) => t('pipelinq', 'Move step {n} down', { n }),
				remove: (n) => t('pipelinq', 'Remove step {n}', { n }),
			}
		},

		resourceTypeOptions() {
			return [
				{ value: 'staff', label: t('pipelinq', 'Staff') },
				{ value: 'room', label: t('pipelinq', 'Room') },
				{ value: 'equipment', label: t('pipelinq', 'Equipment') },
			]
		},

		stepTotal() {
			return this.steps.reduce((acc, s) => acc + (Number(s.durationMinutes) || 0), 0)
		},

		totalWarning() {
			if (this.steps.length === 0 || this.stepTotal === this.durationMinutes) {
				return ''
			}
			return t(
				'pipelinq',
				'Multi-step total ({sum} min) does not match Duration ({duration} min).',
				{ sum: this.stepTotal, duration: this.durationMinutes || 0 },
			)
		},
	},

	methods: {
		newStep() {
			return { durationMinutes: 0, resourceType: 'staff', skillRequired: '', allowGap: false }
		},
	},
}
</script>

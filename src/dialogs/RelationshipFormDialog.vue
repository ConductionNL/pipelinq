<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - RelationshipFormDialog adds or edits one relationship of a contact or
  - client. The parent owns the save; this dialog only collects the fields.
  -->
<template>
	<NcDialog
		:name="editing ? t('pipelinq', 'Edit relationship') : t('pipelinq', 'Add relationship')"
		size="normal"
		@closing="$emit('close')">
		<div class="relationship-form">
			<NcSelect
				v-if="!editing"
				v-model="draft.toContact"
				:inputLabel="t('pipelinq', 'Related entity') + ' *'"
				:options="entityOptions"
				:placeholder="t('pipelinq', 'Search contacts and clients…')"
				label="name"
				:reduce="(opt) => opt.id"
				data-testid="relationship-form-entity"
				@search="$emit('search', $event)" />
			<NcSelect
				v-model="draft.type"
				:inputLabel="t('pipelinq', 'Relationship type') + ' *'"
				:options="typeOptions"
				:placeholder="t('pipelinq', 'Select type…')"
				label="label"
				:reduce="(opt) => opt.value"
				data-testid="relationship-form-type" />
			<NcTextArea
				v-model="draft.notes"
				:label="t('pipelinq', 'Notes')"
				rows="2" />
			<div class="relationship-form__row">
				<NcDateTimePickerNative
					id="relationship-form-start"
					:modelValue="asDate(draft.startDate)"
					:label="t('pipelinq', 'Start date')"
					type="date"
					@update:modelValue="draft.startDate = asIso($event)" />
				<NcDateTimePickerNative
					id="relationship-form-end"
					:modelValue="asDate(draft.endDate)"
					:label="t('pipelinq', 'End date')"
					type="date"
					@update:modelValue="draft.endDate = asIso($event)" />
			</div>
			<NcSelect
				v-model="draft.strength"
				:inputLabel="t('pipelinq', 'Strength')"
				:options="strengthOptions"
				:placeholder="t('pipelinq', 'Select strength…')"
				label="label"
				:reduce="(opt) => opt.value" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!draft.toContact || !draft.type"
				data-testid="relationship-form-save"
				@click="$emit('save', { ...draft })">
				{{ editing ? t('pipelinq', 'Save') : t('pipelinq', 'Add') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDateTimePickerNative,
	NcDialog,
	NcSelect,
	NcTextArea,
} from '@nextcloud/vue'

export default {
	name: 'RelationshipFormDialog',
	components: {
		NcButton,
		NcDateTimePickerNative,
		NcDialog,
		NcSelect,
		NcTextArea,
	},

	props: {
		/** Whether an existing relationship is edited; the related entity is then fixed. */
		editing: {
			type: Boolean,
			default: false,
		},

		/** The starting values: toContact, type, notes, startDate, endDate, strength. */
		form: {
			type: Object,
			required: true,
		},

		/** Contacts and clients found by the last search, `{ id, name }`. */
		entityOptions: {
			type: Array,
			default: () => [],
		},

		/** Relationship types, `{ value, label }`. */
		typeOptions: {
			type: Array,
			default: () => [],
		},

		/** Strengths, `{ value, label }`. */
		strengthOptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'save', 'search'],

	data() {
		return {
			draft: { ...this.form },
		}
	},

	methods: {
		/**
		 * A stored `YYYY-MM-DD` as a Date for the picker, null when empty.
		 *
		 * @param {string} value The stored date.
		 * @return {Date|null} The date.
		 */
		asDate(value) {
			return value ? new Date(`${value}T00:00:00`) : null
		},

		/**
		 * A picked Date as `YYYY-MM-DD` in local time, '' when cleared.
		 *
		 * @param {Date|null} value The picked date.
		 * @return {string} The stored date.
		 */
		asIso(value) {
			if (!(value instanceof Date) || Number.isNaN(value.getTime())) {
				return ''
			}
			const pad = (n) => String(n).padStart(2, '0')
			return `${value.getFullYear()}-${pad(value.getMonth() + 1)}-${pad(value.getDate())}`
		},
	},
}
</script>

<style scoped>
.relationship-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.relationship-form__row {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}
</style>

<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="client-custom-fields" data-testid="client-custom-fields">
		<div
			v-for="field in fields"
			:key="field.key"
			class="client-custom-fields__field"
			:data-testid="`client-custom-field-${field.key}`">
			<NcCheckboxRadioSwitch
				v-if="field.kind === 'boolean'"
				:modelValue="values[field.key] === true"
				@update:modelValue="(v) => set(field.key, v)">
				{{ field.label }}
			</NcCheckboxRadioSwitch>
			<template v-else>
				<label :for="inputId(field)" class="client-custom-fields__label">{{ field.label }}</label>
				<NcSelect
					v-if="field.kind === 'choice'"
					:modelValue="values[field.key] || null"
					:inputId="inputId(field)"
					:inputLabel="field.label"
					labelOutside
					:options="field.options"
					:clearable="true"
					@update:modelValue="(v) => set(field.key, v || '')" />
				<NcTextField
					v-else
					:id="inputId(field)"
					:label="field.label"
					labelOutside
					:type="inputType(field)"
					:helperText="field.description"
					:modelValue="values[field.key] ?? ''"
					@update:modelValue="(v) => set(field.key, v)" />
			</template>
		</div>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcSelect, NcTextField } from '@nextcloud/vue'

/**
 * The inputs of the fields an administrator added to the client schema,
 * one per field, typed by the property's type.
 *
 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
 */
export default {
	name: 'ClientCustomFieldInputs',
	components: { NcCheckboxRadioSwitch, NcSelect, NcTextField },

	props: {
		/** The added fields, from customClientFields(). */
		fields: {
			type: Array,
			required: true,
		},

		/** Their values, by key. */
		values: {
			type: Object,
			required: true,
		},
	},

	emits: ['update:values'],

	methods: {
		/**
		 * @param {{key: string}} field The field.
		 * @return {string} The input's id.
		 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
		 */
		inputId(field) {
			return `client-custom-${field.key}`
		},

		/**
		 * @param {{kind: string}} field The field.
		 * @return {string} The HTML input type.
		 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
		 */
		inputType(field) {
			if (field.kind === 'number') {
				return 'number'
			}
			return field.kind === 'date' ? 'date' : 'text'
		},

		/**
		 * Pass a changed value up as a new values object.
		 *
		 * @param {string} key The field's key.
		 * @param {string|number|boolean} value The new value.
		 * @return {void}
		 * @spec openspec/changes/specify-capability-rows/specs/client-management/spec.md#requirement-a-field-an-administrator-adds-to-the-client-schema-shows-on-the-client-pages
		 */
		set(key, value) {
			this.$emit('update:values', { ...this.values, [key]: value })
		},
	},
}
</script>

<style scoped>
.client-custom-fields {
	display: contents;
}

.client-custom-fields__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	min-width: 0;
}

.client-custom-fields__label {
	font-weight: 600;
}
</style>

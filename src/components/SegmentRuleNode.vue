<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - One node of a segment rule tree: an AND/OR group rendered as a card of
  - condition rows (recursing for nested groups), or a single condition row of
  - field, operator and value.
  -->
<template>
	<div
		v-if="isGroup"
		class="segment-rule-group"
		:class="{
			'segment-rule-group--nested': depth > 0,
			'segment-rule-group--error': ownError,
		}">
		<div class="segment-rule-group__header">
			<NcSelect
				:modelValue="combinatorOption"
				:options="combinatorOptions"
				:ariaLabelCombobox="t('pipelinq', 'How the conditions combine')"
				label="label"
				:clearable="false"
				:searchable="false"
				class="segment-rule-group__combinator"
				@update:modelValue="onCombinatorChange" />
			<NcButton
				v-if="depth > 0"
				variant="tertiary"
				:aria-label="t('pipelinq', 'Remove group')"
				:title="t('pipelinq', 'Remove group')"
				@click="$emit('remove')">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
			</NcButton>
		</div>

		<ol v-if="node.children.length" class="segment-rule-group__list">
			<li
				v-for="(child, index) in node.children"
				:key="index"
				class="segment-rule-group__item">
				<span class="segment-rule-group__connector">
					{{ index === 0 ? t('pipelinq', 'Where') : connectorLabel }}
				</span>
				<SegmentRuleNode
					class="segment-rule-group__child"
					:node="child"
					:depth="depth + 1"
					:fieldOptions="fieldOptions"
					:errors="errors"
					:path="childPath(index)"
					@update:node="updateChild(index, $event)"
					@remove="removeChild(index)" />
			</li>
		</ol>
		<p v-else class="segment-rule-group__empty">
			{{
				depth === 0
					? t('pipelinq', 'No conditions yet. Add a condition to decide who belongs to this segment.')
					: t('pipelinq', 'This group has no conditions yet.')
			}}
		</p>

		<p v-if="ownError" class="segment-rule__error" role="alert">
			{{ ownError }}
		</p>

		<div class="segment-rule-group__actions">
			<NcButton variant="tertiary" @click="addCondition">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{ t('pipelinq', 'Add condition') }}
			</NcButton>
			<NcButton variant="tertiary" @click="addGroup">
				<template #icon>
					<PlusBoxMultipleOutline :size="20" />
				</template>
				{{ t('pipelinq', 'Add group') }}
			</NcButton>
		</div>
	</div>

	<div
		v-else
		class="segment-rule"
		:class="{ 'segment-rule--error': ownError }">
		<div class="segment-rule__fields">
			<NcSelect
				:modelValue="fieldOption"
				:options="fieldSelectOptions"
				:ariaLabelCombobox="t('pipelinq', 'Field')"
				:placeholder="t('pipelinq', 'Choose a field')"
				label="label"
				:clearable="false"
				class="segment-rule__field"
				@update:modelValue="onFieldChange" />
			<NcSelect
				:modelValue="operatorOption"
				:options="operatorOptions"
				:ariaLabelCombobox="t('pipelinq', 'Condition')"
				:placeholder="t('pipelinq', 'Condition')"
				label="label"
				:clearable="false"
				:searchable="false"
				:disabled="!node.field"
				class="segment-rule__operator"
				@update:modelValue="onOperatorChange" />

			<NcSelect
				v-if="valueKind === 'boolean'"
				:modelValue="booleanOption"
				:options="booleanOptions"
				:ariaLabelCombobox="t('pipelinq', 'Value')"
				:placeholder="t('pipelinq', 'Value')"
				label="label"
				:clearable="false"
				:searchable="false"
				:disabled="!node.field"
				class="segment-rule__value"
				@update:modelValue="onValueChange($event?.value)" />
			<NcDateTimePickerNative
				v-else-if="valueKind === 'date'"
				:id="`segment-rule-value-${path}`"
				:modelValue="dateValue"
				type="date"
				:label="t('pipelinq', 'Value')"
				hideLabel
				:disabled="!node.field"
				class="segment-rule__value"
				@update:modelValue="onValueChange(toDateInputString($event) || '')" />
			<NcTextField
				v-else
				:modelValue="textValue"
				:type="valueKind === 'number' ? 'number' : 'text'"
				:label="t('pipelinq', 'Value')"
				:disabled="!node.field"
				class="segment-rule__value"
				@update:modelValue="onValueChange" />

			<NcButton
				variant="tertiary"
				:aria-label="t('pipelinq', 'Remove condition')"
				:title="t('pipelinq', 'Remove condition')"
				@click="$emit('remove')">
				<template #icon>
					<TrashCanOutline :size="20" />
				</template>
			</NcButton>
		</div>
		<p v-if="ownError" class="segment-rule__error" role="alert">
			{{ ownError }}
		</p>
	</div>
</template>

<script>
import { NcButton, NcDateTimePickerNative, NcSelect, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PlusBoxMultipleOutline from 'vue-material-design-icons/PlusBoxMultipleOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import { toDateInputString, toDateObject } from '../services/localeUtils.js'

// Operator names are SegmentService::OPERATOR_TYPE_MATRIX's own
// (lib/Service/SegmentService.php): they travel to the API unchanged, and a
// name the service does not know is rejected.
const OPERATORS_BY_TYPE = {
	string: [
		{ value: 'equals', label: 'Equals' },
		{ value: 'notEquals', label: 'Not equals' },
		{ value: 'contains', label: 'Contains' },
	],
	integer: [
		{ value: 'equals', label: 'Equals' },
		{ value: 'notEquals', label: 'Not equals' },
		{ value: 'greaterThan', label: 'Greater than' },
		{ value: 'greaterThanOrEqual', label: 'Greater than or equal' },
		{ value: 'lessThan', label: 'Less than' },
		{ value: 'lessThanOrEqual', label: 'Less than or equal' },
	],
	number: [
		{ value: 'equals', label: 'Equals' },
		{ value: 'notEquals', label: 'Not equals' },
		{ value: 'greaterThan', label: 'Greater than' },
		{ value: 'greaterThanOrEqual', label: 'Greater than or equal' },
		{ value: 'lessThan', label: 'Less than' },
		{ value: 'lessThanOrEqual', label: 'Less than or equal' },
	],
	boolean: [
		{ value: 'equals', label: 'Is' },
		{ value: 'notEquals', label: 'Is not' },
	],
	array: [{ value: 'contains', label: 'Contains' }],
}

// Dates are stored as ISO strings, so a date-formatted string field also
// takes before / after.
const DATE_OPERATORS = [
	{ value: 'before', label: 'Before' },
	{ value: 'after', label: 'After' },
]

export default {
	name: 'SegmentRuleNode',
	components: {
		NcButton,
		NcDateTimePickerNative,
		NcSelect,
		NcTextField,
		Plus,
		PlusBoxMultipleOutline,
		TrashCanOutline,
	},

	props: {
		node: {
			type: Object,
			required: true,
		},

		depth: {
			type: Number,
			default: 0,
		},

		fieldOptions: {
			type: Array,
			default: () => [],
		},

		/** Error messages keyed by node path, as SegmentBuilder parses them. */
		errors: {
			type: Object,
			default: () => ({}),
		},

		/** This node's path in the validator's notation (`$.children[0]`). */
		path: {
			type: String,
			default: '$',
		},
	},

	emits: ['update:node', 'remove'],

	computed: {
		isGroup() {
			return Array.isArray(this.node?.children)
		},

		ownError() {
			return this.errors?.[this.path] || ''
		},

		combinatorOptions() {
			return [
				{ value: 'AND', label: this.t('pipelinq', 'Match all conditions') },
				{ value: 'OR', label: this.t('pipelinq', 'Match any condition') },
			]
		},

		combinatorOption() {
			return this.combinatorOptions.find((o) => o.value === this.node.type) || this.combinatorOptions[0]
		},

		connectorLabel() {
			return this.node.type === 'OR' ? this.t('pipelinq', 'or') : this.t('pipelinq', 'and')
		},

		translatedFieldOptions() {
			return this.fieldOptions.map((o) => ({ ...o, label: this.t('pipelinq', o.label) }))
		},

		/** @return {Array<object>} The field options, plus a stored field this list does not offer. */
		fieldSelectOptions() {
			const options = this.translatedFieldOptions
			if (this.node.field && !options.some((o) => o.value === this.node.field)) {
				return [...options, { value: this.node.field, label: this.node.field, type: 'string' }]
			}
			return options
		},

		fieldOption() {
			return this.fieldSelectOptions.find((o) => o.value === this.node.field) || null
		},

		fieldType() {
			return this.fieldOption?.type || 'string'
		},

		/** @return {Array<object>} The operators for the field's type, plus a stored one this list does not offer. */
		operatorOptions() {
			const base = OPERATORS_BY_TYPE[this.fieldType] || OPERATORS_BY_TYPE.string
			const list = (this.fieldOption?.format === 'date' ? [...base, ...DATE_OPERATORS] : base)
				.map((o) => ({ value: o.value, label: this.t('pipelinq', o.label) }))
			if (this.node.operator && !list.some((o) => o.value === this.node.operator)) {
				list.push({ value: this.node.operator, label: this.node.operator })
			}
			return list
		},

		operatorOption() {
			return this.operatorOptions.find((o) => o.value === this.node.operator) || null
		},

		/** @return {string} Which value control fits the field: boolean, date, number or text. */
		valueKind() {
			if (this.fieldType === 'boolean') {
				return 'boolean'
			}
			if (this.fieldOption?.format === 'date') {
				return 'date'
			}
			if (this.fieldType === 'number' || this.fieldType === 'integer') {
				return 'number'
			}
			return 'text'
		},

		booleanOptions() {
			return [
				{ value: true, label: this.t('pipelinq', 'Yes') },
				{ value: false, label: this.t('pipelinq', 'No') },
			]
		},

		/** @return {object|null} The Yes / No option; the validator also accepts 'true', '1' and friends. */
		booleanOption() {
			const v = this.node.value
			if (v === '' || v === null || v === undefined) {
				return null
			}
			const truthy = v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'true'
			return this.booleanOptions[truthy ? 0 : 1]
		},

		dateValue() {
			return toDateObject(this.node.value)
		},

		textValue() {
			return this.node.value === null || this.node.value === undefined ? '' : String(this.node.value)
		},
	},

	methods: {
		toDateInputString,

		childPath(index) {
			return `${this.path}.children[${index}]`
		},

		updateChild(index, next) {
			const children = [...this.node.children]
			children[index] = next
			this.emitChange({ ...this.node, children })
		},

		removeChild(index) {
			this.emitChange({ ...this.node, children: this.node.children.filter((_, i) => i !== index) })
		},

		addCondition() {
			this.emitChange({ ...this.node, children: [...this.node.children, { field: '', operator: '', value: '' }] })
		},

		addGroup() {
			const group = { type: 'AND', children: [{ field: '', operator: '', value: '' }] }
			this.emitChange({ ...this.node, children: [...this.node.children, group] })
		},

		onCombinatorChange(option) {
			this.emitChange({ ...this.node, type: option?.value || 'AND' })
		},

		/**
		 * Pick a field. The operator resets to the first one its type allows,
		 * and the value is cleared, since a value typed for another field
		 * rarely fits this one.
		 *
		 * @param {object} option The field option.
		 */
		onFieldChange(option) {
			const list = OPERATORS_BY_TYPE[option?.type || 'string'] || OPERATORS_BY_TYPE.string
			this.emitChange({
				...this.node,
				field: option?.value || '',
				operator: list[0].value,
				value: option?.type === 'boolean' ? true : '',
			})
		},

		onOperatorChange(option) {
			this.emitChange({ ...this.node, operator: option?.value || '' })
		},

		onValueChange(value) {
			this.emitChange({ ...this.node, value })
		},

		emitChange(next) {
			this.$emit('update:node', next)
		},
	},
}
</script>

<style scoped>
.segment-rule-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.segment-rule-group--nested {
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.segment-rule-group--error {
	border-color: var(--color-border-error, var(--color-error));
}

.segment-rule-group__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
}

.segment-rule-group__header .segment-rule-group__combinator {
	width: 240px;
	max-width: 100%;
	margin: 0;
}

.segment-rule-group__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.segment-rule-group__item {
	display: grid;
	grid-template-columns: 56px minmax(0, 1fr);
	align-items: start;
	gap: 8px;
}

/* Centred on the first row of controls, whose height is the clickable area. */
.segment-rule-group__connector {
	line-height: var(--default-clickable-area);
	color: var(--color-text-maxcontrast);
	text-align: end;
}

.segment-rule-group__empty {
	margin: 0;
	padding: 12px;
	border: 1px dashed var(--color-border-dark);
	border-radius: var(--border-radius-large);
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.segment-rule-group__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.segment-rule__fields {
	display: grid;
	grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1.3fr) auto;
	align-items: center;
	gap: 8px;
}

/* NcSelect's own root margin would throw the row out of line. */
.segment-rule__fields .segment-rule__field,
.segment-rule__fields .segment-rule__operator,
.segment-rule__fields .segment-rule__value {
	width: 100%;
	min-width: 0;
	margin: 0;
}

.segment-rule--error .segment-rule__fields :deep(input),
.segment-rule--error .segment-rule__fields :deep(.vs__dropdown-toggle) {
	border-color: var(--color-border-error, var(--color-error));
}

.segment-rule__error {
	margin: 4px 0 0;
	color: var(--color-text-error, var(--color-error));
	font-size: 0.9em;
}

@media (max-width: 720px) {
	.segment-rule__fields {
		grid-template-columns: minmax(0, 1fr) auto;
	}

	.segment-rule__fields .segment-rule__field,
	.segment-rule__fields .segment-rule__operator,
	.segment-rule__fields .segment-rule__value {
		grid-column: 1;
	}

	.segment-rule-group__item {
		grid-template-columns: minmax(0, 1fr);
	}

	.segment-rule-group__connector {
		line-height: normal;
		text-align: start;
	}
}
</style>

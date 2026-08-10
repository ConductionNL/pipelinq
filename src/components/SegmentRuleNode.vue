<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="rule-node" :style="indentStyle">
		<div v-if="isGroup" class="rule-node__group">
			<div class="rule-node__group-header">
				<NcSelect :model-value="groupOperatorOption"
					:options="groupOperators"
					:input-label="t('pipelinq', 'Combine with')"
					label="label"
					:clearable="false"
					class="rule-node__op-select"
					@update:model-value="onGroupOperatorChange" />
				<NcButton variant="tertiary" @click="$emit('remove')">
					<template #icon>
						<Delete :size="18" />
					</template>
					{{ t('pipelinq', 'Remove group') }}
				</NcButton>
			</div>

			<SegmentRuleNode v-for="(child, index) in node.children"
				:key="index"
				:node="child"
				:depth="depth + 1"
				:entity-type="entityType"
				:field-options="fieldOptions"
				:errors="errors"
				:path="childPath(index)"
				@update:node="updateChild(index, $event)"
				@remove="removeChild(index)"
				@validate-leaf="$emit('validate-leaf')" />

			<div class="rule-node__group-actions">
				<NcButton variant="secondary" @click="addCondition">
					<template #icon>
						<Plus :size="18" />
					</template>
					{{ t('pipelinq', 'Add condition') }}
				</NcButton>
				<NcButton variant="tertiary" @click="addGroup">
					<template #icon>
						<Plus :size="18" />
					</template>
					{{ t('pipelinq', 'Add group') }}
				</NcButton>
			</div>
		</div>

		<div v-else class="rule-node__leaf">
			<NcSelect :model-value="fieldOption"
				:options="fieldOptions"
				:input-label="t('pipelinq', 'Field')"
				label="label"
				:clearable="false"
				class="rule-node__field"
				@update:model-value="onFieldChange" />
			<NcSelect :model-value="operatorOption"
				:options="operatorOptions"
				:input-label="t('pipelinq', 'Operator')"
				label="label"
				:clearable="false"
				class="rule-node__operator"
				@update:model-value="onOperatorChange" />
			<div class="rule-node__value-wrap">
				<label class="rule-node__value-label">
					{{ t('pipelinq', 'Value') }}
				</label>
				<input v-if="valueInputType === 'number'"
					:value="node.value"
					type="number"
					class="rule-node__value"
					:aria-label="t('pipelinq', 'Rule value')"
					@input="onValueInput($event.target.value)"
					@blur="$emit('validate-leaf')">
				<input v-else-if="valueInputType === 'date'"
					:value="node.value"
					type="date"
					class="rule-node__value"
					:aria-label="t('pipelinq', 'Rule value')"
					@input="onValueInput($event.target.value)"
					@blur="$emit('validate-leaf')">
				<input v-else
					:value="node.value"
					type="text"
					class="rule-node__value"
					:aria-label="t('pipelinq', 'Rule value')"
					@input="onValueInput($event.target.value)"
					@blur="$emit('validate-leaf')">
			</div>
			<NcButton variant="tertiary" :aria-label="t('pipelinq', 'Remove rule')" @click="$emit('remove')">
				<template #icon>
					<Delete :size="18" />
				</template>
			</NcButton>
			<p v-if="leafError" class="rule-node__error" role="alert">
				{{ leafError }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcSelect, NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

const OPERATORS_BY_TYPE = {
	string: [
		{ value: 'eq', label: 'Equals' },
		{ value: 'ne', label: 'Not equals' },
		{ value: 'contains', label: 'Contains' },
		{ value: 'starts_with', label: 'Starts with' },
		{ value: 'is_empty', label: 'Is empty' },
		{ value: 'is_not_empty', label: 'Is not empty' },
	],
	number: [
		{ value: 'eq', label: 'Equals' },
		{ value: 'ne', label: 'Not equals' },
		{ value: 'gt', label: 'Greater than' },
		{ value: 'gte', label: 'Greater than or equal' },
		{ value: 'lt', label: 'Less than' },
		{ value: 'lte', label: 'Less than or equal' },
	],
	boolean: [
		{ value: 'eq', label: 'Is true / false' },
	],
	date: [
		{ value: 'before', label: 'Before' },
		{ value: 'after', label: 'After' },
		{ value: 'on', label: 'On' },
	],
}

export default {
	name: 'SegmentRuleNode',
	components: {
		NcSelect,
		NcButton,
		Plus,
		Delete,
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
		entityType: {
			type: String,
			required: true,
		},
		fieldOptions: {
			type: Array,
			default: () => [],
		},
		errors: {
			type: Object,
			default: () => ({}),
		},
		path: {
			type: String,
			default: 'root',
		},
	},
	emits: ['update:node', 'remove', 'validate-leaf'],
	computed: {
		/**
		 * Whether this node is an AND/OR group (versus a leaf predicate).
		 *
		 * @return {boolean}
		 */
		isGroup() {
			return Array.isArray(this.node?.children)
		},
		/**
		 * Operators available for grouping nodes (AND vs OR).
		 *
		 * @return {Array<{value:string,label:string}>}
		 */
		groupOperators() {
			return [
				{ value: 'AND', label: this.t('pipelinq', 'Match all (AND)') },
				{ value: 'OR', label: this.t('pipelinq', 'Match any (OR)') },
			]
		},
		/**
		 * Currently-selected group operator option.
		 *
		 * @return {object}
		 */
		groupOperatorOption() {
			return this.groupOperators.find((o) => o.value === this.node.type) || this.groupOperators[0]
		},
		/**
		 * Indentation style based on nesting depth.
		 *
		 * @return {object}
		 */
		indentStyle() {
			return { marginLeft: `${Math.min(this.depth, 4) * 16}px` }
		},
		/**
		 * Selected field option (lookup by field name).
		 *
		 * @return {object|null}
		 */
		fieldOption() {
			return this.fieldOptions.find((o) => o.value === this.node.field) || null
		},
		/**
		 * Detected type for the currently-selected field (default string).
		 *
		 * @return {string}
		 */
		fieldType() {
			return this.fieldOption?.type || 'string'
		},
		/**
		 * Operators applicable to the current field type.
		 *
		 * @return {Array<{value:string,label:string}>}
		 */
		operatorOptions() {
			const list = OPERATORS_BY_TYPE[this.fieldType] || OPERATORS_BY_TYPE.string
			return list.map((o) => ({ value: o.value, label: this.t('pipelinq', o.label) }))
		},
		/**
		 * Selected operator option.
		 *
		 * @return {object|null}
		 */
		operatorOption() {
			return this.operatorOptions.find((o) => o.value === this.node.operator) || null
		},
		/**
		 * Native input type for the value field, derived from field type.
		 *
		 * @return {string}
		 */
		valueInputType() {
			if (this.fieldType === 'number') {
				return 'number'
			}
			if (this.fieldType === 'date') {
				return 'date'
			}
			return 'text'
		},
		/**
		 * Field-level error message at this node's path.
		 *
		 * @return {string}
		 */
		leafError() {
			return this.errors?.[this.path] || ''
		},
	},
	methods: {
		/**
		 * Build the path string for a child index, used to address errors.
		 *
		 * @param {number} index Child index.
		 * @return {string} Dot-separated path.
		 */
		childPath(index) {
			return `${this.path}.${index}`
		},
		/**
		 * Replace a child node at `index` with `next`, then emit the updated tree.
		 *
		 * @param {number} index Index of the child to replace.
		 * @param {object} next The new child value.
		 */
		updateChild(index, next) {
			const children = [...this.node.children]
			children[index] = next
			this.emitChange({ ...this.node, children })
		},
		/**
		 * Drop the child node at `index` and emit the updated tree.
		 *
		 * @param {number} index Index of the child to drop.
		 */
		removeChild(index) {
			const children = this.node.children.filter((_, i) => i !== index)
			this.emitChange({ ...this.node, children })
		},
		/**
		 * Append a blank leaf condition to this group.
		 */
		addCondition() {
			const children = [...this.node.children, { field: '', operator: 'eq', value: '' }]
			this.emitChange({ ...this.node, children })
		},
		/**
		 * Append a blank AND group to this group.
		 */
		addGroup() {
			const children = [...this.node.children, { type: 'AND', children: [] }]
			this.emitChange({ ...this.node, children })
		},
		/**
		 * Switch this group from AND to OR or vice versa.
		 *
		 * @param {object} option NcSelect option (`{value, label}`).
		 */
		onGroupOperatorChange(option) {
			this.emitChange({ ...this.node, type: option?.value || 'AND' })
		},
		/**
		 * Set the leaf's field; reset operator to a sensible default for the type.
		 *
		 * @param {object} option NcSelect option (`{value, label, type}`).
		 */
		onFieldChange(option) {
			const value = option?.value || ''
			const list = OPERATORS_BY_TYPE[option?.type || 'string'] || OPERATORS_BY_TYPE.string
			this.emitChange({ ...this.node, field: value, operator: list[0].value })
			this.$emit('validate-leaf')
		},
		/**
		 * Update the leaf's operator.
		 *
		 * @param {object} option NcSelect option (`{value, label}`).
		 */
		onOperatorChange(option) {
			this.emitChange({ ...this.node, operator: option?.value || 'eq' })
			this.$emit('validate-leaf')
		},
		/**
		 * Update the leaf's value (raw input).
		 *
		 * @param {string} value The new value as typed.
		 */
		onValueInput(value) {
			this.emitChange({ ...this.node, value })
		},
		/**
		 * Emit the updated node upwards.
		 *
		 * @param {object} next The new node.
		 */
		emitChange(next) {
			this.$emit('update:node', next)
		},
	},
}
</script>

<style scoped>
.rule-node {
	border-left: 2px solid var(--color-border);
	padding-left: 8px;
	margin: 6px 0;
}

.rule-node__group {
	display: flex;
	flex-direction: column;
	gap: 6px;
	background: var(--color-background-hover);
	padding: 8px;
	border-radius: var(--border-radius);
}

.rule-node__group-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.rule-node__group-actions {
	display: flex;
	gap: 6px;
}

.rule-node__op-select {
	min-width: 220px;
}

.rule-node__leaf {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	align-items: flex-end;
	padding: 6px 0;
}

.rule-node__field {
	min-width: 180px;
}

.rule-node__operator {
	min-width: 180px;
}

.rule-node__value-wrap {
	display: flex;
	flex-direction: column;
	flex: 1;
	min-width: 160px;
}

.rule-node__value-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-bottom: 2px;
}

.rule-node__value {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-darker);
	color: var(--color-main-text);
	min-width: 120px;
}

.rule-node__error {
	flex-basis: 100%;
	color: var(--color-error);
	margin: 0;
	font-size: 0.9em;
}
</style>

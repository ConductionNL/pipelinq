<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<template>
	<div class="automation-builder">
		<div class="builder-header">
			<h2>{{ isNew ? t('pipelinq', 'New automation') : t('pipelinq', 'Edit automation') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="builder-form">
			<!-- Name -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Automation name') }} *</label>
				<input v-model="automation.name"
					type="text"
					class="form-input"
					:placeholder="t('pipelinq', 'My automation rule')">
			</div>

			<!-- Trigger -->
			<div class="form-group">
				<NcSelect v-model="automation.trigger"
					:options="triggerOptions"
					:input-label="t('pipelinq', 'Trigger event') + ' *'"
					label="label"
					:reduce="opt => opt.value" />
			</div>

			<!-- Conditions -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Conditions') }}</label>
				<div v-for="(condition, index) in conditions" :key="index" class="condition-row">
					<input v-model="condition.field"
						type="text"
						class="condition-field"
						:placeholder="t('pipelinq', 'Field (e.g. status)')">
					<NcSelect v-model="condition.operator"
						:options="operatorOptions"
						:aria-label-combobox="t('pipelinq', 'Operator')"
						label="label"
						:reduce="opt => opt.value"
						class="condition-operator" />
					<input v-model="condition.value"
						type="text"
						class="condition-value"
						:placeholder="t('pipelinq', 'Value')">
					<NcButton type="tertiary" @click="removeCondition(index)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addCondition">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'Add condition') }}
				</NcButton>
			</div>

			<!-- Actions -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Actions') }}</label>
				<div v-for="(action, index) in automation.actions" :key="index" class="action-row">
					<NcSelect v-model="action.type"
						:options="actionOptions"
						:aria-label-combobox="t('pipelinq', 'Action type')"
						label="label"
						:reduce="opt => opt.value"
						class="action-type" />
					<input v-model="action.target"
						type="text"
						class="action-target"
						:placeholder="t('pipelinq', 'Target field or recipient')">
					<input v-model="action.value"
						type="text"
						class="action-value"
						:placeholder="t('pipelinq', 'Value')">
					<NcButton type="tertiary" @click="removeAction(index)">
						<template #icon>
							<Delete :size="20" />
						</template>
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addAction">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'Add action') }}
				</NcButton>
			</div>

			<!-- Webhook URL (optional) -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Webhook URL') }}</label>
				<input v-model="automation.webhookUrl"
					type="url"
					class="form-input"
					:placeholder="t('pipelinq', 'https://n8n.example.com/webhook/...')">
			</div>

			<!-- Inline error feedback -->
			<p v-if="saveError" class="save-error" role="alert">
				{{ saveError }}
			</p>

			<!-- Save / Cancel -->
			<div class="form-actions">
				<NcButton type="primary" :disabled="!canSave" @click="save">
					{{ t('pipelinq', 'Save automation') }}
				</NcButton>
				<NcButton type="secondary" @click="$router.push({ name: 'Automations' })">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/store.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'AutomationBuilder',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		Plus,
		Delete,
	},
	props: {
		automationId: {
			type: String,
			default: null,
		},
		id: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			saveError: '',
			automation: {
				name: '',
				trigger: null,
				conditions: [],
				actions: [],
				isActive: true,
				webhookUrl: '',
			},
			conditions: [],
		}
	},
	computed: {
		isNew() {
			return !this.automationId
		},
		/**
		 * Returns the available action type options for an automation rule.
		 * Each option maps a machine-readable value to a user-visible label.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-1
		 * @return {Array<{value: string, label: string}>}
		 */
		actionOptions() {
			return [
				{ value: 'assign_lead', label: this.t('pipelinq', 'Assign lead') },
				{ value: 'move_stage', label: this.t('pipelinq', 'Move stage') },
				{ value: 'send_notification', label: this.t('pipelinq', 'Send notification') },
				{ value: 'add_note', label: this.t('pipelinq', 'Add note') },
				{ value: 'fire_webhook', label: this.t('pipelinq', 'Fire webhook') },
				{ value: 'update_tag', label: this.t('pipelinq', 'Update tag') },
				{ value: 'apply_decision', label: this.t('pipelinq', 'Apply DMN decision') },
			]
		},
		/**
		 * Returns true when the automation has the minimum required fields
		 * (name and trigger) filled in, enabling the save button.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-5
		 * @return {boolean}
		 */
		canSave() {
			return !!(this.automation.name && this.automation.name.trim() && this.automation.trigger)
		},
		/**
		 * Returns the available comparison operator options for a condition row.
		 * Operators cover equality, containment and numeric comparisons.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-8
		 * @return {Array<{value: string, label: string}>}
		 */
		operatorOptions() {
			return [
				{ value: 'equals', label: this.t('pipelinq', 'Equals') },
				{ value: 'not_equals', label: this.t('pipelinq', 'Not equals') },
				{ value: 'contains', label: this.t('pipelinq', 'Contains') },
				{ value: 'not_contains', label: this.t('pipelinq', 'Does not contain') },
				{ value: 'greater_than', label: this.t('pipelinq', 'Greater than') },
				{ value: 'less_than', label: this.t('pipelinq', 'Less than') },
				{ value: 'is_empty', label: this.t('pipelinq', 'Is empty') },
				{ value: 'is_not_empty', label: this.t('pipelinq', 'Is not empty') },
			]
		},
		/**
		 * Returns the available trigger event options that can initiate an automation.
		 * Triggers correspond to lifecycle events on CRM entities.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-13
		 * @return {Array<{value: string, label: string}>}
		 */
		triggerOptions() {
			return [
				{ value: 'lead_created', label: this.t('pipelinq', 'Lead created') },
				{ value: 'lead_stage_changed', label: this.t('pipelinq', 'Lead stage changed') },
				{ value: 'lead_assigned', label: this.t('pipelinq', 'Lead assigned') },
				{ value: 'contact_created', label: this.t('pipelinq', 'Contact created') },
				{ value: 'request_created', label: this.t('pipelinq', 'Request created') },
				{ value: 'request_status_changed', label: this.t('pipelinq', 'Request status changed') },
				{ value: 'marketing_segment_match', label: this.t('pipelinq', 'Marketing segment match') },
			]
		},
	},
	/**
	 * On mount, loads the automation if an automationId prop is provided.
	 * New automations start with empty state.
	 *
	 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-7
	 */
	mounted() {
		const effectiveId = this.automationId || (this.id && this.id !== 'new' ? this.id : null)
		if (effectiveId) {
			this.loadAutomation(effectiveId)
		}
	},
	methods: {
		/**
		 * Appends a new blank action entry to the automation's actions array.
		 * Each action starts with an empty type, target and value.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-2
		 */
		addAction() {
			this.automation.actions.push({
				type: null,
				target: '',
				value: '',
			})
		},
		/**
		 * Appends a new blank condition row to the local conditions array.
		 * Each condition starts with empty field, a default operator and an empty value.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-3
		 */
		addCondition() {
			this.conditions.push({
				field: '',
				operator: 'equals',
				value: '',
			})
		},
		/**
		 * Converts the local reactive conditions array into the flat object array
		 * expected by the automation schema. Returns an empty array when there are
		 * no conditions, so the stored value is never undefined.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-4
		 * @return {Array<{field: string, operator: string, value: string}>}
		 */
		buildConditions() {
			if (!this.conditions || this.conditions.length === 0) {
				return []
			}
			return this.conditions
				.filter((c) => c.field && c.field.trim())
				.map((c) => ({
					field: c.field.trim(),
					operator: c.operator || 'equals',
					value: c.value || '',
				}))
		},
		/**
		 * Loads the automation identified by automationId from the object store
		 * and populates the local reactive state. On success also runs parseConditions.
		 * Errors are caught so the component remains usable.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-6
		 */
		async loadAutomation(effectiveId) {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObject('automation', effectiveId || this.automationId || this.id)
				if (result) {
					this.automation = { ...result }
					this.parseConditions()
				}
			} catch (e) {
				this.saveError = this.t('pipelinq', 'Failed to load automation')
				// eslint-disable-next-line no-console
				console.error('Failed to load automation', e)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Parses the stored conditions array from the loaded automation into the
		 * local reactive conditions array that drives the condition-row UI.
		 * Tolerates absent or malformed input by defaulting to an empty array.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-9
		 */
		parseConditions() {
			const stored = this.automation.conditions
			if (!Array.isArray(stored)) {
				this.conditions = []
				return
			}
			this.conditions = stored.map((c) => ({
				field: c.field || '',
				operator: c.operator || 'equals',
				value: c.value || '',
			}))
		},
		/**
		 * Removes the action at the given index from the automation's actions array.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-10
		 * @param {number} index Zero-based index of the action to remove.
		 */
		removeAction(index) {
			this.automation.actions.splice(index, 1)
		},
		/**
		 * Removes the condition row at the given index from the local conditions array.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-11
		 * @param {number} index Zero-based index of the condition to remove.
		 */
		removeCondition(index) {
			this.conditions.splice(index, 1)
		},
		/**
		 * Persists the automation rule to the object store. Assembles the final
		 * conditions via buildConditions before saving, then navigates back to
		 * the automations list on success.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-automations-ui/tasks.md#task-12
		 */
		async save() {
			this.saveError = ''
			this.automation.conditions = this.buildConditions()

			try {
				const objectStore = useObjectStore()
				await objectStore.saveObject('automation', this.automation)
				this.$router.push({ name: 'Automations' })
			} catch (e) {
				this.saveError = this.t('pipelinq', 'Failed to save automation')
				// eslint-disable-next-line no-console
				console.error('Failed to save automation', e)
			}
		},
	},
}
</script>

<style scoped>
.automation-builder {
	padding: 20px;
	max-width: 900px;
}

.builder-form {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.form-group {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.form-group > label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.form-input {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.condition-row,
.action-row {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 8px;
	flex-wrap: wrap;
}

.condition-field,
.condition-value,
.action-target,
.action-value {
	flex: 1;
	min-width: 100px;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.condition-operator {
	min-width: 150px;
}

.action-type {
	min-width: 180px;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.save-error {
	color: var(--color-error);
	font-weight: 600;
}
</style>

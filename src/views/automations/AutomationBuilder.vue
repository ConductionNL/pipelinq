<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="automation-builder">
		<div class="builder-header">
			<h2>{{ isEdit ? t('pipelinq', 'Edit automation') : t('pipelinq', 'New automation') }}</h2>
			<div class="builder-header__actions">
				<NcButton @click="cancel">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="saving || !form.name || !form.trigger" @click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="builder-body">
			<div class="field">
				<NcTextField
					:value.sync="form.name"
					:label="t('pipelinq', 'Name')"
					:placeholder="t('pipelinq', 'Automation name')" />
			</div>

			<div class="field">
				<label class="field-label" for="trigger-select">{{ t('pipelinq', 'Trigger') }}</label>
				<NcSelect
					input-id="trigger-select"
					:value="selectedTrigger"
					:options="triggerOptions"
					:input-label="t('pipelinq', 'Trigger')"
					label="label"
					@input="onTriggerInput" />
			</div>

			<div class="field">
				<label class="field-label">{{ t('pipelinq', 'Trigger conditions') }}</label>
				<p class="hint">
					{{ t('pipelinq', 'All conditions must match (AND logic). String comparison is case-insensitive.') }}
				</p>
				<div v-for="(cond, index) in conditions" :key="index" class="condition-row">
					<NcTextField
						:value.sync="cond.field"
						:label="t('pipelinq', 'Field')" />
					<NcTextField
						:value.sync="cond.value"
						:label="t('pipelinq', 'Value')" />
					<NcButton type="tertiary" @click="removeCondition(index)">
						{{ t('pipelinq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addCondition">
					{{ t('pipelinq', 'Add condition') }}
				</NcButton>
			</div>

			<div class="field">
				<label class="field-label">{{ t('pipelinq', 'Actions') }}</label>
				<p class="hint">
					{{ t('pipelinq', 'Actions run in order when the automation fires.') }}
				</p>
				<div v-for="(action, index) in actions" :key="index" class="action-row">
					<NcSelect
						:value="actionOptionFor(action.type)"
						:options="actionOptions"
						:input-label="t('pipelinq', 'Action type')"
						label="label"
						@input="(val) => onActionInput(index, val)" />
					<NcButton type="tertiary" @click="removeAction(index)">
						{{ t('pipelinq', 'Remove') }}
					</NcButton>
				</div>
				<NcButton type="secondary" @click="addAction">
					{{ t('pipelinq', 'Add action') }}
				</NcButton>
			</div>

			<div class="field">
				<NcTextField
					:value.sync="form.webhookUrl"
					:label="t('pipelinq', 'Webhook URL (optional)')"
					:placeholder="t('pipelinq', 'https://...')" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'

const TRIGGER_OPTIONS = [
	{ id: 'lead_created', label: 'Lead created' },
	{ id: 'lead_stage_changed', label: 'Lead stage changed' },
	{ id: 'lead_assigned', label: 'Lead assigned' },
	{ id: 'contact_created', label: 'Contact created' },
	{ id: 'request_created', label: 'Request created' },
	{ id: 'request_status_changed', label: 'Request status changed' },
	{ id: 'marketing_segment_match', label: 'Marketing segment match' },
]

const ACTION_OPTIONS = [
	{ id: 'assign_lead', label: 'Assign lead' },
	{ id: 'move_stage', label: 'Move stage' },
	{ id: 'send_notification', label: 'Send notification' },
	{ id: 'add_note', label: 'Add note' },
	{ id: 'fire_webhook', label: 'Fire webhook' },
	{ id: 'update_tag', label: 'Update tag' },
	{ id: 'apply_decision', label: 'Apply decision' },
]

export default {
	name: 'AutomationBuilder',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			form: {
				name: '',
				trigger: '',
				webhookUrl: '',
			},
			conditions: [],
			actions: [],
			loading: false,
			saving: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isEdit() {
			return Boolean(this.$route.params.id) && this.$route.params.id !== 'new'
		},
		triggerOptions() {
			return TRIGGER_OPTIONS.map((o) => ({ id: o.id, label: this.t('pipelinq', o.label) }))
		},
		actionOptions() {
			return ACTION_OPTIONS.map((o) => ({ id: o.id, label: this.t('pipelinq', o.label) }))
		},
		selectedTrigger() {
			return this.triggerOptions.find((o) => o.id === this.form.trigger) || null
		},
	},
	async created() {
		if (this.isEdit) {
			await this.load()
		}
	},
	methods: {
		/**
		 * Load the automation being edited into the form.
		 */
		async load() {
			this.loading = true
			try {
				const automation = await this.objectStore.fetchObject('automation', this.$route.params.id) || {}
				this.form.name = automation.name || ''
				this.form.trigger = automation.trigger || ''
				this.form.webhookUrl = automation.webhookUrl || ''
				this.conditions = Object.entries(automation.triggerConditions || {})
					.map(([field, value]) => ({ field, value: this.stringifyValue(value) }))
				this.actions = Array.isArray(automation.actions)
					? automation.actions.map((a) => ({ type: a.type || '' }))
					: []
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to load automation.'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Handle a trigger dropdown selection.
		 *
		 * @param {object} option The selected option.
		 */
		onTriggerInput(option) {
			this.form.trigger = option ? option.id : ''
		},
		/**
		 * Return the action option object for a stored type.
		 *
		 * @param {string} type The action type.
		 * @return {object|null} The matching option.
		 */
		actionOptionFor(type) {
			return this.actionOptions.find((o) => o.id === type) || null
		},
		/**
		 * Handle an action dropdown selection.
		 *
		 * @param {number} index  The action row index.
		 * @param {object} option The selected option.
		 */
		onActionInput(index, option) {
			this.actions[index].type = option ? option.id : ''
		},
		/**
		 * Add an empty condition row.
		 */
		addCondition() {
			this.conditions.push({ field: '', value: '' })
		},
		/**
		 * Remove a condition row.
		 *
		 * @param {number} index The row index.
		 */
		removeCondition(index) {
			this.conditions.splice(index, 1)
		},
		/**
		 * Add an empty action row.
		 */
		addAction() {
			this.actions.push({ type: '' })
		},
		/**
		 * Remove an action row.
		 *
		 * @param {number} index The row index.
		 */
		removeAction(index) {
			this.actions.splice(index, 1)
		},
		/**
		 * Build and persist the automation, then navigate to its detail.
		 */
		async save() {
			this.saving = true
			try {
				const payload = {
					name: this.form.name,
					trigger: this.form.trigger,
					webhookUrl: this.form.webhookUrl,
					triggerConditions: this.buildConditions(),
					actions: this.actions.filter((a) => a.type).map((a) => ({ type: a.type })),
				}
				if (this.isEdit) {
					payload.id = this.$route.params.id
				}
				const saved = await this.objectStore.saveObject('automation', payload)
				showSuccess(this.t('pipelinq', 'Automation saved.'))
				const id = (saved && saved.id) || this.$route.params.id
				this.$router.push({ name: 'AutomationDetail', params: { id } })
			} catch (e) {
				showError(this.t('pipelinq', 'Failed to save automation.'))
			} finally {
				this.saving = false
			}
		},
		/**
		 * Build the triggerConditions object from the condition rows.
		 *
		 * @return {object} The condition map.
		 */
		buildConditions() {
			const result = {}
			for (const cond of this.conditions) {
				if (cond.field) {
					result[cond.field] = cond.value
				}
			}
			return result
		},
		/**
		 * Stringify a stored condition value for the form input.
		 *
		 * @param {*} value The stored value.
		 * @return {string} The string form.
		 */
		stringifyValue(value) {
			if (value && typeof value === 'object') {
				return JSON.stringify(value)
			}
			return String(value)
		},
		/**
		 * Navigate back to the automation list.
		 */
		cancel() {
			this.$router.push({ name: 'Automations' })
		},
	},
}
</script>

<style scoped>
.automation-builder {
	max-width: 720px;
	margin: 0 auto;
	padding: 16px;
}
.builder-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}
.builder-header__actions {
	display: flex;
	gap: 8px;
}
.field {
	margin-bottom: 20px;
}
.field-label {
	display: block;
	font-weight: 600;
	margin-bottom: 4px;
}
.hint {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
	margin: 0 0 8px;
}
.condition-row,
.action-row {
	display: flex;
	gap: 8px;
	align-items: flex-end;
	margin-bottom: 8px;
}
</style>

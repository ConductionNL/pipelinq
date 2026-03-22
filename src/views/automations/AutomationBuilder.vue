<template>
	<div class="automation-builder">
		<div class="builder-header">
			<h2>{{ isNew ? t('pipelinq', 'New Automation') : t('pipelinq', 'Edit Automation') }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="builder-form">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Name') }}</label>
				<input v-model="form.name" type="text" :placeholder="t('pipelinq', 'Automation name')" class="form-input">
			</div>

			<div class="form-group">
				<label>{{ t('pipelinq', 'Trigger') }}</label>
				<NcSelect v-model="form.trigger"
					:options="triggerOptions"
					:placeholder="t('pipelinq', 'Select trigger event')"
					label="label"
					:reduce="opt => opt.value" />
			</div>

			<div v-if="form.trigger" class="form-group">
				<label>{{ t('pipelinq', 'Conditions (optional)') }}</label>
				<div v-for="(condition, index) in conditions" :key="index" class="condition-row">
					<input v-model="condition.field" type="text" :placeholder="t('pipelinq', 'Field name')">
					<NcSelect v-model="condition.operator"
						:options="operatorOptions"
						label="label"
						:reduce="opt => opt.value" />
					<input v-model="condition.value" type="text" :placeholder="t('pipelinq', 'Value')">
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

			<div class="form-group">
				<label>{{ t('pipelinq', 'Actions') }}</label>
				<div v-for="(action, index) in form.actions" :key="index" class="action-card">
					<div class="action-header">
						<span class="action-number">{{ index + 1 }}</span>
						<NcSelect v-model="action.type"
							:options="actionOptions"
							label="label"
							:reduce="opt => opt.value"
							class="action-type-select" />
						<NcButton type="tertiary" @click="removeAction(index)">
							<template #icon>
								<Delete :size="20" />
							</template>
						</NcButton>
					</div>
					<div v-if="action.type" class="action-config">
						<template v-if="action.type === 'assign_lead'">
							<input v-model="action.config.assignee"
								type="text"
								:placeholder="t('pipelinq', 'User ID to assign to')">
						</template>
						<template v-else-if="action.type === 'move_stage'">
							<input v-model="action.config.stage"
								type="text"
								:placeholder="t('pipelinq', 'Target stage name')">
						</template>
						<template v-else-if="action.type === 'send_notification'">
							<input v-model="action.config.recipient"
								type="text"
								:placeholder="t('pipelinq', 'Recipient user ID')">
							<input v-model="action.config.message"
								type="text"
								:placeholder="t('pipelinq', 'Notification message')">
						</template>
						<template v-else-if="action.type === 'update_field'">
							<input v-model="action.config.field"
								type="text"
								:placeholder="t('pipelinq', 'Field name')">
							<input v-model="action.config.value"
								type="text"
								:placeholder="t('pipelinq', 'New value')">
						</template>
						<template v-else-if="action.type === 'add_note'">
							<textarea v-model="action.config.message"
								:placeholder="t('pipelinq', 'Note content')" />
						</template>
						<template v-else-if="action.type === 'webhook'">
							<input v-model="action.config.url"
								type="url"
								:placeholder="t('pipelinq', 'Webhook URL')">
						</template>
					</div>
				</div>
				<NcButton type="secondary" @click="addAction">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'Add action') }}
				</NcButton>
			</div>

			<div class="form-group">
				<NcCheckboxRadioSwitch :checked.sync="form.isActive" type="switch">
					{{ t('pipelinq', 'Active') }}
				</NcCheckboxRadioSwitch>
			</div>

			<div v-if="form.trigger" class="form-group">
				<label>{{ t('pipelinq', 'n8n Webhook URL (optional)') }}</label>
				<input v-model="form.webhookUrl"
					type="url"
					:placeholder="t('pipelinq', 'https://n8n.example.com/webhook/...')">
			</div>

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
import { NcButton, NcLoadingIcon, NcSelect, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import { useObjectStore } from '../../store/store.js'
import Plus from 'vue-material-design-icons/Plus.vue'
import Delete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'AutomationBuilder',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSelect,
		NcCheckboxRadioSwitch,
		Plus,
		Delete,
	},
	props: {
		automationId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			loading: false,
			form: {
				name: '',
				trigger: null,
				triggerConditions: {},
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
		canSave() {
			return this.form.name && this.form.trigger && this.form.actions.length > 0
		},
		triggerOptions() {
			return [
				{ value: 'lead_created', label: this.t('pipelinq', 'Lead created') },
				{ value: 'lead_stage_changed', label: this.t('pipelinq', 'Lead stage changed') },
				{ value: 'lead_assigned', label: this.t('pipelinq', 'Lead assigned') },
				{ value: 'lead_value_changed', label: this.t('pipelinq', 'Lead value changed') },
				{ value: 'contact_created', label: this.t('pipelinq', 'Contact created') },
				{ value: 'request_created', label: this.t('pipelinq', 'Request created') },
				{ value: 'request_status_changed', label: this.t('pipelinq', 'Request status changed') },
			]
		},
		actionOptions() {
			return [
				{ value: 'assign_lead', label: this.t('pipelinq', 'Assign lead') },
				{ value: 'move_stage', label: this.t('pipelinq', 'Move to stage') },
				{ value: 'send_notification', label: this.t('pipelinq', 'Send notification') },
				{ value: 'update_field', label: this.t('pipelinq', 'Update field') },
				{ value: 'add_note', label: this.t('pipelinq', 'Add note') },
				{ value: 'webhook', label: this.t('pipelinq', 'Call webhook') },
			]
		},
		operatorOptions() {
			return [
				{ value: 'eq', label: '=' },
				{ value: 'neq', label: '!=' },
				{ value: 'gt', label: '>' },
				{ value: 'gte', label: '>=' },
				{ value: 'lt', label: '<' },
				{ value: 'lte', label: '<=' },
			]
		},
	},
	mounted() {
		if (this.automationId) {
			this.loadAutomation()
		}
	},
	methods: {
		async loadAutomation() {
			this.loading = true
			try {
				const objectStore = useObjectStore()
				const result = await objectStore.fetchObject('automation', this.automationId)
				if (result) {
					this.form = { ...result }
					this.parseConditions()
				}
			} catch (e) {
				console.error('Failed to load automation', e)
			} finally {
				this.loading = false
			}
		},
		parseConditions() {
			const conds = this.form.triggerConditions || {}
			this.conditions = Object.entries(conds).map(([field, val]) => {
				if (typeof val === 'object' && val.operator) {
					return { field, operator: val.operator, value: String(val.value) }
				}
				return { field, operator: 'eq', value: String(val) }
			})
		},
		buildConditions() {
			const result = {}
			for (const c of this.conditions) {
				if (!c.field) continue
				if (c.operator === 'eq') {
					result[c.field] = c.value
				} else {
					result[c.field] = { operator: c.operator, value: c.value }
				}
			}
			return result
		},
		addCondition() {
			this.conditions.push({ field: '', operator: 'eq', value: '' })
		},
		removeCondition(index) {
			this.conditions.splice(index, 1)
		},
		addAction() {
			this.form.actions.push({ type: null, config: {} })
		},
		removeAction(index) {
			this.form.actions.splice(index, 1)
		},
		async save() {
			this.form.triggerConditions = this.buildConditions()
			const objectStore = useObjectStore()
			await objectStore.saveObject('automation', this.form)
			this.$router.push({ name: 'Automations' })
		},
	},
}
</script>

<style scoped>
.automation-builder {
	padding: 20px;
	max-width: 800px;
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

.form-group label {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.form-input {
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.condition-row {
	display: flex;
	gap: 8px;
	align-items: center;
}

.condition-row input {
	flex: 1;
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.action-card {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px;
	margin-bottom: 8px;
}

.action-header {
	display: flex;
	align-items: center;
	gap: 8px;
}

.action-number {
	font-weight: bold;
	color: var(--color-primary);
	min-width: 24px;
}

.action-type-select {
	flex: 1;
}

.action-config {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.action-config input,
.action-config textarea {
	padding: 6px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.action-config textarea {
	min-height: 60px;
	resize: vertical;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>

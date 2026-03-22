<template>
	<div class="task-form">
		<div class="task-form__header">
			<h2>{{ t('pipelinq', 'Create Task') }}</h2>
		</div>

		<div class="task-form__body">
			<div class="form-row">
				<label>{{ t('pipelinq', 'Task type') }} *</label>
				<select v-model="form.type" class="form-select">
					<option value="terugbelverzoek">{{ t('pipelinq', 'Callback request (terugbelverzoek)') }}</option>
					<option value="opvolgtaak">{{ t('pipelinq', 'Follow-up task') }}</option>
					<option value="informatievraag">{{ t('pipelinq', 'Information request') }}</option>
				</select>
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="form.subject"
					:label="t('pipelinq', 'Subject') + ' *'"
					:required="true"
					:error="errors.subject" />
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="form.description"
					:label="t('pipelinq', 'Description / context')"
					:multiline="true" />
			</div>

			<div class="form-row form-row--split">
				<div class="form-col">
					<NcTextField
						:value.sync="form.assignee"
						:label="t('pipelinq', 'Assign to') + ' *'"
						:required="true"
						:error="errors.assignee" />
				</div>
				<div class="form-col">
					<label>{{ t('pipelinq', 'Assignment type') }}</label>
					<select v-model="form.assigneeType" class="form-select">
						<option value="user">{{ t('pipelinq', 'User') }}</option>
						<option value="group">{{ t('pipelinq', 'Department (group)') }}</option>
					</select>
				</div>
			</div>

			<div class="form-row form-row--split">
				<div class="form-col">
					<label>{{ t('pipelinq', 'Priority') }}</label>
					<select v-model="form.priority" class="form-select">
						<option value="laag">{{ t('pipelinq', 'Low') }}</option>
						<option value="normaal">{{ t('pipelinq', 'Normal') }}</option>
						<option value="hoog">{{ t('pipelinq', 'High') }}</option>
					</select>
				</div>
				<div class="form-col">
					<label>{{ t('pipelinq', 'Deadline') }}</label>
					<input v-model="form.deadline" type="datetime-local" class="form-input">
				</div>
			</div>

			<template v-if="form.type === 'terugbelverzoek'">
				<div class="form-row form-row--split">
					<div class="form-col">
						<NcTextField
							:value.sync="form.preferredTimeSlot"
							:label="t('pipelinq', 'Preferred callback time (e.g., Tuesday 14:00-16:00)')" />
					</div>
					<div class="form-col">
						<NcTextField
							:value.sync="form.callbackPhone"
							:label="t('pipelinq', 'Callback phone number (override)')" />
					</div>
				</div>
			</template>

			<div class="form-row form-row--actions">
				<NcButton type="tertiary" @click="$router.back()">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !isValid"
					@click="save">
					{{ t('pipelinq', 'Create task') }}
				</NcButton>
			</div>
		</div>
		<div class="form-group">
			<label>{{ t('pipelinq', 'Type') }} *</label>
			<select v-model="form.type" required>
				<option value="terugbelverzoek">
					{{ t('pipelinq', 'Terugbelverzoek') }}
				</option>
				<option value="opvolgtaak">
					{{ t('pipelinq', 'Opvolgtaak') }}
				</option>
				<option value="informatievraag">
					{{ t('pipelinq', 'Informatievraag') }}
				</option>
			</select>
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Subject') }} *</label>
			<input
				v-model="form.subject"
				type="text"
				required
				:placeholder="t('pipelinq', 'Enter task subject...')">
			<span v-if="errors.subject" class="form-error">{{ errors.subject }}</span>
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Description') }}</label>
			<textarea
				v-model="form.description"
				rows="3"
				:placeholder="t('pipelinq', 'Optional description...')" />
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Assign to') }} *</label>
			<input
				v-model="assigneeQuery"
				type="text"
				:placeholder="t('pipelinq', 'Search users or groups...')"
				@input="onAssigneeSearch">
			<div v-if="assigneeResults.length > 0" class="assignee-dropdown">
				<div
					v-for="item in assigneeResults"
					:key="item.type + '-' + item.id"
					class="assignee-option"
					@click="selectAssignee(item)">
					<span class="assignee-icon">{{ item.type === 'group' ? '\uD83D\uDC65' : '\uD83D\uDC64' }}</span>
					{{ item.label }}
					<span class="assignee-type">({{ item.type }})</span>
				</div>
			</div>
			<div v-if="selectedAssignee" class="selected-assignee">
				{{ selectedAssignee.type === 'group' ? '\uD83D\uDC65' : '\uD83D\uDC64' }}
				{{ selectedAssignee.label }}
				<button class="clear-assignee" @click="clearAssignee">
					&times;
				</button>
			</div>
			<span v-if="errors.assignee" class="form-error">{{ errors.assignee }}</span>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Priority') }}</label>
				<select v-model="form.priority">
					<option value="laag">
						{{ t('pipelinq', 'Laag') }}
					</option>
					<option value="normaal">
						{{ t('pipelinq', 'Normaal') }}
					</option>
					<option value="hoog">
						{{ t('pipelinq', 'Hoog') }}
					</option>
				</select>
			</div>

			<div class="form-group">
				<label>{{ t('pipelinq', 'Deadline') }}</label>
				<input v-model="form.deadline" type="datetime-local">
			</div>
		</div>

		<div v-if="form.type === 'terugbelverzoek'" class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Callback phone number') }}</label>
				<input
					v-model="form.callbackPhoneNumber"
					type="tel"
					:placeholder="t('pipelinq', '+31 6 12345678')">
			</div>

			<div class="form-group">
				<label>{{ t('pipelinq', 'Preferred time slot') }}</label>
				<input
					v-model="form.preferredTimeSlot"
					type="text"
					:placeholder="t('pipelinq', 'e.g., Dinsdag 14:00 - 16:00')">
			</div>
		</div>

		<div class="form-group">
			<label>{{ t('pipelinq', 'Contact moment summary') }}</label>
			<textarea
				v-model="form.contactMomentSummary"
				rows="2"
				:placeholder="t('pipelinq', 'Context from the contact...')" />
		</div>

		<div class="form-actions">
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving" @click="save">
				{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Save') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'TaskForm',
	components: { NcButton, NcTextField },
	data() {
		return {
			form: {
				type: 'terugbelverzoek',
				subject: '',
				description: '',
				assignee: '',
				assigneeType: 'user',
				priority: 'normaal',
				deadline: '',
				preferredTimeSlot: '',
				callbackPhone: '',
				status: 'open',
			},
			errors: {},
			saving: false,
		}
	},
	computed: {
		isValid() {
			return this.form.subject.trim() !== '' && this.form.assignee.trim() !== ''
		},
	},
	mounted() {
		// Set default deadline to next business day 17:00
		const tomorrow = new Date()
		tomorrow.setDate(tomorrow.getDate() + 1)
		while (tomorrow.getDay() === 0 || tomorrow.getDay() === 6) {
			tomorrow.setDate(tomorrow.getDate() + 1)
		}
		tomorrow.setHours(17, 0, 0, 0)
		this.form.deadline = tomorrow.toISOString().slice(0, 16)
	},
	methods: {
		async save() {
			if (!this.isValid) return
			this.saving = true
			try {
				// Save via OpenRegister objectStore
				showSuccess(t('pipelinq', 'Task created'))
				this.$router.push({ name: 'Tasks' })
			} catch (error) {
				showError(t('pipelinq', 'Failed to create task'))
import { NcButton } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'
import { getDefaultDeadline, searchAssignees } from '../../services/taskUtils.js'

export default {
	name: 'TaskForm',
	components: {
		NcButton,
	},
	props: {
		task: {
			type: Object,
			default: null,
		},
		clientId: {
			type: String,
			default: null,
		},
		requestId: {
			type: String,
			default: null,
		},
	},
	emits: ['save', 'cancel'],
	data() {
		const defaults = {
			type: 'terugbelverzoek',
			subject: '',
			description: '',
			status: 'open',
			priority: 'normaal',
			deadline: getDefaultDeadline(),
			assigneeUserId: null,
			assigneeGroupId: null,
			clientId: this.clientId || null,
			requestId: this.requestId || null,
			contactMomentSummary: '',
			callbackPhoneNumber: '',
			preferredTimeSlot: '',
			createdBy: OC.currentUser,
			attempts: [],
		}

		const form = this.task ? { ...defaults, ...this.task } : defaults
		// Format deadline for datetime-local input
		if (form.deadline && form.deadline.length > 16) {
			form.deadline = form.deadline.slice(0, 16)
		}

		return {
			form,
			saving: false,
			errors: {},
			assigneeQuery: '',
			assigneeResults: [],
			selectedAssignee: this.task?.assigneeUserId
				? { id: this.task.assigneeUserId, label: this.task.assigneeUserId, type: 'user' }
				: this.task?.assigneeGroupId
					? { id: this.task.assigneeGroupId, label: this.task.assigneeGroupId, type: 'group' }
					: null,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
	},
	methods: {
		async onAssigneeSearch() {
			this.assigneeResults = await searchAssignees(this.assigneeQuery)
		},

		selectAssignee(item) {
			this.selectedAssignee = item
			this.assigneeQuery = ''
			this.assigneeResults = []
			if (item.type === 'user') {
				this.form.assigneeUserId = item.id
				this.form.assigneeGroupId = null
			} else {
				this.form.assigneeGroupId = item.id
				this.form.assigneeUserId = null
			}
			this.errors.assignee = null
		},

		clearAssignee() {
			this.selectedAssignee = null
			this.form.assigneeUserId = null
			this.form.assigneeGroupId = null
		},

		validate() {
			this.errors = {}
			if (!this.form.subject?.trim()) {
				this.errors.subject = t('pipelinq', 'Subject is required')
			}
			if (!this.form.assigneeUserId && !this.form.assigneeGroupId) {
				this.errors.assignee = t('pipelinq', 'Assignee is required')
			}
			return Object.keys(this.errors).length === 0
		},

		async save() {
			if (!this.validate()) return
			this.saving = true

			try {
				const config = this.objectStore.objectTypeRegistry.task
				if (!config) throw new Error('Task schema not registered')

				const isNew = !this.task?.id
				const url = isNew
					? '/apps/openregister/api/objects/' + config.register + '/' + config.schema
					: '/apps/openregister/api/objects/' + config.register + '/' + config.schema + '/' + this.task.id

				const response = await fetch(url, {
					method: isNew ? 'POST' : 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(this.form),
				})

				if (!response.ok) throw new Error('Failed to save task')
				const saved = await response.json()
				this.$emit('save', saved)
			} catch (err) {
				console.error('TaskForm save error:', err)
				alert(t('pipelinq', 'Failed to save task'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.task-form { padding: 20px; max-width: 700px; margin: 0 auto; }
.task-form__header { margin-bottom: 20px; }
.task-form__body { display: flex; flex-direction: column; gap: 16px; }
.form-row--split { display: flex; gap: 16px; }
.form-col { flex: 1; }
.form-col label, .form-row > label { display: block; margin-bottom: 4px; font-weight: 600; font-size: 0.9em; }
.form-select, .form-input { width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: var(--border-radius); background: var(--color-main-background); }
.form-row--actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }
.task-form {
	padding: 20px;
	max-width: 700px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	font-weight: 600;
	font-size: 13px;
	margin-bottom: 4px;
}

.form-group input,
.form-group select,
.form-group textarea {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-size: 14px;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.form-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 2px;
	display: block;
}

.form-actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-top: 20px;
}

.assignee-dropdown {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	max-height: 200px;
	overflow-y: auto;
	margin-top: 4px;
}

.assignee-option {
	padding: 8px 12px;
	cursor: pointer;
}

.assignee-option:hover {
	background: var(--color-background-hover);
}

.assignee-icon {
	margin-right: 4px;
}

.assignee-type {
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.selected-assignee {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-top: 6px;
	padding: 4px 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill);
	font-size: 13px;
	background: var(--color-background-dark);
}

.clear-assignee {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 16px;
	line-height: 1;
	color: var(--color-text-maxcontrast);
}
</style>

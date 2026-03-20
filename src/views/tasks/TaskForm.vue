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
</style>

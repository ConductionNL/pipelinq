<template>
	<div class="contactmoment-quicklog">
		<h3 v-if="!inline">
			{{ t('pipelinq', 'Log contactmoment') }}
		</h3>

		<!-- Subject -->
		<div class="form-group">
			<NcTextField
				:value="form.subject"
				:label="t('pipelinq', 'Subject')"
				:error="!!errors.subject"
				:helper-text="errors.subject"
				@update:value="v => form.subject = v" />
		</div>

		<!-- Channel + Outcome row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Channel') }}</label>
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:clearable="false"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Outcome') }}</label>
				<NcSelect
					v-model="form.outcome"
					:options="outcomeOptions"
					:clearable="true"
					:placeholder="t('pipelinq', 'Select outcome')" />
			</div>
		</div>

		<!-- Client -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Client') }}</label>
			<NcSelect
				v-model="form.client"
				:options="clientSelectOptions"
				:clearable="true"
				label="label"
				:reduce="o => o.value"
				:placeholder="t('pipelinq', 'Select client')" />
		</div>

		<!-- Request -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Request') }}</label>
			<NcSelect
				v-model="form.request"
				:options="requestSelectOptions"
				:clearable="true"
				label="label"
				:reduce="o => o.value"
				:placeholder="t('pipelinq', 'Select request')" />
		</div>

		<!-- Summary -->
		<div class="form-group">
			<NcTextField
				:value="form.summary"
				:label="t('pipelinq', 'Summary')"
				@update:value="v => form.summary = v" />
		</div>

		<!-- Duration -->
		<div class="form-group">
			<NcTextField
				:value="form.duration"
				:label="t('pipelinq', 'Duration (e.g. PT5M, PT1H30M)')"
				@update:value="v => form.duration = v" />
		</div>

		<!-- Notes -->
		<div class="form-group">
			<NcTextField
				:value="form.notes"
				:label="t('pipelinq', 'Notes')"
				@update:value="v => form.notes = v" />
		</div>

		<!-- Actions -->
		<div class="form-actions">
			<NcButton type="tertiary" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="!isValid || saving" @click="onSave">
				{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Save') }}
			</NcButton>
		</div>

		<div v-if="errorMessage" class="form-error">
			{{ errorMessage }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'ContactmomentQuickLog',
	components: {
		NcButton,
		NcSelect,
		NcTextField,
	},
	props: {
		clientId: {
			type: String,
			default: null,
		},
		requestId: {
			type: String,
			default: null,
		},
		inline: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			form: {
				subject: '',
				channel: null,
				outcome: null,
				client: null,
				request: null,
				summary: '',
				duration: '',
				notes: '',
			},
			channelOptions: [
				'telefoon',
				'email',
				'balie',
				'chat',
				'social',
				'brief',
			],
			outcomeOptions: [
				'afgehandeld',
				'doorverbonden',
				'terugbelverzoek',
				'vervolgactie',
			],
			saving: false,
			errorMessage: '',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		clients() {
			return this.objectStore.collections.client || []
		},
		clientSelectOptions() {
			return this.clients.map(c => ({
				value: c.id,
				label: c.name || c.id,
			}))
		},
		requests() {
			return this.objectStore.collections.request || []
		},
		requestSelectOptions() {
			return this.requests.map(r => ({
				value: r.id,
				label: r.title || r.id,
			}))
		},
		errors() {
			const errors = {}
			if (!this.form.subject || !this.form.subject.trim()) {
				errors.subject = t('pipelinq', 'Subject is required')
			}
			if (!this.form.channel) {
				errors.channel = t('pipelinq', 'Channel is required')
			}
			return errors
		},
		isValid() {
			return this.form.subject?.trim() && this.form.channel
		},
	},
	async created() {
		await Promise.all([
			this.objectStore.fetchCollection('client', { _limit: 100 }),
			this.objectStore.fetchCollection('request', { _limit: 100 }),
		])

		if (this.clientId) {
			this.form.client = this.clientId
		}
		if (this.requestId) {
			this.form.request = this.requestId
			// If request has a client, pre-fill that too
			const req = this.requests.find(r => r.id === this.requestId)
			if (req?.client && !this.clientId) {
				this.form.client = req.client
			}
		}
	},
	methods: {
		async onSave() {
			if (!this.isValid) return

			this.saving = true
			this.errorMessage = ''

			const data = {
				subject: this.form.subject,
				channel: this.form.channel,
				contactedAt: new Date().toISOString(),
				agent: OC.currentUser,
				channelMetadata: {},
			}

			if (this.form.outcome) data.outcome = this.form.outcome
			if (this.form.client) data.client = this.form.client
			if (this.form.request) data.request = this.form.request
			if (this.form.summary) data.summary = this.form.summary
			if (this.form.duration) data.duration = this.form.duration
			if (this.form.notes) data.notes = this.form.notes

			try {
				const result = await this.objectStore.saveObject('contactmoment', data)
				if (result) {
					showSuccess(t('pipelinq', 'Contactmoment logged successfully'))
					this.$emit('saved', result)
				} else {
					const error = this.objectStore.getError('contactmoment')
					this.errorMessage = error?.message || t('pipelinq', 'Failed to save contactmoment')
					showError(this.errorMessage)
				}
			} catch (error) {
				this.errorMessage = error.message || t('pipelinq', 'Failed to save contactmoment')
				showError(this.errorMessage)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.contactmoment-quicklog {
	max-width: 600px;
}

.contactmoment-quicklog h3 {
	margin: 0 0 16px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 20px;
}

.form-error {
	margin-top: 12px;
	padding: 8px 12px;
	background: var(--color-error);
	color: white;
	border-radius: var(--border-radius);
	font-size: 13px;
}
</style>

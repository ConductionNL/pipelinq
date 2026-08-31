<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - ContactmomentQuickLog.vue
  -
  - Quick-log form for a contactmoment. Since unify-ticket-supertype the
  - contactmoment is not its own schema: it is a `ticket` object carrying
  - `ticketType: 'interaction'`. The form therefore writes the unified ticket
  - fields (title / description / occurredAt / assignee / parentTicket) while the
  - UI keeps the familiar contactmoment wording (Subject, Summary, Request).
  -->

<template>
	<div class="contactmoment-quicklog">
		<h3 v-if="!inline">
			{{ t('pipelinq', 'Log contactmoment') }}
		</h3>

		<!-- Subject → ticket.title -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.title"
				:label="t('pipelinq', 'Subject')"
				:error="!!errors.title"
				:helperText="errors.title"
				@update:modelValue="(v) => (form.title = v)" />
		</div>

		<!-- Channel + Outcome row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Channel') }}</label>
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:aria-label-combobox="t('pipelinq', 'Channel')"
					:clearable="false"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Outcome') }}</label>
				<NcSelect
					v-model="form.outcome"
					:options="outcomeOptions"
					:aria-label-combobox="t('pipelinq', 'Outcome')"
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
				:aria-label-combobox="t('pipelinq', 'Client')"
				:clearable="true"
				label="label"
				:reduce="(o) => o.value"
				:placeholder="t('pipelinq', 'Select client')" />
		</div>

		<!-- Request → ticket.parentTicket (a request-type ticket) -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Request') }}</label>
			<NcSelect
				v-model="form.parentTicket"
				:options="requestSelectOptions"
				:aria-label-combobox="t('pipelinq', 'Request')"
				:clearable="true"
				label="label"
				:reduce="(o) => o.value"
				:placeholder="t('pipelinq', 'Select request')" />
		</div>

		<!-- Summary → ticket.description -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.description"
				:label="t('pipelinq', 'Summary')"
				@update:modelValue="(v) => (form.description = v)" />
		</div>

		<!-- Duration -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.duration"
				:label="t('pipelinq', 'Duration (e.g. PT5M, PT1H30M)')"
				@update:modelValue="(v) => (form.duration = v)" />
		</div>

		<!-- Notes -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.notes"
				:label="t('pipelinq', 'Notes')"
				@update:modelValue="(v) => (form.notes = v)" />
		</div>

		<!-- Actions -->
		<div class="form-actions">
			<NcButton variant="tertiary" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!isValid || saving"
				@click="onSave">
				{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
			</NcButton>
		</div>

		<div v-if="errorMessage" class="form-error">
			{{ errorMessage }}
		</div>
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
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

	emits: ['cancel', 'saved'],

	data() {
		return {
			// Ticket fields, written verbatim to the `ticket` schema on save.
			form: {
				title: '',
				channel: null,
				outcome: null,
				client: null,
				parentTicket: null,
				description: '',
				duration: '',
				notes: '',
			},

			// Request-type tickets for the "Request" (parent ticket) dropdown.
			// Held locally rather than read from `objectStore.collections.ticket`,
			// which is a shared, unnarrowed key any other ticket view may overwrite.
			requests: [],
			channelOptions: [
				'telefoon',
				'email',
				'balie',
				'chat',
				'social',
				'brief',
			],

			outcomeOptions: [
				'handled',
				'transferred',
				'callbackRequest',
				'followUpAction',
			],

			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-23
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-20
		 */
		clients() {
			return this.objectStore.collections.client || []
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-19
		 */
		clientSelectOptions() {
			return this.clients.map((c) => ({
				value: c.id,
				label: c.name || c.id,
			}))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-25
		 */
		requestSelectOptions() {
			return this.requests.map((r) => ({
				value: r.id,
				label: r.title || r.id,
			}))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-22
		 */
		errors() {
			const errors = {}
			if (!this.form.title || !this.form.title.trim()) {
				errors.title = t('pipelinq', 'Subject is required')
			}
			if (!this.form.channel) {
				errors.channel = t('pipelinq', 'Channel is required')
			}
			return errors
		},

		isValid() {
			return this.form.title?.trim() && this.form.channel
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-21
	 */
	async created() {
		const [, requests] = await Promise.all([
			this.objectStore.fetchCollection('client', { _limit: 100 }),
			// Request-type tickets only — the unified `ticket` schema is narrowed
			// by its `ticketType` discriminator (unify-ticket-supertype).
			this.objectStore.fetchCollection('ticket', {
				ticketType: 'request',
				_limit: 100,
			}),
		])
		this.requests = requests || []

		if (this.clientId) {
			this.form.client = this.clientId
		}
		if (this.requestId) {
			this.form.parentTicket = this.requestId
			// If the request has a client, pre-fill that too
			const req = this.requests.find((r) => r.id === this.requestId)
			if (req?.client && !this.clientId) {
				this.form.client = req.client
			}
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-24
		 */
		async onSave() {
			if (!this.isValid) return

			this.saving = true
			this.errorMessage = ''

			// A contactmoment is a `ticket` with ticketType 'interaction'
			// (unify-ticket-supertype): subject→title, summary→description,
			// contactedAt→occurredAt, agent→assignee, request→parentTicket.
			const data = {
				ticketType: 'interaction',
				title: this.form.title,
				channel: this.form.channel,
				occurredAt: new Date().toISOString(),
				assignee: window.OC?.getCurrentUser?.()?.uid,
				channelMetadata: {},
			}

			if (this.form.outcome) data.outcome = this.form.outcome
			if (this.form.client) data.client = this.form.client
			if (this.form.parentTicket) data.parentTicket = this.form.parentTicket
			if (this.form.description) data.description = this.form.description
			if (this.form.duration) data.duration = this.form.duration
			if (this.form.notes) data.notes = this.form.notes

			try {
				const result = await this.objectStore.saveObject('ticket', data)
				if (result) {
					showSuccess(t('pipelinq', 'Contactmoment logged successfully'))
					this.$emit('saved', result)
				} else {
					const error = this.objectStore.getError('ticket')
					this.errorMessage =
						error?.message
						|| t('pipelinq', 'Failed to save contactmoment')
					showError(this.errorMessage)
				}
			} catch (error) {
				this.errorMessage =
					error.message || t('pipelinq', 'Failed to save contactmoment')
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

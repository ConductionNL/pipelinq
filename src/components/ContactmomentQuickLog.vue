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
  -
  - While the agent types, the form is kept as that same ticket in
  - OpenRegister's status `draft` (contact-moments-keep-draft, decisions 180
  - and 216): two seconds after the last change and when the tab goes hidden.
  - Opening the quick log again offers the draft back. Save makes the draft
  - definitive through OpenRegister's owner action, and a save that fails
  - because the session ended keeps the text on screen.
  -->

<template>
	<div class="contactmoment-quicklog" data-testid="contactmoment-quicklog">
		<h3 v-if="!inline">
			{{ t('pipelinq', 'Log contactmoment') }}
		</h3>

		<NcNoteCard
			v-if="offeredDraft"
			type="info"
			data-testid="contactmoment-draft-offer">
			<p>
				{{
					t(
						'pipelinq',
						'You have an unsaved contact moment from {time}.',
						{
							time: offeredDraftTime,
						},
					)
				}}
			</p>
			<div class="draft-actions">
				<NcButton
					variant="primary"
					data-testid="contactmoment-draft-restore"
					@click="restoreDraft">
					{{ t('pipelinq', 'Restore draft') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					data-testid="contactmoment-draft-discard"
					@click="discardDraft">
					{{ t('pipelinq', 'Discard') }}
				</NcButton>
			</div>
		</NcNoteCard>

		<NcNoteCard
			v-if="sessionEnded"
			type="warning"
			data-testid="contactmoment-session-ended">
			{{
				t(
					'pipelinq',
					'Your session has ended. Log in again in a new tab, then press Save here.',
				)
			}}
		</NcNoteCard>

		<!-- Subject → ticket.title -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.title"
				:label="t('pipelinq', 'Subject')"
				:error="!!shownErrors.title"
				:helperText="shownErrors.title"
				@blur="touched.title = true"
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
					labelOutside
					:clearable="false"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Outcome') }}</label>
				<NcSelect
					v-model="form.outcome"
					:options="outcomeOptions"
					:aria-label-combobox="t('pipelinq', 'Outcome')"
					labelOutside
					:clearable="true"
					:placeholder="t('pipelinq', 'Select outcome')" />
			</div>
		</div>

		<!-- Client + Contact → ticket.client / ticket.contact. Contact is scoped
		     to the chosen client and stays disabled until there is one; both can
		     create what they cannot find. Shared with the lead and request forms
		     through linkedPartyCascadeMixin. -->
		<div class="form-row">
			<div class="form-group" data-testid="contactmoment-form-client">
				<CnResourceSelect
					register="pipelinq"
					schema="client"
					labelField="name"
					:modelValue="form.client || ''"
					:inputLabel="t('pipelinq', 'Client')"
					:placeholder="t('pipelinq', 'Select or create a client')"
					:preload="true"
					:createHandler="createClient"
					@update:modelValue="onClientChange" />
			</div>
			<div class="form-group" data-testid="contactmoment-form-contact">
				<CnResourceSelect
					register="pipelinq"
					schema="contact"
					labelField="name"
					:modelValue="form.contact || ''"
					:inputLabel="t('pipelinq', 'Contact')"
					:filters="contactFilters"
					:disabled="!form.client"
					:preload="true"
					:createHandler="createContact"
					:placeholder="
						form.client
							? t('pipelinq', 'Select or create a contact')
							: t('pipelinq', 'Select a client first')
					"
					@update:modelValue="(v) => (form.contact = v || null)" />
			</div>
		</div>

		<ClientCreateDialog
			v-if="clientDialogOpen"
			:name="pendingName"
			stayOnPage
			@created="onClientCreated"
			@close="closeClientDialog" />
		<ContactCreateDialog
			v-if="contactDialogOpen"
			:client="form.client"
			:name="pendingName"
			@created="onContactCreated"
			@close="closeContactDialog" />

		<!-- Request → ticket.parentTicket (a request-type ticket) -->
		<div class="form-group">
			<label>{{ t('pipelinq', 'Request') }}</label>
			<NcSelect
				v-model="form.parentTicket"
				:options="requestSelectOptions"
				:aria-label-combobox="t('pipelinq', 'Request')"
				labelOutside
				:clearable="true"
				label="label"
				:reduce="(o) => o.value"
				:placeholder="t('pipelinq', 'Select request')" />
		</div>

		<!-- Summary → ticket.description -->
		<div class="form-group">
			<NcTextArea
				:modelValue="form.description"
				:label="t('pipelinq', 'Summary')"
				resize="vertical"
				rows="3"
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
			<NcTextArea
				:modelValue="form.notes"
				:label="t('pipelinq', 'Notes')"
				resize="vertical"
				rows="3"
				@update:modelValue="(v) => (form.notes = v)" />
		</div>

		<!-- Actions -->
		<div class="form-actions">
			<NcButton variant="secondary" @click="$emit('cancel')">
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
import { CnResourceSelect } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcNoteCard, NcSelect, NcTextArea, NcTextField } from '@nextcloud/vue'
import ClientCreateDialog from '../dialogs/ClientCreateDialog.vue'
import ContactCreateDialog from '../dialogs/ContactCreateDialog.vue'
import contactMomentDraftMixin from '../mixins/contactMomentDraftMixin.js'
import linkedPartyCascadeMixin from '../mixins/linkedPartyCascadeMixin.js'
import { buildContactMoment } from '../services/contactMomentDraft.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'ContactmomentQuickLog',
	components: {
		ClientCreateDialog,
		CnResourceSelect,
		ContactCreateDialog,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	mixins: [linkedPartyCascadeMixin, contactMomentDraftMixin],

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
				contact: null,
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
			// Fields the agent has left; their errors show from then on.
			touched: { title: false },
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

		/**
		 * The errors of the fields the agent has left, so an empty form does
		 * not open with a complaint.
		 *
		 * @return {object}
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-22
		 */
		shownErrors() {
			return Object.fromEntries(Object.entries(this.errors).filter(([field]) => this.touched[field]))
		},

		isValid() {
			return this.form.title?.trim() && this.form.channel
		},

		/**
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		draftContext() {
			return {
				author: this.author,
				clientId: this.clientId || null,
				requestId: this.requestId || null,
			}
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-21
	 */
	async created() {
		// Clients and contacts are no longer fetched here: CnResourceSelect
		// searches them server-side, which is the point — the old preloaded
		// `_limit: 100` collection made client 101 unselectable with no way to
		// tell from the UI that it had been cut off.
		//
		// Request-type tickets only — the unified `ticket` schema is narrowed
		// by its `ticketType` discriminator (unify-ticket-supertype).
		const requests = await this.objectStore.fetchCollection('ticket', {
			ticketType: 'request',
			_limit: 100,
		})
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

		await this.loadDraft()
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
			const data = buildContactMoment(this.form, {
				author: this.author,
				now: new Date(),
			})

			try {
				const result = await this.saveContactMoment(data)
				if (result) {
					showSuccess(t('pipelinq', 'Contactmoment logged successfully'))
					this.$emit('saved', result)
				}
			} catch (error) {
				this.keepFormAfterFailure(error)
			} finally {
				this.saving = false
			}
		},

		/**
		 * A failed save keeps the form and its draft. An ended session gets
		 * its own message instead of the raw error.
		 *
		 * @param {Error} error The failure `saveContactMoment` threw.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.4
		 */
		keepFormAfterFailure(error) {
			if (error?.sessionEnded) {
				this.errorMessage = ''
				return
			}
			this.errorMessage =
				error?.message || t('pipelinq', 'Failed to save contactmoment')
			showError(this.errorMessage)
		},
	},
}
</script>

<style scoped>
.contactmoment-quicklog {
	max-width: 600px;
	min-width: 0;
}

.contactmoment-quicklog h3 {
	margin: 0 0 16px;
}

.form-group {
	min-width: 0;
	margin-bottom: 16px;
}

/* NcSelect keeps a 260px minimum, which pushed a half-width card's paired fields past its edge. */
.form-group :deep(.v-select.select) {
	min-width: 0;
	width: 100%;
}

.form-group label {
	display: block;
	font-weight: bold;
	margin-bottom: 4px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.form-row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
	gap: 12px;
}

.draft-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
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

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
  - The form is kept as a private `contactMomentDraft` while the agent types
  - (contact-moments-keep-draft): two seconds after the last change and when
  - the tab goes hidden. Opening the quick log again offers the draft back, and
  - a save that fails because the session ended keeps the text on screen.
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
import { CnResourceSelect } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import ClientCreateDialog from '../dialogs/ClientCreateDialog.vue'
import ContactCreateDialog from '../dialogs/ContactCreateDialog.vue'
import linkedPartyCascadeMixin from '../mixins/linkedPartyCascadeMixin.js'
import {
	buildDraftPayload,
	DRAFT_TYPE,
	DraftAutosaver,
	isDraftFormEmpty,
	isSessionEnded,
	pickDraft,
	refreshRequestToken,
} from '../services/contactMomentDraft.js'
import { useObjectStore } from '../store/modules/object.js'

/**
 * An error that carries the HTTP status of the failed draft write.
 *
 * @param {object|null} error The store's error object.
 * @return {Error} The error.
 */
function draftError(error) {
	const failure = new Error(error?.message || 'Draft not saved')
	failure.status = error?.status
	return failure
}

export default {
	name: 'ContactmomentQuickLog',
	components: {
		ClientCreateDialog,
		CnResourceSelect,
		ContactCreateDialog,
		NcButton,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	mixins: [linkedPartyCascadeMixin],

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

			// The draft kept on the server for this author, client and request.
			draftId: null,
			offeredDraft: null,
			// Set when a save or an autosave was refused because the session
			// ended; the next Save fetches a fresh request token first.
			sessionEnded: false,
			// Off until the restore question is answered, so a draft that is
			// still being offered is never overwritten by the empty form.
			draftWatching: false,
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

		isValid() {
			return this.form.title?.trim() && this.form.channel
		},

		/**
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		author() {
			return window.OC?.getCurrentUser?.()?.uid || null
		},

		/**
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		offeredDraftTime() {
			const changed = this.offeredDraft?.updatedAt
			return changed ? new Date(changed).toLocaleString() : ''
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

	watch: {
		form: {
			deep: true,
			/**
			 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
			 */
			handler() {
				if (!this.draftWatching || !this.author) {
					return
				}
				this.autosaver.schedule(
					isDraftFormEmpty(this.form, this.draftContext)
						? null
						: buildDraftPayload(this.form, {
								...this.draftContext,
								now: new Date(),
							}),
				)
			},
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-21
	 */
	async created() {
		this.autosaver = new DraftAutosaver({
			write: (payload, options) => this.writeDraft(payload, options),
			remove: (options) => this.removeDraft(options),
			onError: (error) => {
				if (isSessionEnded(error)) {
					this.sessionEnded = true
				}
			},
		})
		this.onVisibilityChange = () => {
			if (document.visibilityState === 'hidden') {
				this.autosaver.flush({ keepalive: true })
			}
		}
		document.addEventListener('visibilitychange', this.onVisibilityChange)

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

	/**
	 * Keep what was typed when the quick log closes without saving.
	 *
	 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
	 */
	beforeUnmount() {
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
		this.autosaver.flush()
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-24
		 */
		async onSave() {
			if (!this.isValid) return

			this.saving = true
			this.errorMessage = ''
			// Nothing may write the draft back while the contact moment is saved.
			this.autosaver.stop()

			if (this.sessionEnded) {
				await refreshRequestToken(
					(...args) => fetch(...args),
					generateUrl('/csrftoken'),
				)
			}

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
			if (this.form.contact) data.contact = this.form.contact
			if (this.form.parentTicket) data.parentTicket = this.form.parentTicket
			if (this.form.description) data.description = this.form.description
			if (this.form.duration) data.duration = this.form.duration
			if (this.form.notes) data.notes = this.form.notes

			try {
				const result = await this.objectStore.saveObject('ticket', data)
				if (result) {
					this.sessionEnded = false
					await this.dropDraft()
					showSuccess(t('pipelinq', 'Contactmoment logged successfully'))
					this.$emit('saved', result)
				} else {
					const error = this.objectStore.getError('ticket')
					this.keepFormAfterFailure(error)
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
		 * @param {object|null} error The store's error object.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.4
		 */
		keepFormAfterFailure(error) {
			this.autosaver.resume()
			if (isSessionEnded(error)) {
				this.sessionEnded = true
				this.errorMessage = ''
				return
			}
			this.errorMessage =
				error?.message || t('pipelinq', 'Failed to save contactmoment')
			showError(this.errorMessage)
		},

		/**
		 * Find this author's draft for this client and request, and offer it.
		 * Expired and duplicate drafts are removed on the way.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async loadDraft() {
			if (!this.author) {
				return
			}
			let drafts
			try {
				drafts = await this.objectStore.fetchCollection(DRAFT_TYPE, {
					author: this.author,
					_limit: 50,
				})
			} catch {
				drafts = []
			}
			const { offer, stale } = pickDraft(drafts || [], {
				...this.draftContext,
				now: new Date(),
			})
			for (const draft of stale) {
				this.objectStore.deleteObject(DRAFT_TYPE, draft.id)
			}
			if (offer) {
				this.draftId = offer.id
				this.offeredDraft = offer
				return
			}
			this.draftWatching = true
		},

		/**
		 * Put the draft's fields back in the form.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		restoreDraft() {
			const kept = this.offeredDraft?.form || {}
			for (const key of Object.keys(this.form)) {
				if (kept[key] !== undefined) {
					this.form[key] = kept[key]
				}
			}
			this.offeredDraft = null
			this.draftWatching = true
		},

		/**
		 * Throw the draft away and start from an empty form.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async discardDraft() {
			this.offeredDraft = null
			await this.dropDraft()
			this.autosaver.resume()
			this.draftWatching = true
		},

		/**
		 * Remove the server draft, if there is one.
		 *
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.3
		 */
		async dropDraft() {
			this.autosaver.stop()
			if (this.draftId) {
				const id = this.draftId
				this.draftId = null
				await this.objectStore.deleteObject(DRAFT_TYPE, id)
			}
		},

		/**
		 * Write the draft: create it once, then overwrite it.
		 *
		 * @param {object} payload The draft.
		 * @param {{keepalive: boolean}} options Keepalive for a closing tab.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		async writeDraft(payload, { keepalive }) {
			if (keepalive) {
				await this.sendDraftKeepalive(
					this.draftId ? 'PUT' : 'POST',
					this.draftId,
					payload,
				)
				return
			}
			const data = this.draftId ? { ...payload, id: this.draftId } : payload
			const saved = await this.objectStore.saveObject(DRAFT_TYPE, data)
			if (!saved) {
				throw draftError(this.objectStore.getError(DRAFT_TYPE))
			}
			this.draftId = saved.id || this.draftId
		},

		/**
		 * Remove the draft because the form was emptied.
		 *
		 * @param {{keepalive: boolean}} options Keepalive for a closing tab.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		async removeDraft({ keepalive }) {
			if (!this.draftId) {
				return
			}
			const id = this.draftId
			this.draftId = null
			if (keepalive) {
				await this.sendDraftKeepalive('DELETE', id, null)
				return
			}
			await this.objectStore.deleteObject(DRAFT_TYPE, id)
		},

		/**
		 * The write a closing tab still delivers.
		 *
		 * @param {string} method POST, PUT or DELETE.
		 * @param {string|null} id The draft's id, when it exists.
		 * @param {object|null} payload The draft.
		 * @spec openspec/changes/contact-moments-keep-draft/tasks.md#task-1.2
		 */
		async sendDraftKeepalive(method, id, payload) {
			const url = generateUrl(
				'/apps/openregister/api/objects/pipelinq/'
					+ DRAFT_TYPE
					+ (id ? '/' + id : ''),
			)
			const response = await fetch(url, {
				method,
				keepalive: true,
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					requesttoken: window.OC?.requestToken || '',
					'OCS-APIREQUEST': 'true',
				},
				body: payload ? JSON.stringify(payload) : undefined,
			})
			if (!response.ok) {
				throw draftError({ status: response.status })
			}
			// Hidden is also a switch to another tab, after which this page
			// keeps typing: remember the id so the next write overwrites.
			if (method === 'POST') {
				try {
					const created = await response.json()
					this.draftId = created?.id || this.draftId
				} catch {
					// The tab closed before the answer was read.
				}
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

.draft-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
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

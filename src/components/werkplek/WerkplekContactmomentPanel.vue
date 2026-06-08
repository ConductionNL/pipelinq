<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3 -->
<template>
	<div class="werkplek-cm-panel">
		<h3 class="werkplek-cm-panel__title">
			{{ t('pipelinq', 'Active interaction') }}
		</h3>

		<div v-if="contextLabel" class="werkplek-cm-panel__context">
			<span class="werkplek-cm-panel__context-label">{{ t('pipelinq', 'Context') }}:</span>
			{{ contextLabel }}
			<button
				type="button"
				class="werkplek-cm-panel__context-clear"
				:aria-label="t('pipelinq', 'Clear context')"
				@click="clearContext">
				×
			</button>
		</div>

		<div class="werkplek-cm-panel__row">
			<div class="werkplek-cm-panel__field">
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:input-label="t('pipelinq', 'Channel')"
					label="label"
					:reduce="o => o.value"
					:clearable="false" />
			</div>

			<div class="werkplek-cm-panel__field werkplek-cm-panel__field--timer">
				<CallTimer
					v-if="form.channel === 'telefoon'"
					@stopped="onTimerStopped" />
			</div>
		</div>

		<div class="werkplek-cm-panel__field">
			<NcSelect
				v-model="form.client"
				:options="clientOptions"
				:input-label="t('pipelinq', 'Client')"
				label="label"
				:reduce="o => o.value"
				:clearable="true"
				:loading="clientSearchLoading"
				@search="onClientSearch" />
		</div>

		<div class="werkplek-cm-panel__field">
			<NcTextField
				:value="form.subject"
				:label="t('pipelinq', 'Subject')"
				:error="!!fieldErrors.subject"
				:helper-text="fieldErrors.subject"
				@update:value="v => form.subject = v" />
		</div>

		<div class="werkplek-cm-panel__field">
			<label class="werkplek-cm-panel__label" for="werkplek-cm-summary">
				{{ t('pipelinq', 'Summary') }}
			</label>
			<textarea
				id="werkplek-cm-summary"
				v-model="form.summary"
				class="werkplek-cm-panel__textarea"
				rows="4" />
		</div>

		<div class="werkplek-cm-panel__field">
			<NcSelect
				v-model="form.outcome"
				:options="outcomeOptions"
				:input-label="t('pipelinq', 'Outcome')"
				label="label"
				:reduce="o => o.value"
				:clearable="true" />
		</div>

		<div class="werkplek-cm-panel__actions">
			<NcButton type="primary" :disabled="saving || !canRegister" @click="onRegister">
				{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Register') }}
			</NcButton>
			<NcButton type="secondary" :disabled="!form.client" @click="openTaskDialog">
				{{ t('pipelinq', 'New task') }}
			</NcButton>
		</div>

		<div v-if="errorMessage" class="werkplek-cm-panel__error">
			{{ errorMessage }}
		</div>

		<WerkplekNewTaskDialog
			v-if="taskDialogOpen"
			:client-id="form.client"
			:contact-moment-summary="form.summary"
			@close="taskDialogOpen = false"
			@saved="onTaskSaved" />
	</div>
</template>

<script>
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'
import CallTimer from '../CallTimer.vue'
import WerkplekNewTaskDialog from './WerkplekNewTaskDialog.vue'

/**
 * Allowed contactmoment channels (spec REQ-KWP-030, schema enum).
 */
const CHANNEL_OPTIONS = [
	{ value: 'telefoon', labelKey: 'Phone' },
	{ value: 'email', labelKey: 'Email' },
	{ value: 'balie', labelKey: 'Counter' },
	{ value: 'chat', labelKey: 'Chat' },
	{ value: 'post', labelKey: 'Letter' },
	{ value: 'social', labelKey: 'Social media' },
]

/**
 * Allowed contactmoment outcomes (design.md).
 */
const OUTCOME_OPTIONS = [
	{ value: 'opgelost', labelKey: 'Resolved' },
	{ value: 'doorverwezen', labelKey: 'Forwarded' },
	{ value: 'terugbellen', labelKey: 'Call back' },
	{ value: 'niet_bereikbaar', labelKey: 'Not reachable' },
]

/**
 * Quick contactmoment registration panel for the KCC Werkplek.
 *
 * Adapts its fields by channel (the call timer only appears for telefoon),
 * pre-fills the client from an inbox-selected request or task, validates
 * subject + channel before persisting via the object store, and leaves the
 * `agent` field blank so the server-side `IUserSession` is the only source
 * of truth (REQ-KWP-030 / REQ-KWP-031).
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
 */
export default {
	name: 'WerkplekContactmomentPanel',

	components: {
		NcButton,
		NcSelect,
		NcTextField,
		CallTimer,
		WerkplekNewTaskDialog,
	},

	props: {
		/**
		 * Selected context coming from the inbox panel — `{ kind, item }`.
		 *
		 * @type {object|null}
		 */
		context: {
			type: Object,
			default: null,
		},
	},

	emits: ['saved'],

	data() {
		return {
			form: {
				channel: 'telefoon',
				client: '',
				subject: '',
				summary: '',
				outcome: '',
				duration: '',
				requestId: '',
				taskId: '',
			},
			saving: false,
			errorMessage: '',
			fieldErrors: {},
			clientSearchLoading: false,
			clientSearchResults: [],
			taskDialogOpen: false,
		}
	},

	computed: {
		/**
		 * Channel <NcSelect> options — translated labels.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		channelOptions() {
			return CHANNEL_OPTIONS.map(opt => ({
				value: opt.value,
				label: this.t('pipelinq', opt.labelKey),
			}))
		},
		/**
		 * Outcome <NcSelect> options — translated labels.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		outcomeOptions() {
			return OUTCOME_OPTIONS.map(opt => ({
				value: opt.value,
				label: this.t('pipelinq', opt.labelKey),
			}))
		},
		/**
		 * Client search results as <NcSelect>-compatible options.
		 *
		 * @return {Array<{value: string, label: string}>}
		 */
		clientOptions() {
			return this.clientSearchResults.map(c => ({
				value: c.id,
				label: c.name || c.id,
			}))
		},
		/**
		 * Pinia object store handle.
		 *
		 * @return {object}
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * Whether the form is valid for submission.
		 *
		 * @return {boolean}
		 */
		canRegister() {
			return Boolean(this.form.subject && this.form.subject.trim() && this.form.channel)
		},
		/**
		 * Human-readable label for the selected context (request or task title).
		 *
		 * @return {string}
		 */
		contextLabel() {
			if (!this.context || !this.context.item) return ''
			const it = this.context.item
			if (this.context.kind === 'request') {
				return this.t('pipelinq', 'Request') + ': ' + (it.title || it.id)
			}
			if (this.context.kind === 'task') {
				return this.t('pipelinq', 'Task') + ': ' + (it.subject || it.id)
			}
			return ''
		},
	},

	watch: {
		/**
		 * When the inbox selection changes, pre-fill the form.
		 *
		 * @param {object|null} val The new context.
		 */
		context: {
			immediate: true,
			handler(val) {
				if (!val || !val.item) return
				const it = val.item
				if (it.client) this.form.client = it.client
				if (it.clientId) this.form.client = it.clientId
				if (val.kind === 'request') {
					this.form.requestId = it.id || ''
					this.form.subject = it.title || ''
				} else if (val.kind === 'task') {
					this.form.taskId = it.id || ''
					this.form.subject = it.subject || ''
				}
				// Ensure the selected client shows up in the <NcSelect> options.
				if (this.form.client && !this.clientOptions.find(o => o.value === this.form.client)) {
					const stub = { id: this.form.client, name: it.clientName || it.client || this.form.client }
					this.clientSearchResults = [...this.clientSearchResults, stub]
				}
			},
		},
	},

	methods: {
		/**
		 * Run a client search via the object store (debounced indirectly by the
		 * NcSelect input). Errors degrade silently — the dropdown just shows
		 * "no results" rather than crashing the panel.
		 *
		 * @param {string} term Search term (free text).
		 *
		 * @return {Promise<void>}
		 */
		async onClientSearch(term) {
			if (!term || term.length < 2) return
			this.clientSearchLoading = true
			try {
				const collection = await this.objectStore.fetchCollection('client', { _search: term, _limit: 20 })
				const items = Array.isArray(collection) ? collection : (this.objectStore.collections.client || [])
				this.clientSearchResults = items
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('[WerkplekContactmomentPanel] client search failed', e)
			} finally {
				this.clientSearchLoading = false
			}
		},
		/**
		 * Handle the CallTimer "stopped" event — capture the ISO-8601 duration.
		 *
		 * @param {string} iso PT-formatted ISO duration.
		 */
		onTimerStopped(iso) {
			this.form.duration = iso || ''
		},
		/**
		 * Clear the inbox-selected context.
		 */
		clearContext() {
			this.form.client = ''
			this.form.requestId = ''
			this.form.taskId = ''
			this.form.subject = ''
			this.$emit('saved', null)
		},
		/**
		 * Open the "New task" dialog with the current client + summary.
		 */
		openTaskDialog() {
			if (!this.form.client) return
			this.taskDialogOpen = true
		},
		/**
		 * Handle the saved-task event from the dialog.
		 *
		 * @param {object} task The newly-saved task object.
		 */
		onTaskSaved(task) {
			this.taskDialogOpen = false
			try {
				showSuccess(this.t('pipelinq', 'Task created'))
			} catch {
				// no-op
			}
			this.$emit('saved', { type: 'task', item: task })
		},
		/**
		 * Persist a new contactmoment.
		 *
		 * The `agent` field is intentionally omitted — the server fills it via
		 * `IUserSession` (REQ-KWP-031). Errors are surfaced inline AND via the
		 * NC dialogs lib so the agent always sees the failure.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
		 */
		async onRegister() {
			this.fieldErrors = {}
			this.errorMessage = ''
			if (!this.form.subject || !this.form.subject.trim()) {
				this.fieldErrors = { subject: this.t('pipelinq', 'Subject is required') }
				return
			}
			if (!this.form.channel) {
				this.errorMessage = this.t('pipelinq', 'Channel is required')
				return
			}

			this.saving = true
			const payload = {
				subject: this.form.subject.trim(),
				channel: this.form.channel,
				contactedAt: new Date().toISOString(),
			}
			if (this.form.outcome) payload.outcome = this.form.outcome
			if (this.form.client) payload.client = this.form.client
			if (this.form.requestId) payload.request = this.form.requestId
			if (this.form.summary) payload.summary = this.form.summary
			if (this.form.duration) payload.duration = this.form.duration

			try {
				const result = await this.objectStore.saveObject('contactmoment', payload)
				if (!result) {
					const err = this.objectStore.getError ? this.objectStore.getError('contactmoment') : null
					this.errorMessage = (err && err.message) || this.t('pipelinq', 'Failed to save contactmoment')
					try { showError(this.errorMessage) } catch { /* no-op */ }
					return
				}
				try { showSuccess(this.t('pipelinq', 'Contactmoment registered')) } catch { /* no-op */ }
				this.$emit('saved', { type: 'contactmoment', item: result })
				// Reset form for the next interaction; keep the client for follow-up calls.
				this.form.subject = ''
				this.form.summary = ''
				this.form.outcome = ''
				this.form.duration = ''
			} catch (e) {
				this.errorMessage = (e && e.message) || this.t('pipelinq', 'Failed to save contactmoment')
				try { showError(this.errorMessage) } catch { /* no-op */ }
				// eslint-disable-next-line no-console
				console.warn('[WerkplekContactmomentPanel] save failed', e)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.werkplek-cm-panel {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px;
}

.werkplek-cm-panel__title {
	margin: 0;
	font-size: 1.1em;
}

.werkplek-cm-panel__context {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	background: var(--color-primary-element-light, var(--color-background-darker));
	border-radius: var(--border-radius);
	font-size: 0.9em;
}

.werkplek-cm-panel__context-label { font-weight: 600; }
.werkplek-cm-panel__context-clear { margin-left: auto; background: transparent; border: 0; font-size: 1.2em; cursor: pointer; }

.werkplek-cm-panel__row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}

.werkplek-cm-panel__field { display: flex; flex-direction: column; gap: 4px; }
.werkplek-cm-panel__field--timer { justify-content: flex-end; }

.werkplek-cm-panel__label { font-weight: 500; font-size: 0.9em; }
.werkplek-cm-panel__textarea {
	width: 100%;
	min-height: 80px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	resize: vertical;
}

.werkplek-cm-panel__actions { display: flex; gap: 8px; justify-content: flex-end; }
.werkplek-cm-panel__error { color: var(--color-error); padding: 4px 0; }
</style>

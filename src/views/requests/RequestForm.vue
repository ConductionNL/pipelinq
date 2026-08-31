<template>
	<div class="request-form">
		<!-- Title -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.title"
				:label="t('pipelinq', 'Title')"
				:error="!!errors.title"
				:helperText="errors.title"
				@update:modelValue="(v) => (form.title = v)" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.description"
				:label="t('pipelinq', 'Description')"
				@update:modelValue="(v) => (form.description = v)" />
		</div>

		<!-- Status + Priority row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Status') }}</label>
				<NcSelect
					v-model="form.status"
					:options="availableStatuses"
					:aria-label-combobox="t('pipelinq', 'Status')"
					:clearable="false"
					:placeholder="t('pipelinq', 'Status')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Priority') }}</label>
				<NcSelect
					v-model="form.priority"
					:options="priorityOptions"
					:aria-label-combobox="t('pipelinq', 'Priority')"
					:clearable="false"
					:placeholder="t('pipelinq', 'Priority')" />
			</div>
		</div>

		<!-- Channel + Category row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Channel') }}</label>
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:aria-label-combobox="t('pipelinq', 'Channel')"
					:clearable="true"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div class="form-group">
				<NcTextField
					:modelValue="form.category"
					:label="t('pipelinq', 'Category')"
					@update:modelValue="(v) => (form.category = v)" />
			</div>
		</div>

		<!-- Requested at -->
		<div class="form-group">
			<NcDateTimePickerNative
				:modelValue="occurredAtDate"
				:label="t('pipelinq', 'Requested at')"
				type="date"
				@update:modelValue="occurredAtDate = $event" />
		</div>

		<!-- Client + Contact. Contact is scoped to the chosen client and stays
		     disabled until there is one — the same cascade Stage has on
		     Pipeline below. Both can create what they cannot find. -->
		<div class="form-row">
			<div class="form-group" data-testid="request-form-client">
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
			<div class="form-group" data-testid="request-form-contact">
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
			@created="onClientCreated"
			@close="closeClientDialog" />
		<ContactCreateDialog
			v-if="contactDialogOpen"
			:client="form.client"
			:name="pendingName"
			@created="onContactCreated"
			@close="closeContactDialog" />

		<!-- Pipeline + Stage row -->
		<div class="form-row">
			<div class="form-group" data-testid="request-form-pipeline">
				<label>{{ t('pipelinq', 'Pipeline') }}</label>
				<NcSelect
					v-model="form.pipeline"
					:options="pipelineOptions"
					:aria-label-combobox="t('pipelinq', 'Pipeline')"
					:clearable="true"
					label="label"
					:reduce="(o) => o.value"
					:placeholder="t('pipelinq', 'Select pipeline')"
					@update:modelValue="onPipelineChange" />
			</div>
			<div class="form-group" data-testid="request-form-stage">
				<label>{{ t('pipelinq', 'Stage') }}</label>
				<NcSelect
					v-model="form.stage"
					:options="stageOptions"
					:aria-label-combobox="t('pipelinq', 'Stage')"
					:clearable="true"
					:disabled="!form.pipeline"
					:placeholder="
						form.pipeline
							? t('pipelinq', 'Select stage')
							: t('pipelinq', 'Select pipeline first')
					" />
			</div>
		</div>

		<!-- Actions -->
		<div v-if="showActions" class="form-actions">
			<NcButton variant="tertiary" @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!isValid" @click="onSave">
				{{ isEdit ? t('pipelinq', 'Save') : t('pipelinq', 'Create') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { CnResourceSelect } from '@conduction/nextcloud-vue'
import {
	NcButton,
	NcDateTimePickerNative,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ClientCreateDialog from '../../dialogs/ClientCreateDialog.vue'
import ContactCreateDialog from '../../dialogs/ContactCreateDialog.vue'
import { toDateInputString, toDateObject } from '../../services/localeUtils.js'
import { pipelineAppliesTo } from '../../services/pipelineUtils.js'
import { getAllowedTransitions } from '../../services/requestStatus.js'
import { useObjectStore } from '../../store/modules/object.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'

export default {
	name: 'RequestForm',
	components: {
		ClientCreateDialog,
		CnResourceSelect,
		ContactCreateDialog,
		NcButton,
		NcDateTimePickerNative,
		NcSelect,
		NcTextField,
	},

	props: {
		request: {
			type: Object,
			default: null,
		},

		preLinkedClient: {
			type: String,
			default: null,
		},

		/**
		 * Render the built-in Cancel / Save buttons. Set to `false` when the
		 * host supplies its own action buttons (e.g. a parent NcDialog driving
		 * the form via a ref + the `update:valid` event).
		 *
		 * Defaults ON deliberately: a host that supplies its own action bar
		 * opts OUT. Inverting the name would make every ordinary use pass a
		 * negative prop just to get the normal form.
		 */
		showActions: {
			type: Boolean,
			// eslint-disable-next-line vue/no-boolean-default
			default: true,
		},
	},

	emits: ['cancel', 'save', 'update:valid'],

	data() {
		return {
			form: {
				title: '',
				description: '',
				status: 'new',
				priority: 'normal',
				channel: null,
				category: '',
				occurredAt: null,
				client: null,
				contact: null,
				pipeline: null,
				stage: null,
			},

			priorityOptions: ['low', 'normal', 'high', 'urgent'],

			// Inline-create plumbing. `resolveCreate` is the promise resolver
			// CnResourceSelect is awaiting: the picker hands control to a full
			// dialog and resumes when that dialog resolves (with the created
			// object) or is cancelled (with null).
			clientDialogOpen: false,
			contactDialogOpen: false,
			pendingName: '',
			resolveCreate: null,
		}
	},

	computed: {
		/**
		 * Bridge the stored `occurredAt` string (formerly `requestedAt`, renamed by
		 * unify-ticket-supertype) to NcDateTimePickerNative, which works with Date
		 * objects. The user-facing label stays "Requested at".
		 *
		 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
		 */
		occurredAtDate: {
			/**
			 * @return {Date|null} The stored `occurredAt` as a Date.
			 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
			 */
			get() {
				return toDateObject(this.form.occurredAt)
			},

			/**
			 * @param {Date|null} date The picked date.
			 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
			 */
			set(date) {
				this.form.occurredAt = toDateInputString(date)
			},
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-44
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-49
		 */
		requestChannelsStore() {
			return useRequestChannelsStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-39
		 */
		channelOptions() {
			return this.requestChannelsStore.channelNames
		},

		isEdit() {
			return !!this.request?.id
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-38
		 */
		availableStatuses() {
			if (!this.isEdit) return ['new']
			const current = this.request.status || 'new'
			return [current, ...getAllowedTransitions(current)]
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-48
		 */
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-50
		 */
		requestPipelines() {
			return this.pipelines.filter((p) => pipelineAppliesTo(p, 'request'))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-47
		 */
		pipelineOptions() {
			return this.requestPipelines.map((p) => ({
				value: p.id,
				label: p.title,
			}))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-51
		 */
		selectedPipeline() {
			if (!this.form.pipeline) return null
			return this.pipelines.find((p) => p.id === this.form.pipeline) || null
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-52
		 */
		stageOptions() {
			if (!this.selectedPipeline?.stages) return []
			return [...this.selectedPipeline.stages]
				.sort((a, b) => a.order - b.order)
				.map((s) => s.name)
		},

		/**
		 * Scope for the contact picker. CnResourceSelect drops empty entries,
		 * so an unchosen client scopes to nothing rather than querying for
		 * contacts whose client is the empty string.
		 *
		 * @return {{client: (string|null)}}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		contactFilters() {
			return { client: this.form.client }
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-43
		 */
		errors() {
			const errors = {}
			if (!this.form.title || !this.form.title.trim()) {
				errors.title = t('pipelinq', 'Title is required')
			}
			return errors
		},

		isValid() {
			return Object.keys(this.errors).length === 0 && this.form.title?.trim()
		},
	},

	watch: {
		// Surface validity so a host (e.g. a parent NcDialog) can enable or
		// disable its own submit button.
		isValid: {
			immediate: true,
			handler(val) {
				this.$emit('update:valid', !!val)
			},
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-42
	 */
	async created() {
		await Promise.all([
			this.objectStore.fetchCollection('pipeline', { _limit: 100 }),
			this.requestChannelsStore.fetchChannels(),
		])

		if (this.request) {
			this.form = {
				id: this.request.id,
				title: this.request.title || '',
				description: this.request.description || '',
				status: this.request.status || 'new',
				priority: this.request.priority || 'normal',
				channel: this.request.channel || null,
				category: this.request.category || '',
				occurredAt: this.request.occurredAt || null,
				client: this.request.client || null,
				contact: this.request.contact || null,
				pipeline: this.request.pipeline || null,
				stage: this.request.stage || null,
			}
		} else {
			if (this.preLinkedClient) {
				this.form.client = this.preLinkedClient
			}
			this.autoAssignDefaultPipeline()
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-37
		 */
		autoAssignDefaultPipeline() {
			const defaultPipeline = this.requestPipelines.find((p) => p.isDefault)
			if (defaultPipeline) {
				this.form.pipeline = defaultPipeline.id
				const stages = [...(defaultPipeline.stages || [])].sort(
					(a, b) => a.order - b.order,
				)
				const firstOpen = stages.find((s) => !s.isClosed)
				if (firstOpen) {
					this.form.stage = firstOpen.name
				}
			}
		},

		/**
		 * Selecting a different client invalidates the contact under it.
		 *
		 * @param {string} value The chosen client uuid, or '' when cleared.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		onClientChange(value) {
			const next = value || null
			if (next !== this.form.client) {
				this.form.contact = null
			}
			this.form.client = next
		},

		/**
		 * CnResourceSelect create hook for the client picker. The `client`
		 * schema needs more than a name — `contactsUid` is server-minted — so
		 * the typed term opens the full create dialog instead of being saved
		 * directly.
		 *
		 * @return {Promise<object|null>} The created client, or null if cancelled.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		createClient() {
			return new Promise((resolve) => {
				this.resolveCreate = resolve
				this.clientDialogOpen = true
			})
		},

		/**
		 * Same hook for the contact picker, carrying the typed name and the
		 * selected client into the dialog.
		 *
		 * @param {string} term The name typed into the picker.
		 * @return {Promise<object|null>} The created contact, or null if cancelled.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		createContact(term) {
			this.pendingName = term || ''
			return new Promise((resolve) => {
				this.resolveCreate = resolve
				this.contactDialogOpen = true
			})
		},

		/**
		 * Settle the awaiting picker exactly once, however the dialog ended.
		 *
		 * @param {object|null} created The created object, or null when cancelled.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		settleCreate(created) {
			const resolve = this.resolveCreate
			this.resolveCreate = null
			this.pendingName = ''
			if (resolve) resolve(created)
		},

		/**
		 * @param {string} id The created client's uuid (ClientCreateDialog emits an id).
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		onClientCreated(id) {
			this.clientDialogOpen = false
			this.form.contact = null
			this.settleCreate(id ? { id } : null)
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		closeClientDialog() {
			this.clientDialogOpen = false
			this.settleCreate(null)
		},

		/**
		 * @param {object} contact The created contact object.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		onContactCreated(contact) {
			this.contactDialogOpen = false
			this.settleCreate(contact || null)
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		closeContactDialog() {
			this.contactDialogOpen = false
			this.settleCreate(null)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-45
		 */
		onPipelineChange() {
			this.form.stage = null
			if (this.selectedPipeline) {
				const stages = [...(this.selectedPipeline.stages || [])].sort(
					(a, b) => a.order - b.order,
				)
				const firstOpen = stages.find((s) => !s.isClosed)
				if (firstOpen) {
					this.form.stage = firstOpen.name
				}
			}
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-46
		 */
		onSave() {
			if (!this.isValid) return

			const data = { ...this.form }
			if (!data.channel) delete data.channel
			if (!data.occurredAt) delete data.occurredAt
			if (!data.client) delete data.client
			if (!data.contact) delete data.contact
			if (!data.pipeline) delete data.pipeline
			if (!data.stage) delete data.stage
			if (!data.category) delete data.category

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.request-form {
	max-width: 600px;
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
</style>

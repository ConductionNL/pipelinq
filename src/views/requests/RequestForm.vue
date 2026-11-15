<template>
	<div class="request-form" :class="{ 'request-form--board': boardLayout }">
		<!-- The kind of ticket (board layout only; the edit form keeps the
		     ticket's kind). -->
		<div
			v-if="boardLayout"
			class="form-group rf-type"
			data-testid="request-form-type">
			<label :id="typeLabelId">{{ t('pipelinq', 'What is it?') }}</label>
			<div
				class="rf-type__options"
				role="radiogroup"
				:aria-labelledby="typeLabelId">
				<NcCheckboxRadioSwitch
					v-for="option in typeOptions"
					:key="option.value"
					v-model="form.ticketType"
					:value="option.value"
					name="request-form-type"
					type="radio"
					buttonVariant>
					<template #icon>
						<component :is="option.icon" :size="18" />
					</template>
					{{ option.label }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<!-- Title -->
		<div class="form-group rf-title">
			<NcTextField
				:modelValue="form.title"
				:label="
					boardLayout ? t('pipelinq', 'Subject') : t('pipelinq', 'Title')
				"
				:error="showTitleError"
				:helperText="showTitleError ? errors.title : ''"
				@update:modelValue="onTitleInput" />
		</div>

		<!-- Description -->
		<div class="form-group rf-description">
			<NcTextArea
				v-if="boardLayout"
				:modelValue="form.description"
				:label="t('pipelinq', 'What does the customer ask?')"
				resize="vertical"
				@update:modelValue="(v) => (form.description = v)" />
			<NcTextField
				v-else
				:modelValue="form.description"
				:label="t('pipelinq', 'Description')"
				@update:modelValue="(v) => (form.description = v)" />
		</div>

		<!-- Status + Priority row; in the board layout Priority + Handler, and
		     Status under More details. -->
		<div class="form-row rf-priority">
			<div
				v-if="!boardLayout || showMore"
				class="form-group"
				:class="{ 'rf-status': boardLayout }">
				<label>{{ t('pipelinq', 'Status') }}</label>
				<NcSelect
					v-model="form.status"
					:options="availableStatuses"
					:getOptionLabel="getStatusLabel"
					:aria-label-combobox="t('pipelinq', 'Status')"
					labelOutside
					:clearable="false"
					:placeholder="t('pipelinq', 'Status')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Priority') }}</label>
				<NcSelect
					v-model="form.priority"
					:options="priorityOptions"
					:getOptionLabel="getPriorityLabel"
					:aria-label-combobox="t('pipelinq', 'Priority')"
					labelOutside
					:clearable="false"
					:placeholder="t('pipelinq', 'Priority')" />
			</div>
			<div
				v-if="boardLayout"
				class="form-group"
				data-testid="request-form-assignee">
				<label>{{ t('pipelinq', 'Handler') }}</label>
				<NcSelect
					v-model="form.assignee"
					:options="assigneeOptions"
					:aria-label-combobox="t('pipelinq', 'Handler')"
					labelOutside
					label="label"
					:reduce="(o) => o.value"
					:clearable="false" />
				<p class="rf-hint">
					{{ t('pipelinq', 'Or leave it in the queue') }}
				</p>
			</div>
		</div>

		<!-- Channel + Category row -->
		<div class="form-row rf-channel">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Channel') }}</label>
				<NcSelect
					v-model="form.channel"
					:options="channelOptions"
					:aria-label-combobox="t('pipelinq', 'Channel')"
					labelOutside
					:clearable="true"
					:placeholder="t('pipelinq', 'Select channel')" />
			</div>
			<div v-if="categoryChoices.length > 0" class="form-group">
				<label>{{ t('pipelinq', 'Category') }}</label>
				<NcSelect
					:modelValue="selectedCategory"
					:options="categoryChoices"
					:aria-label-combobox="t('pipelinq', 'Category')"
					labelOutside
					:clearable="true"
					:placeholder="t('pipelinq', 'Select a category')"
					@update:modelValue="(option) => (form.category = option ? option.value : '')" />
			</div>
			<div v-else class="form-group">
				<NcTextField
					:modelValue="form.category"
					:label="t('pipelinq', 'Category')"
					@update:modelValue="(v) => (form.category = v)" />
			</div>
		</div>

		<!-- Requested at -->
		<div v-if="!boardLayout || showMore" class="form-group rf-more">
			<NcDateTimePickerNative
				:modelValue="occurredAtDate"
				:label="t('pipelinq', 'Requested at')"
				type="date"
				@update:modelValue="occurredAtDate = $event" />
		</div>

		<!-- Client + Contact. Contact is scoped to the chosen client and stays
		     disabled until there is one — the same cascade Stage has on
		     Pipeline below. Both can create what they cannot find. -->
		<div class="form-row rf-client">
			<div class="form-group" data-testid="request-form-client">
				<CnSearchSelect
					v-if="boardLayout"
					class="rf-client__search"
					:modelValue="form.client || null"
					:options="clientOptions"
					:loading="clientSearching"
					:filterable="false"
					:inputLabel="t('pipelinq', 'Client')"
					:placeholder="t('pipelinq', 'Search by name')"
					data-testid="request-form-client-search"
					@search="onClientSearch"
					@update:modelValue="onClientChange" />
				<CnResourceSelect
					v-else
					register="pipelinq"
					schema="client"
					labelField="name"
					:modelValue="form.client || ''"
					:inputLabel="t('pipelinq', 'Client')"
					:placeholder="t('pipelinq', 'Select or create a client')"
					:preload="true"
					:createHandler="createClient"
					@update:modelValue="onClientChange" />
				<div v-if="boardLayout" class="rf-client__actions">
					<NcButton
						variant="secondary"
						class="cn-button--tonal"
						@click="newClient">
						<template #icon>
							<AccountPlusOutline :size="16" />
						</template>
						{{ t('pipelinq', 'New client') }}
					</NcButton>
				</div>
				<p v-if="boardLayout" class="rf-hint">
					{{
						t('pipelinq', 'or leave it empty for an anonymous question')
					}}
				</p>
			</div>
			<div
				v-if="!boardLayout || showMore"
				class="form-group"
				:class="{ 'rf-more': boardLayout }"
				data-testid="request-form-contact">
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

		<!-- Pipeline + Stage row -->
		<div v-if="!boardLayout || showMore" class="form-row rf-more">
			<div class="form-group" data-testid="request-form-pipeline">
				<label>{{ t('pipelinq', 'Pipeline') }}</label>
				<NcSelect
					v-model="form.pipeline"
					:options="pipelineOptions"
					:aria-label-combobox="t('pipelinq', 'Pipeline')"
					labelOutside
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
					labelOutside
					:clearable="true"
					:disabled="!form.pipeline"
					:placeholder="
						form.pipeline
							? t('pipelinq', 'Select stage')
							: t('pipelinq', 'Select pipeline first')
					" />
			</div>
		</div>

		<p v-if="boardLayout" class="rf-note" data-testid="request-form-term">
			<ClockOutline :size="18" class="rf-note__icon" />
			<span>
				{{
					t(
						'pipelinq',
						'The deadline follows from the kind of ticket and its service level. A conversation is also saved as a contact moment on this ticket.',
					)
				}}
			</span>
		</p>
		<div v-if="boardLayout" class="rf-more-toggle">
			<NcButton
				variant="tertiary"
				:aria-expanded="showMore ? 'true' : 'false'"
				data-testid="request-form-more"
				@click="showMore = !showMore">
				{{
					showMore
						? t('pipelinq', 'Fewer details')
						: t('pipelinq', 'More details')
				}}
			</NcButton>
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
import { CnResourceSelect, CnSearchSelect } from '@conduction/nextcloud-vue'
import { getCurrentUser } from '@nextcloud/auth'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDateTimePickerNative,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ClockOutline from 'vue-material-design-icons/ClockOutline.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import PhoneOutline from 'vue-material-design-icons/PhoneOutline.vue'
import ClientCreateDialog from '../../dialogs/ClientCreateDialog.vue'
import ContactCreateDialog from '../../dialogs/ContactCreateDialog.vue'
import hostedFormMixin from '../../mixins/hostedFormMixin.js'
import linkedPartyCascadeMixin from '../../mixins/linkedPartyCascadeMixin.js'
import pipelineStageMixin from '../../mixins/pipelineStageMixin.js'
import { toDateInputString, toDateObject } from '../../services/localeUtils.js'
import { pipelineAppliesTo } from '../../services/pipelineUtils.js'
import {
	getAllowedTransitions,
	getPriorityLabel,
	getStatusLabel,
} from '../../services/requestStatus.js'
import { categoryOptions, fetchTicketCategories } from '../../services/ticketCategories.js'
import { useObjectStore } from '../../store/modules/object.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { fieldsOr, withoutEmptyFields } from '../../utils/formFields.js'

/**
 * An existing request as the form edits it.
 *
 * @param {object} request The stored request.
 * @return {object} The form.
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-42
 */
function formFromRequest(request) {
	return {
		id: request.id,
		...fieldsOr(request, { title: '', description: '', status: 'new', priority: 'normal', channel: null, category: '', occurredAt: null, client: null, contact: null, pipeline: null, stage: null }),
	}
}

export default {
	name: 'RequestForm',
	components: {
		AccountPlusOutline,
		ClientCreateDialog,
		ClockOutline,
		CnResourceSelect,
		CnSearchSelect,
		ContactCreateDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcDateTimePickerNative,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	mixins: [hostedFormMixin, linkedPartyCascadeMixin, pipelineStageMixin],

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
		 * The create form of board PqNieuwVerzoek: the kind of ticket first,
		 * then the client, subject, question, channel and category, priority
		 * and handler; status, requested at, contact, pipeline and stage sit
		 * behind More details. Only for a new ticket.
		 */
		boardLayout: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['cancel', 'save', 'update:valid'],

	data() {
		return {
			/** The tenant's ticketCategory objects, read when the form opens. */
			ticketCategories: [],
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

			showMore: false,
			titleTouched: false,
			/** The clients the board's search field lists, as search rows. */
			clientOptions: [],
			clientSearching: false,
			clientSearchTicket: 0,
			typeLabelId: `request-form-type-${Math.random().toString(36).slice(2, 8)}`,

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
		 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
		 */
		occurredAtDate: {
			/**
			 * @return {Date|null} The stored `occurredAt` as a Date.
			 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
			 */
			get() {
				return toDateObject(this.form.occurredAt)
			},

			/**
			 * @param {Date|null} date The picked date.
			 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
			 */
			set(date) {
				this.form.occurredAt = toDateInputString(date)
			},
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-44
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-49
		 */
		requestChannelsStore() {
			return useRequestChannelsStore()
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-39
		 */
		channelOptions() {
			return this.requestChannelsStore.channelNames
		},

		/**
		 * The tenant's active categories as select options, the stored one kept.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/request-management/spec.md#requirement-a-requests-category-is-picked-from-the-tenants-category-list
		 */
		categoryChoices() {
			return categoryOptions(this.ticketCategories, this.form.category)
		},

		/**
		 * The option of the stored category, or null.
		 *
		 * @return {{value: string, label: string}|null}
		 * @spec openspec/specs/request-management/spec.md#requirement-a-requests-category-is-picked-from-the-tenants-category-list
		 */
		selectedCategory() {
			return this.categoryChoices.find((option) => option.value === this.form.category) || null
		},

		/**
		 * The kinds of ticket the board layout offers.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
		 */
		typeOptions() {
			return [
				{
					value: 'request',
					label: t('pipelinq', 'Request'),
					icon: MessageTextOutline,
				},
				{
					value: 'melding',
					label: t('pipelinq', 'Public-space report'),
					icon: AlertOutline,
				},
				{
					value: 'complaint',
					label: t('pipelinq', 'Complaint'),
					icon: AlertCircleOutline,
				},
				{
					value: 'interaction',
					label: t('pipelinq', 'Contact moment'),
					icon: PhoneOutline,
				},
			]
		},

		/**
		 * The handler choices: the person filling in the form, or nobody so the
		 * ticket waits in the queue.
		 *
		 * @return {Array<{value: string|null, label: string}>}
		 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
		 */
		assigneeOptions() {
			const user = getCurrentUser()
			const options = [
				{ value: null, label: t('pipelinq', 'Nobody, the queue') },
			]
			if (user) {
				options.unshift({
					value: user.uid,
					label: t('pipelinq', 'Me, {name}', {
						name: user.displayName || user.uid,
					}),
				})
			}
			return options
		},

		isEdit() {
			return !!this.request?.id
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-38
		 */
		availableStatuses() {
			if (!this.isEdit) return ['new']
			const current = this.request.status || 'new'
			return [current, ...getAllowedTransitions(current)]
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-48
		 */
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-50
		 */
		requestPipelines() {
			return this.pipelines.filter((p) => pipelineAppliesTo(p, 'request'))
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-47
		 */
		pipelineOptions() {
			return this.requestPipelines.map((p) => ({
				value: p.id,
				label: p.title,
			}))
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-43
		 */
		errors() {
			const errors = {}
			if (!this.form.title || !this.form.title.trim()) {
				errors.title = t('pipelinq', 'Title is required')
			}
			return errors
		},

		/**
		 * Whether the subject's "required" message shows: only once someone
		 * typed in the field, so a new form does not open in error.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-43
		 */
		showTitleError() {
			return this.titleTouched && !!this.errors.title
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-42
	 */
	async created() {
		const [, , categories] = await Promise.all([
			this.objectStore.fetchCollection('pipeline', { _limit: 100 }),
			this.requestChannelsStore.fetchChannels(),
			fetchTicketCategories(),
		])
		this.ticketCategories = categories

		if (this.request) {
			this.form = formFromRequest(this.request)
			return
		}
		this.prepareNewRequest()
	},

	methods: {
		/**
		 * A client as a row of the board's search list: initials, the name,
		 * and what kind of client it is and where.
		 *
		 * @param {object} client A client object.
		 * @return {{id: string, label: string, secondary: string, avatar: boolean}} The row.
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		clientRow(client) {
			const kind = client.type === 'organization'
				? t('pipelinq', 'Organisation')
				: t('pipelinq', 'Resident')
			return {
				id: client.id,
				label: client.name || '',
				secondary: [kind, client.address].filter(Boolean).join(' · '),
				avatar: true,
			}
		},

		/**
		 * List the clients matching the typed name. A slower answer to an
		 * older search never replaces a newer one.
		 *
		 * @param {string} query The typed text.
		 * @return {Promise<void>}
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		async onClientSearch(query) {
			const term = (query || '').trim()
			const ticket = ++this.clientSearchTicket
			if (term === '') {
				this.clientOptions = this.clientOptions.filter((o) => o.id === this.form.client)
				return
			}
			this.clientSearching = true
			try {
				const found = await this.objectStore.fetchCollectionForOptions('client', { _search: term, _limit: 20 })
				if (ticket === this.clientSearchTicket) {
					this.clientOptions = (Array.isArray(found) ? found : []).map((c) => this.clientRow(c))
				}
			} catch {
				if (ticket === this.clientSearchTicket) {
					this.clientOptions = []
				}
			} finally {
				if (ticket === this.clientSearchTicket) {
					this.clientSearching = false
				}
			}
		},

		/**
		 * Put the client the form opened with (or just created) in the list,
		 * so the field shows its name.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/request-dialog-client-lookups/specs/request-management/spec.md#requirement-the-new-request-dialog-finds-the-client-or-looks-them-up
		 */
		async loadPickedClient() {
			const id = this.form.client
			if (!id || this.clientOptions.some((o) => o.id === id)) {
				return
			}
			try {
				const client = await this.objectStore.fetchObject('client', String(id))
				if (client && this.form.client === id) {
					this.clientOptions = [this.clientRow(client), ...this.clientOptions]
				}
			} catch {
				// The field then shows the id rather than failing the form.
			}
		},

		/**
		 * Create a client from the New client button and put it on the ticket.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		async newClient() {
			const created = await this.createClient('')
			if (created && created.id) {
				this.clientOptions = [this.clientRow(created), ...this.clientOptions.filter((o) => o.id !== created.id)]
				this.onClientChange(created.id)
			}
		},

		/**
		 * A new request: the client it was opened on; on the board, the
		 * picked client, the request type and the current user as handler;
		 * then the default pipeline.
		 *
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-42
		 */
		prepareNewRequest() {
			if (this.preLinkedClient) {
				this.form.client = this.preLinkedClient
			}
			if (this.boardLayout) {
				this.loadPickedClient()
				this.form.ticketType = 'request'
				this.form.assignee = getCurrentUser()?.uid || null
			}
			this.autoAssignDefaultPipeline()
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-37
		 */
		autoAssignDefaultPipeline() {
			const defaultPipeline = this.requestPipelines.find((p) => p.isDefault)
			if (defaultPipeline) {
				this.form.pipeline = defaultPipeline.id
				this.placeOnFirstOpenStage(defaultPipeline)
			}
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-46
		 */
		getPriorityLabel,
		getStatusLabel,

		/**
		 * @param {string} value The typed subject.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-requests-ui/tasks.md#task-43
		 */
		onTitleInput(value) {
			this.form.title = value
			this.titleTouched = true
		},

		/**
		 * Emits the request without its empty fields.
		 *
		 * @spec openspec/specs/request-management/spec.md#requirement-a-requests-category-is-picked-from-the-tenants-category-list
		 */
		onSave() {
			this.titleTouched = true
			if (!this.isValid) return

			const data = withoutEmptyFields({ ...this.form }, ['channel', 'occurredAt', 'client', 'contact', 'pipeline', 'stage', 'category', 'assignee', 'ticketType'])

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped src="../formLayout.css"></style>

<style scoped>
.request-form {
	max-width: 600px;
}

.request-form--board {
	display: flex;
	flex-direction: column;
	max-width: none;
}

.request-form--board .rf-type {
	order: 1;
}

.request-form--board .rf-client {
	order: 2;
}

.request-form--board .rf-title {
	order: 3;
}

.request-form--board .rf-description {
	order: 4;
}

.request-form--board .rf-channel {
	order: 5;
}

.request-form--board .rf-priority {
	order: 6;
}

.request-form--board .rf-note {
	order: 7;
}

.request-form--board .rf-more-toggle {
	order: 8;
}

.request-form--board .rf-more {
	order: 9;
}

.rf-type__options {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.rf-client__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 8px;
}

.rf-note {
	display: flex;
	gap: 10px;
	align-items: flex-start;
	margin: 0;
	padding: 12px 14px;
	border-radius: 10px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 14px;
}

.rf-note__icon {
	flex: none;
	color: var(--color-text-maxcontrast);
}

.rf-hint {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>

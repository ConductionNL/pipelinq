<template>
	<div class="lead-form">
		<!-- Title -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.title"
				:label="t('pipelinq', 'Title')"
				:error="!!shownErrors.title"
				:helperText="shownErrors.title"
				@update:modelValue="(v) => (form.title = v)" />
		</div>

		<!-- Description -->
		<div class="form-group">
			<NcTextField
				:modelValue="form.description"
				:label="t('pipelinq', 'Description')"
				@update:modelValue="(v) => (form.description = v)" />
		</div>

		<!-- Category: what the lead is about. Routing suggests colleagues whose
		     skills cover it (pipelinq#2049). -->
		<div class="form-group" data-testid="lead-form-category">
			<NcTextField
				:modelValue="form.category"
				:label="t('pipelinq', 'Category')"
				@update:modelValue="(v) => (form.category = v)" />
		</div>

		<!-- Value + currency row. The win chance is the qualification score
		     (pipeline-numbers-tell-the-truth), so there is no probability input. -->
		<div class="form-row">
			<div class="form-group">
				<NcTextField
					:modelValue="form.value === null ? '' : String(form.value)"
					:label="t('pipelinq', 'Value')"
					type="number"
					:error="!!shownErrors.value"
					:helperText="shownErrors.value"
					@update:modelValue="
						(v) => (form.value = v === '' ? null : Number(v))
					" />
			</div>
			<div class="form-group form-group--currency">
				<NcTextField
					:modelValue="form.currency"
					:label="t('pipelinq', 'Currency')"
					maxlength="3"
					:error="!!shownErrors.currency"
					:helperText="shownErrors.currency"
					@update:modelValue="
						(v) => (form.currency = normaliseCurrency(v))
					" />
			</div>
		</div>

		<!-- Source + Priority row -->
		<div class="form-row">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Source') }}</label>
				<NcSelect
					v-model="form.source"
					:options="sourceOptions"
					:aria-label-combobox="t('pipelinq', 'Source')"
					labelOutside
					:clearable="true"
					:placeholder="t('pipelinq', 'Select source')" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Priority') }}</label>
				<NcSelect
					v-model="form.priority"
					:options="priorityOptions"
					:reduce="(o) => o.value"
					:aria-label-combobox="t('pipelinq', 'Priority')"
					labelOutside
					:clearable="false"
					:placeholder="t('pipelinq', 'Select priority')" />
			</div>
		</div>

		<!-- Expected Close Date -->
		<div class="form-group">
			<NcDateTimePickerNative
				:modelValue="expectedCloseDateObj"
				:label="t('pipelinq', 'Expected close date')"
				type="date"
				@update:modelValue="expectedCloseDateObj = $event" />
		</div>

		<!-- Client + Contact. Contact is scoped to the chosen client and stays
		     disabled until there is one — the same cascade Stage has on
		     Pipeline below. Both can create what they cannot find. -->
		<div class="form-row">
			<div class="form-group" data-testid="lead-form-client">
				<CnResourceSelect
					register="pipelinq"
					schema="client"
					labelField="name"
					:modelValue="form.client || ''"
					:inputLabel="t('pipelinq', 'Client') + ' *'"
					:placeholder="t('pipelinq', 'Select or create a client')"
					:preload="true"
					:createHandler="createClient"
					@update:modelValue="onClientChange" />
				<p v-if="shownErrors.client" class="field-error" role="alert">
					{{ shownErrors.client }}
				</p>
			</div>
			<div class="form-group" data-testid="lead-form-contact">
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
		<div class="form-row">
			<div class="form-group" data-testid="lead-form-pipeline">
				<label>{{ t('pipelinq', 'Pipeline') }} *</label>
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
				<p v-if="shownErrors.pipeline" class="field-error" role="alert">
					{{ shownErrors.pipeline }}
				</p>
			</div>
			<div class="form-group" data-testid="lead-form-stage">
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
import { translate } from '@nextcloud/l10n'
import {
	NcButton,
	NcDateTimePickerNative,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ClientCreateDialog from '../../dialogs/ClientCreateDialog.vue'
import ContactCreateDialog from '../../dialogs/ContactCreateDialog.vue'
import linkedPartyCascadeMixin from '../../mixins/linkedPartyCascadeMixin.js'
import touchedErrorsMixin from '../../mixins/touchedErrorsMixin.js'
import { isCurrencyCode, normaliseCurrency } from '../../services/leadCurrency.js'
import { toDateInputString, toDateObject } from '../../services/localeUtils.js'
import { pipelineAppliesTo } from '../../services/pipelineUtils.js'
import { reportingCurrency } from '../../services/reportingCurrency.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useObjectStore } from '../../store/modules/object.js'
import { enumOptions, LEAD_PRIORITY_LABELS } from '../../utils/enumLabels.js'

export default {
	name: 'LeadForm',
	components: {
		ClientCreateDialog,
		CnResourceSelect,
		ContactCreateDialog,
		NcButton,
		NcDateTimePickerNative,
		NcSelect,
		NcTextField,
	},

	mixins: [linkedPartyCascadeMixin, touchedErrorsMixin],

	props: {
		lead: {
			type: Object,
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
				category: '',
				value: null,
				// The deal's currency (pipelinq#2040). New deals start in the
				// reporting currency; the forecast converts any other one.
				currency: reportingCurrency(),
				source: null,
				priority: 'normal',
				expectedCloseDate: null,
				client: null,
				contact: null,
				pipeline: null,
				stage: null,
			},

			priorityOptions: enumOptions(LEAD_PRIORITY_LABELS, (text) =>
				translate('pipelinq', text),
			),
		}
	},

	computed: {
		/**
		 * Bridge the stored `expectedCloseDate` string to
		 * NcDateTimePickerNative, which works with Date objects.
		 */
		expectedCloseDateObj: {
			get() {
				return toDateObject(this.form.expectedCloseDate)
			},

			set(date) {
				this.form.expectedCloseDate = toDateInputString(date)
			},
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-48
		 */
		objectStore() {
			return useObjectStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-47
		 */
		leadSourcesStore() {
			return useLeadSourcesStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-54
		 */
		sourceOptions() {
			return this.leadSourcesStore.sourceNames
		},

		isEdit() {
			return !!this.lead?.id
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-52
		 */
		pipelines() {
			return this.objectStore.collections.pipeline || []
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-46
		 */
		leadPipelines() {
			return this.pipelines.filter((p) => pipelineAppliesTo(p, 'lead'))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-51
		 */
		pipelineOptions() {
			return this.leadPipelines.map((p) => ({
				value: p.id,
				label: p.title,
			}))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-53
		 */
		selectedPipeline() {
			if (!this.form.pipeline) return null
			return this.pipelines.find((p) => p.id === this.form.pipeline) || null
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-55
		 */
		stageOptions() {
			if (!this.selectedPipeline?.stages) return []
			return [...this.selectedPipeline.stages]
				.sort((a, b) => a.order - b.order)
				.map((s) => s.name)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-45
		 */
		errors() {
			const errors = {}
			if (!this.form.title || !this.form.title.trim()) {
				errors.title = t('pipelinq', 'Title is required')
			}
			if (this.form.value !== null && this.form.value < 0) {
				errors.value = t('pipelinq', 'Value must be non-negative')
			}
			if (this.form.currency && !isCurrencyCode(this.form.currency)) {
				errors.currency = t(
					'pipelinq',
					'Use a three-letter currency code, such as EUR or USD',
				)
			}

			// A lead belongs to a pipeline and to a client; the schema requires
			// both. Catching it here means the user sees which field is missing,
			// instead of OpenRegister rejecting the whole save with
			// "The required property (client) is missing".
			if (!this.form.pipeline) {
				errors.pipeline = t('pipelinq', 'Pipeline is required')
			}

			if (!this.form.client) {
				errors.client = t('pipelinq', 'Client is required')
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
	 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-44
	 */
	async created() {
		// Load pipelines, clients, and lead sources for dropdowns
		await Promise.all([
			this.objectStore.fetchCollection('pipeline', { _limit: 100 }),
			this.leadSourcesStore.fetchSources(),
		])

		if (this.lead) {
			// Edit mode: populate from existing lead
			this.form = {
				id: this.lead.id,
				title: this.lead.title || '',
				description: this.lead.description || '',
				category: this.lead.category || '',
				value: this.lead.value ?? null,
				currency: this.lead.currency || reportingCurrency(),
				// Not editable any more; carried so a full save keeps the stored value.
				probability: this.lead.probability ?? null,
				source: this.lead.source || null,
				priority: this.lead.priority || 'normal',
				expectedCloseDate: this.lead.expectedCloseDate || null,
				client: this.lead.client || null,
				contact: this.lead.contact || null,
				pipeline: this.lead.pipeline || null,
				stage: this.lead.stage || null,
			}
		} else {
			// Create mode: auto-assign default pipeline
			this.autoAssignDefaultPipeline()
		}
	},

	methods: {
		normaliseCurrency,

		/**
		 * Put a new lead on the default lead pipeline, or the first one when
		 * none is marked default, in its first open stage. Every lead sits in
		 * a stage; the server fills one in too, this shows it in the form.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-41
		 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
		 */
		autoAssignDefaultPipeline() {
			const defaultPipeline =
				this.leadPipelines.find((p) => p.isDefault) || this.leadPipelines[0]
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-49
		 */
		onPipelineChange() {
			// Reset stage when pipeline changes
			this.form.stage = null
			// Auto-select first non-closed stage
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
		 * The stage order of the chosen stage, and a fresh entry time when the
		 * stage changed, so the board and the aging figures stay consistent.
		 *
		 * @param {string|undefined} stage The chosen stage name.
		 * @return {object} The fields to add to the saved lead.
		 * @spec openspec/changes/pipeline-numbers-tell-the-truth/specs/lead-management/spec.md
		 */
		stagePlacement(stage) {
			if (!stage) return {}
			const placement = {}
			const match = (this.selectedPipeline?.stages || []).find(
				(s) => s.name === stage,
			)
			if (match && match.order !== undefined && match.order !== null) {
				placement.stageOrder = Number(match.order)
			}
			if (!this.lead || this.lead.stage !== stage) {
				placement.stageEnteredAt = new Date().toISOString()
			}
			return placement
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-50
		 */
		onSave() {
			this.markSaveAttempted()
			if (!this.isValid) return

			const data = { ...this.form }
			// Clean null values
			if (data.value === null) delete data.value
			if (!data.currency) delete data.currency
			if (data.probability === null) delete data.probability
			if (!data.source) delete data.source
			if (!data.category) delete data.category
			if (!data.expectedCloseDate) delete data.expectedCloseDate
			if (!data.client) delete data.client
			if (!data.contact) delete data.contact
			if (!data.pipeline) delete data.pipeline
			if (!data.stage) delete data.stage
			Object.assign(data, this.stagePlacement(data.stage))

			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.lead-form {
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

.form-row .form-group--currency {
	flex: 0 0 96px;
}

.field-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.form-actions {
	display: flex;
	gap: 8px;
	margin-top: 20px;
}
</style>

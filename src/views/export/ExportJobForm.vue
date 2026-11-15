<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="
			isEdit
				? t('pipelinq', 'Edit export job')
				: t('pipelinq', 'New export job')
		"
		:loading="loading"
		@back="goBack">
		<CnDetailCard :title="t('pipelinq', 'Export job')">
			<div class="export-form">
				<NcTextField v-model="model.name" :label="t('pipelinq', 'Name')" />
				<NcTextField
					v-model="model.description"
					:label="t('pipelinq', 'Description')" />
				<NcSelect
					:modelValue="selectedSchemas"
					:options="schemaOptions"
					:inputLabel="t('pipelinq', 'Source schemas')"
					:placeholder="t('pipelinq', 'Choose schemas to export…')"
					label="label"
					:multiple="true"
					:keepOpen="true"
					@update:modelValue="onSchemasSelect" />
				<NcSelect
					:modelValue="selectedDelivery"
					:options="deliveryOptions"
					:inputLabel="t('pipelinq', 'Delivery')"
					label="label"
					:clearable="false"
					@update:modelValue="(o) => (delivery = o ? o.id : 'destination')" />
				<NcSelect
					v-if="delivery === 'mail'"
					:modelValue="selectedRecipients"
					:options="recipientOptions"
					:inputLabel="t('pipelinq', 'Recipients')"
					:placeholder="t('pipelinq', 'Search users and groups…')"
					label="label"
					trackBy="key"
					:multiple="true"
					:filterable="false"
					:loading="searchingRecipients"
					@search="onRecipientSearch"
					@update:modelValue="onRecipientsSelect" />
				<NcSelect
					v-else
					:modelValue="selectedDestination"
					:options="destinationOptions"
					:inputLabel="t('pipelinq', 'Destination')"
					:placeholder="t('pipelinq', 'Choose a destination…')"
					label="label"
					:clearable="false"
					@update:modelValue="
						(o) => (model.destinationId = o ? o.id : '')
					" />
				<NcSelect
					:modelValue="selectedFormat"
					:options="formatOptions"
					:inputLabel="t('pipelinq', 'Format')"
					label="label"
					:clearable="false"
					@update:modelValue="(o) => (model.format = o ? o.id : 'csv')" />
				<NcSelect
					:modelValue="selectedMode"
					:options="modeOptions"
					:inputLabel="t('pipelinq', 'Mode')"
					label="label"
					:clearable="false"
					@update:modelValue="(o) => (model.mode = o ? o.id : 'full')" />
				<NcTextField
					v-if="model.mode === 'incremental'"
					v-model="model.incrementalWatermarkColumn"
					:label="t('pipelinq', 'Watermark column')"
					:helperText="
						t(
							'pipelinq',
							'Column used to detect changed rows (e.g. updatedAt)',
						)
					" />
				<NcTextField
					v-model="model.scheduleCron"
					:label="t('pipelinq', 'Schedule (cron)')"
					:helperText="scheduleSummary"
					placeholder="0 2 * * *" />
				<NcTextField
					v-model="model.rowFilterExpression"
					:label="t('pipelinq', 'Row filter (optional)')"
					placeholder="status = 'open'" />
				<NcTextField
					v-model="allowlistText"
					:label="
						t('pipelinq', 'Column allowlist (optional, comma-separated)')
					"
					:helperText="
						t(
							'pipelinq',
							'Limit exported columns to minimise PII; leave empty to export all columns',
						)
					" />
			</div>

			<template #actions>
				<NcButton
					v-if="isEdit"
					variant="tertiary"
					:disabled="busy"
					@click="openTestRunModal">
					{{ t('pipelinq', 'Test run') }}
				</NcButton>
				<NcButton
					variant="primary"
					:disabled="busy || !model.name"
					@click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton variant="secondary" @click="goBack">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
			</template>
		</CnDetailCard>
		<ExportTestRunModal
			v-if="testRunOpen"
			:jobId="jobId"
			@close="testRunOpen = false" />
	</CnDetailPage>
</template>

<script>
import { CnDetailCard, CnDetailPage } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcSelect, NcTextField } from '@nextcloud/vue'
import ExportTestRunModal from '../../modals/ExportTestRunModal.vue'
import { collectionRows } from '../../services/collectionRows.js'
import { exportApi } from '../../services/exportApi.js'
import { toOptions, toRecipients } from '../../services/mailRecipients.js'
import { searchAssignees } from '../../services/taskUtils.js'
import { useObjectStore } from '../../store/modules/object.js'

// The pipelinq schemas the export pipeline may read. `ticket` is the unified
// supertype (unify-ticket-supertype): the former `request`, `complaint` and
// `contactmoment` schemas are one schema now, discriminated by `ticketType`.
// Exporting `ticket` therefore covers all three; narrow to a single subtype
// with a row filter (e.g. `ticketType = 'complaint'`).
const EXPORTABLE_SCHEMAS = [
	'client',
	'contact',
	'lead',
	'ticket',
	'crmTask',
	'product',
]
const FORMATS = ['csv', 'parquet', 'jsonl']
const MODES = ['full', 'incremental']
const DELIVERIES = ['destination', 'mail']
// How long the schedule field waits for typing to stop before asking for its words.
const DESCRIBE_DELAY_MS = 300

export default {
	name: 'ExportJobForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
		CnDetailPage,
		CnDetailCard,
		ExportTestRunModal,
	},

	props: {
		exportJobId: {
			type: String,
			default: null,
		},

		id: {
			type: String,
			default: null,
		},
	},

	setup() {
		return { objectStore: useObjectStore() }
	},

	data() {
		return {
			loading: false,
			busy: false,
			destinations: [],
			allowlistText: '',
			testRunOpen: false,
			delivery: 'destination',
			recipients: [],
			recipientOptions: [],
			knownRecipients: [],
			searchingRecipients: false,
			scheduleWords: '',
			describeTimer: null,
			model: {
				name: '',
				description: '',
				sourceSchemas: [],
				destinationId: '',
				format: 'csv',
				mode: 'full',
				incrementalWatermarkColumn: '',
				scheduleCron: '0 2 * * *',
				rowFilterExpression: '',
				columnAllowlist: [],
				enabled: false,
			},
		}
	},

	computed: {
		/**
		 * The resolved job id from either prop name.
		 *
		 * @return {string|null} The job UUID.
		 */
		jobId() {
			return this.exportJobId || this.id || this.$route?.params?.id || null
		},

		/**
		 * Whether the form is editing an existing job.
		 *
		 * @return {boolean} True when editing.
		 */
		isEdit() {
			return !!this.jobId
		},

		/**
		 * Source schema options.
		 *
		 * @return {Array<object>} The options.
		 */
		schemaOptions() {
			return EXPORTABLE_SCHEMAS.map((id) => ({ id, label: id }))
		},

		/**
		 * The currently selected schema options.
		 *
		 * @return {Array<object>} The selected options.
		 */
		selectedSchemas() {
			return this.schemaOptions.filter((o) =>
				(this.model.sourceSchemas || []).includes(o.id),
			)
		},

		/**
		 * Destination dropdown options.
		 *
		 * @return {Array<object>} The options.
		 */
		destinationOptions() {
			return this.destinations.filter((d) => d.type !== 'mail').map((d) => ({
				id: d.id,
				label:
					d.validationStatus === 'valid'
						? `${d.name} (${d.type})`
						: `${d.name} (${d.type} — ${this.t('pipelinq', 'unverified')})`,
			}))
		},

		/**
		 * The selected destination option.
		 *
		 * @return {object|null} The option.
		 */
		selectedDestination() {
			return (
				this.destinationOptions.find(
					(o) => o.id === this.model.destinationId,
				) || null
			)
		},

		/**
		 * Delivery options: a warehouse destination, or mail to users and groups.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
		 */
		deliveryOptions() {
			return DELIVERIES.map((id) => ({
				id,
				label: id === 'mail' ? this.t('pipelinq', 'Mail') : this.t('pipelinq', 'Destination'),
			}))
		},

		/**
		 * The selected delivery option.
		 *
		 * @return {object|null} The option.
		 */
		selectedDelivery() {
			return this.deliveryOptions.find((o) => o.id === this.delivery) || null
		},

		/**
		 * The picked recipients as picker options, labelled from the searches so far.
		 *
		 * @return {Array<object>} The options.
		 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
		 */
		selectedRecipients() {
			return toOptions(this.recipients, this.knownRecipients)
		},

		/**
		 * The mail destination this job already sends through, if any.
		 *
		 * @return {object|null} The destination.
		 */
		mailDestination() {
			const current = this.destinations.find((d) => d.id === this.model.destinationId)
			return current && current.type === 'mail' ? current : null
		},

		/**
		 * The schedule in words, and for mail who it goes to: "Every Monday at 07:00 to Team leads".
		 *
		 * Falls back to the expression itself while its words are not known.
		 *
		 * @return {string} The summary.
		 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
		 */
		scheduleSummary() {
			const schedule = this.scheduleWords || this.model.scheduleCron || ''
			if (this.delivery !== 'mail' || this.recipients.length === 0 || schedule === '') {
				return schedule
			}
			return this.t('pipelinq', '{schedule} to {recipients}', {
				schedule,
				recipients: this.selectedRecipients.map((o) => o.label).join(', '),
			})
		},

		/**
		 * Format options.
		 *
		 * @return {Array<object>} The options.
		 */
		formatOptions() {
			return FORMATS.map((id) => ({ id, label: id }))
		},

		/**
		 * The selected format option.
		 *
		 * @return {object|null} The option.
		 */
		selectedFormat() {
			return this.formatOptions.find((o) => o.id === this.model.format) || null
		},

		/**
		 * Mode options.
		 *
		 * @return {Array<object>} The options.
		 */
		modeOptions() {
			return MODES.map((id) => ({
				id,
				label: this.t(
					'pipelinq',
					id === 'full' ? 'Full refresh' : 'Incremental',
				),
			}))
		},

		/**
		 * The selected mode option.
		 *
		 * @return {object|null} The option.
		 */
		selectedMode() {
			return this.modeOptions.find((o) => o.id === this.model.mode) || null
		},
	},

	watch: {
		'model.scheduleCron': {
			handler(expression) {
				clearTimeout(this.describeTimer)
				this.describeTimer = setTimeout(() => this.describeSchedule(expression), DESCRIBE_DELAY_MS)
			},

			immediate: true,
		},
	},

	async mounted() {
		await this.loadDestinations()
		if (this.isEdit) {
			await this.load()
		}
	},

	beforeUnmount() {
		clearTimeout(this.describeTimer)
	},

	methods: {
		/**
		 * Load destinations for the dropdown.
		 *
		 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
		 */
		async loadDestinations() {
			try {
				await this.objectStore.fetchCollection('exportDestination', {
					_limit: 200,
				})
				this.destinations = collectionRows(this.objectStore.getCollection('exportDestination'))
			} catch {
				this.destinations = []
			}
		},

		/**
		 * Load the job for editing.
		 *
		 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-upload-with-retries-req-bie-008
		 */
		async load() {
			this.loading = true
			try {
				const existing = await this.objectStore.fetchObject(
					'exportJob',
					this.jobId,
				)
				if (existing) {
					this.model = { ...this.model, ...existing }
					this.allowlistText = (this.model.columnAllowlist || []).join(
						', ',
					)
					if (this.mailDestination) {
						this.delivery = 'mail'
						this.recipients = toRecipients(this.mailDestination.recipients)
					}
				}
			} catch {
				showError(this.t('pipelinq', 'Could not load the job'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Track the multi-select schema choice.
		 *
		 * @param {Array<object>} options The selected options.
		 */
		onSchemasSelect(options) {
			this.model.sourceSchemas = (options || []).map((o) => o.id)
		},

		/**
		 * Ask the server for the schedule in words; keep the expression when it has none.
		 *
		 * @param {string} expression The cron expression.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
		 */
		async describeSchedule(expression) {
			if (!expression) {
				this.scheduleWords = ''
				return
			}
			try {
				const result = await exportApi.describeSchedule(expression)
				if (expression === this.model.scheduleCron) {
					this.scheduleWords = result.valid ? result.description : ''
				}
			} catch {
				this.scheduleWords = ''
			}
		},

		/**
		 * Search users and groups for the recipients picker.
		 *
		 * @param {string} query What the user typed.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
		 */
		async onRecipientSearch(query) {
			this.searchingRecipients = true
			try {
				const matches = await searchAssignees(query)
				const found = toOptions(matches, matches)
				this.recipientOptions = found
				this.knownRecipients = [...this.knownRecipients, ...found]
			} finally {
				this.searchingRecipients = false
			}
		},

		/**
		 * Track the picked recipients.
		 *
		 * @param {Array<object>} options The picked options.
		 */
		onRecipientsSelect(options) {
			this.knownRecipients = [...this.knownRecipients, ...(options || [])]
			this.recipients = toRecipients(options)
		},

		/**
		 * Save the mail destination that carries this job's recipients, and return its id.
		 *
		 * @return {Promise<string>} The destination id.
		 * @throws {Error} When it could not be saved.
		 * @spec openspec/changes/reports-compare-periods-and-scheduled-delivery/specs/report-periods-and-delivery/spec.md#REQ-RPD-003
		 */
		async saveMailDestination() {
			const destination = {
				name: this.t('pipelinq', 'Mail: {name}', { name: this.model.name }),
				type: 'mail',
				recipients: this.recipients,
			}
			if (this.mailDestination) {
				destination.id = this.mailDestination.id
			}
			const saved = await this.objectStore.saveObject('exportDestination', destination)
			const id = destination.id || saved?.id || saved?.uuid
			if (!id) {
				throw new Error('The mail destination was not saved')
			}
			return id
		},

		/**
		 * Parse the comma-separated allowlist text into an array.
		 *
		 * @return {Array<string>} The allowlist columns.
		 */
		parsedAllowlist() {
			return this.allowlistText
				.split(',')
				.map((s) => s.trim())
				.filter((s) => s.length > 0)
		},

		/**
		 * Persist the job via the shared object store.
		 *
		 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-upload-with-retries-req-bie-008
		 */
		async save() {
			if (this.delivery === 'mail' && this.recipients.length === 0) {
				showError(this.t('pipelinq', 'Choose at least one recipient'))
				return
			}
			this.busy = true
			try {
				const payload = {
					...this.model,
					columnAllowlist: this.parsedAllowlist(),
				}
				if (this.delivery === 'mail') {
					payload.destinationId = await this.saveMailDestination()
					// A mailed report has no warehouse to test first: a new one runs on its schedule.
					if (!this.isEdit) {
						payload.enabled = true
					}
				}
				if (this.isEdit) {
					payload.id = this.jobId
				}
				await this.objectStore.saveObject('exportJob', payload)
				showSuccess(this.t('pipelinq', 'Export job saved'))
				this.$router.push({ name: 'ExportJobs' })
			} catch {
				showError(this.t('pipelinq', 'Could not save the job'))
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the dedicated test-run modal, which auto-executes the run.
		 *
		 * Surfaces validation status, sample row count, optional sample file
		 * download link and any errors per REQ-BIE-003.
		 */
		openTestRunModal() {
			if (!this.jobId) {
				showError(this.t('pipelinq', 'Save the job before running a test'))
				return
			}
			this.testRunOpen = true
		},

		/**
		 * Navigate back to the job list.
		 */
		goBack() {
			this.$router.push({ name: 'ExportJobs' })
		},
	},
}
</script>

<style scoped>
.export-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 560px;
}
</style>

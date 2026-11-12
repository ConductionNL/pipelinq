<template>
	<div class="create-lead-widget">
		<div v-if="!success" class="widget-form">
			<NcTextField
				v-model="form.title"
				:label="t('pipelinq', 'Title')"
				:placeholder="
					t(
						'pipelinq',
						'Lead title (required) — press Enter for quick add',
					)
				"
				:error="submitted && !form.title"
				@keyup.enter="onQuickAdd" />

			<ClientAutocomplete
				:value="selectedClient"
				:placeholder="t('pipelinq', 'Search client…')"
				:label="t('pipelinq', 'Client')"
				@input="onClientSelected" />

			<NcSelect
				v-model="form.pipeline"
				:options="pipelineOptions"
				:inputLabel="t('pipelinq', 'Pipeline')"
				:placeholder="t('pipelinq', 'Pipeline')"
				label="label"
				trackBy="id"
				inputId="lead-pipeline" />

			<NcTextField
				v-model="form.value"
				:label="t('pipelinq', 'Value')"
				:placeholder="t('pipelinq', 'Estimated value (EUR)')"
				type="number" />

			<NcSelect
				v-model="form.source"
				:options="sourceOptions"
				:inputLabel="t('pipelinq', 'Source')"
				:placeholder="t('pipelinq', 'Source')"
				inputId="lead-source" />

			<NcButton variant="primary" :disabled="submitting" @click="onSubmit">
				{{
					submitting
						? t('pipelinq', 'Creating…')
						: t('pipelinq', 'Create lead')
				}}
			</NcButton>
		</div>

		<div v-else class="widget-success">
			<NcNoteCard type="success">
				{{ t('pipelinq', 'Lead created!') }}
				<a :href="successLink">{{ t('pipelinq', 'View lead') }}</a>
			</NcNoteCard>
			<NcButton variant="secondary" @click="resetForm">
				{{ t('pipelinq', 'Create another') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import ClientAutocomplete from '../../components/widgets/ClientAutocomplete.vue'
import { leadSourceLabel } from '../../services/leadSourceLabels.js'
import { initializeStores } from '../../store/store.js'
import { toText } from '../../utils/widgetText.js'
import createWidgetMixin from './createWidgetMixin.js'
import { createWidgetObject, optionValue, readWidgetObjects, widgetObjectsUrl } from './widgetObjects.js'

export default {
	name: 'CreateLeadWidget',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcNoteCard,
		ClientAutocomplete,
	},

	mixins: [createWidgetMixin],

	data() {
		return {
			pipelines: [],
			form: {
				title: '',
				pipeline: null,
				value: '',
				source: null,
			},

			sourceOptions: [
				'website',
				'referral',
				'cold-call',
				'advertisement',
				'event',
				'other',
			].map((id) => ({ id, label: leadSourceLabel(id) })),
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-26
		 */
		pipelineOptions() {
			return this.pipelines.map((p) => ({
				id: p.id,
				label: toText(p.title) || t('pipelinq', 'Unnamed pipeline'),
				stages: p.stages || [],
			}))
		},
	},

	/**
	 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-22
	 */
	async mounted() {
		try {
			const { objectStore } = await initializeStores()
			this.config = objectStore.objectTypeRegistry
			await this.fetchPipelines()
		} catch (err) {
			console.error('CreateLeadWidget init error:', err)
		}
	},

	methods: {
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-20
		 */
		async fetchPipelines() {
			if (!this.config?.pipeline) return
			try {
				const pipelines = await readWidgetObjects(widgetObjectsUrl(this.config.pipeline, '?_limit=50'))
				if (pipelines === null) return
				this.pipelines = pipelines

				// Pre-select first pipeline
				if (this.pipelines.length > 0 && !this.form.pipeline) {
					this.form.pipeline = this.pipelineOptions[0]
				}
			} catch (err) {
				console.error('Failed to fetch pipelines:', err)
			}
		},

		/**
		 * @param {object} pipeline The pipeline whose first open stage to take.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-21
		 */
		getFirstStage(pipeline) {
			const stages = pipeline?.stages || []
			if (stages.length === 0) return { name: '', order: 1 }
			const sorted = [...stages].sort(
				(a, b) => (a.order || 0) - (b.order || 0),
			)
			return sorted[0]
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-24
		 */
		onQuickAdd() {
			if (this.form.title) {
				this.onSubmit()
			}
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-25
		 */
		async onSubmit() {
			this.submitted = true
			if (!this.form.title) {
				return
			}
			if (!this.config?.lead) {
				console.error('Lead schema not configured')
				return
			}

			this.submitting = true
			try {
				const id = await createWidgetObject(this.config.lead, {
					body: this.leadBody(),
					failure: 'Failed to create lead',
				})
				this.successLink = generateUrl('/apps/pipelinq/leads/' + id)
				this.success = true
			} catch (err) {
				console.error('CreateLeadWidget create error:', err)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * The lead to post: on the first stage of the chosen pipeline, with
		 * the client, value and source when given.
		 *
		 * @return {object} The lead.
		 */
		leadBody() {
			const selectedPipeline = this.form.pipeline
			const firstStage = this.getFirstStage(selectedPipeline)

			const body = {
				title: this.form.title,
				status: 'open',
				stage: firstStage.name,
				stageOrder: firstStage.order || 1,
			}

			if (selectedPipeline) {
				body.pipeline = selectedPipeline.id
			}
			return { ...body, ...this.leadExtras() }
		},

		/**
		 * @return {object} The client, value and source of the lead, each only when given.
		 */
		leadExtras() {
			const extras = {}
			if (this.selectedClient) {
				extras.client = this.selectedClient.id
			}
			if (this.form.value) {
				extras.value = parseFloat(this.form.value) || 0
			}
			if (this.form.source) {
				extras.source = optionValue(this.form.source)
			}
			return extras
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-27
		 */
		resetForm() {
			this.form = {
				title: '',
				pipeline:
					this.pipelineOptions.length > 0 ? this.pipelineOptions[0] : null,

				value: '',
				source: null,
			}
			this.selectedClient = null
			this.submitted = false
			this.success = false
			this.successLink = ''
		},
	},
}
</script>

<style scoped src="./createWidget.css"></style>

<style scoped>
.create-lead-widget {
	padding: 12px 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>

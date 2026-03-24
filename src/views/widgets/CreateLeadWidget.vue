<template>
	<div class="create-lead-widget">
		<div v-if="!success" class="widget-form">
			<NcTextField :value.sync="form.title"
				:label="t('pipelinq', 'Title')"
				:placeholder="t('pipelinq', 'Lead title (required) — press Enter for quick add')"
				:error="submitted && !form.title"
				@keyup.enter="onQuickAdd" />

			<ClientAutocomplete :value="selectedClient"
				:placeholder="t('pipelinq', 'Search client...')"
				:label="t('pipelinq', 'Client')"
				@input="onClientSelected" />

			<NcSelect v-model="form.pipeline"
				:options="pipelineOptions"
				:placeholder="t('pipelinq', 'Pipeline')"
				label="label"
				track-by="id"
				input-id="lead-pipeline" />

			<NcTextField :value.sync="form.value"
				:label="t('pipelinq', 'Value')"
				:placeholder="t('pipelinq', 'Estimated value (EUR)')"
				type="number" />

			<NcSelect v-model="form.source"
				:options="sourceOptions"
				:placeholder="t('pipelinq', 'Source')"
				input-id="lead-source" />

			<NcButton type="primary"
				:disabled="submitting"
				@click="onSubmit">
				{{ submitting ? t('pipelinq', 'Creating...') : t('pipelinq', 'Create lead') }}
			</NcButton>
		</div>

		<div v-else class="widget-success">
			<NcNoteCard type="success">
				{{ t('pipelinq', 'Lead created!') }}
				<a :href="successLink">{{ t('pipelinq', 'View lead') }}</a>
			</NcNoteCard>
			<NcButton type="secondary" @click="resetForm">
				{{ t('pipelinq', 'Create another') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcTextField, NcButton, NcSelect, NcNoteCard } from '@nextcloud/vue'
import ClientAutocomplete from '../../components/widgets/ClientAutocomplete.vue'
import { initializeStores } from '../../store/store.js'

export default {
	name: 'CreateLeadWidget',
	components: {
		NcTextField,
		NcButton,
		NcSelect,
		NcNoteCard,
		ClientAutocomplete,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			config: null,
			pipelines: [],
			form: {
				title: '',
				pipeline: null,
				value: '',
				source: null,
			},
			selectedClient: null,
			submitted: false,
			submitting: false,
			success: false,
			successLink: '',
			sourceOptions: [
				{ id: 'website', label: t('pipelinq', 'Website') },
				{ id: 'referral', label: t('pipelinq', 'Referral') },
				{ id: 'cold-call', label: t('pipelinq', 'Cold call') },
				{ id: 'advertisement', label: t('pipelinq', 'Advertisement') },
				{ id: 'event', label: t('pipelinq', 'Event') },
				{ id: 'other', label: t('pipelinq', 'Other') },
			],
		}
	},
	computed: {
		pipelineOptions() {
			return this.pipelines.map((p) => ({
				id: p.id,
				label: p.title || t('pipelinq', 'Unnamed pipeline'),
				stages: p.stages || [],
			}))
		},
	},
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
		onClientSelected(client) {
			this.selectedClient = client
		},
		async fetchPipelines() {
			if (!this.config?.pipeline) return
			try {
				const typeConfig = this.config.pipeline
				const url = '/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema
					+ '?_limit=50'

				const response = await fetch(url, {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})

				if (!response.ok) return
				const data = await response.json()
				this.pipelines = data.results || data || []

				// Pre-select first pipeline
				if (this.pipelines.length > 0 && !this.form.pipeline) {
					this.form.pipeline = this.pipelineOptions[0]
				}
			} catch (err) {
				console.error('Failed to fetch pipelines:', err)
			}
		},
		getFirstStage(pipeline) {
			const stages = pipeline?.stages || []
			if (stages.length === 0) return { name: '', order: 1 }
			const sorted = [...stages].sort((a, b) => (a.order || 0) - (b.order || 0))
			return sorted[0]
		},
		onQuickAdd() {
			if (this.form.title) {
				this.onSubmit()
			}
		},
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
				const typeConfig = this.config.lead
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
				if (this.selectedClient) {
					body.client = this.selectedClient.id
				}
				if (this.form.value) {
					body.value = parseFloat(this.form.value) || 0
				}
				if (this.form.source) {
					body.source = typeof this.form.source === 'object'
						? this.form.source.id
						: this.form.source
				}

				const url = '/apps/openregister/api/objects/'
					+ typeConfig.register + '/' + typeConfig.schema

				const response = await fetch(url, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})

				if (!response.ok) throw new Error('Failed to create lead')
				const created = await response.json()
				const id = created.id || created.uuid
				this.successLink = '/index.php/apps/pipelinq/leads/' + id
				this.success = true
			} catch (err) {
				console.error('CreateLeadWidget create error:', err)
			} finally {
				this.submitting = false
			}
		},
		resetForm() {
			this.form = {
				title: '',
				pipeline: this.pipelineOptions.length > 0 ? this.pipelineOptions[0] : null,
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

<style scoped>
.create-lead-widget {
	padding: 12px 16px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.widget-form {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.widget-success {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>

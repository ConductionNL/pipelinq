<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<CnDetailPage
		:title="request.kenmerk || t('pipelinq', 'AVG request')"
		:subtitle="articleLabel(request.artikel)"
		:back-route="{ name: 'AvgRequests' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading">
		<template #actions>
			<DpiaFlagBadge :flagged="!!request.dpiaFlag" />
			<NcButton v-if="!readOnly" :disabled="busy" @click="extend">
				{{ t('pipelinq', 'Extend +60 days') }}
			</NcButton>
		</template>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div class="avg-detail__deadline">
			<DeadlineCounter :deadline="request.wettelijkeTermijnVerloopt" :extended-days="request.verlengdMet || 0" />
		</div>

		<div class="avg-detail__tabs">
			<NcButton v-for="tab in tabs"
				:key="tab.id"
				:type="activeTab === tab.id ? 'primary' : 'tertiary'"
				@click="activeTab = tab.id">
				{{ tab.label }}
			</NcButton>
		</div>

		<section v-if="activeTab === 'intake'" class="avg-detail__section">
			<dl class="avg-detail__grid">
				<dt>{{ t('pipelinq', 'Reference') }}</dt>
				<dd>{{ request.kenmerk }}</dd>
				<dt>{{ t('pipelinq', 'Article') }}</dt>
				<dd>{{ articleLabel(request.artikel) }}</dd>
				<dt>{{ t('pipelinq', 'Submitted via') }}</dt>
				<dd>{{ request.ingediendVia }}</dd>
				<dt>{{ t('pipelinq', 'Question') }}</dt>
				<dd>{{ request.specifiekeVraag }}</dd>
				<dt>{{ t('pipelinq', 'Scope') }}</dt>
				<dd>{{ (request.scope || []).join(', ') }}</dd>
			</dl>
		</section>

		<section v-else-if="activeTab === 'evidence'" class="avg-detail__section">
			<EvidenceCollector :verzoek-id="id" @collected="reloadEvidence" />
		</section>

		<section v-else-if="activeTab === 'redaction'" class="avg-detail__section">
			<RedactionEditor :verzoek-id="id" :items="evidenceItems" @redacted="reloadEvidence" />
		</section>

		<section v-else-if="activeTab === 'bundle'" class="avg-detail__section">
			<BundlePreview :verzoek-id="id" />
		</section>

		<section v-else-if="activeTab === 'denial'" class="avg-detail__section">
			<DenialForm :verzoek-id="id" />
		</section>
	</CnDetailPage>
</template>

<script>
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import BundlePreview from '../../components/avg/BundlePreview.vue'
import DeadlineCounter from '../../components/avg/DeadlineCounter.vue'
import DenialForm from '../../components/avg/DenialForm.vue'
import DpiaFlagBadge from '../../components/avg/DpiaFlagBadge.vue'
import EvidenceCollector from '../../components/avg/EvidenceCollector.vue'
import RedactionEditor from '../../components/avg/RedactionEditor.vue'
import { articleLabel } from '../../utils/avg/avgLabels.js'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'AvgRequestDetail',
	components: {
		BundlePreview,
		CnDetailPage,
		DeadlineCounter,
		DenialForm,
		DpiaFlagBadge,
		EvidenceCollector,
		NcButton,
		NcNoteCard,
		RedactionEditor,
	},
	data() {
		return {
			request: {},
			evidenceItems: [],
			loading: true,
			busy: false,
			error: '',
			activeTab: 'intake',
		}
	},
	computed: {
		/**
		 * The current request id from the route.
		 *
		 * @return {string} The id.
		 */
		id() {
			return this.$route.params.id
		},
		/**
		 * Whether the request is read-only (resolved / archived).
		 *
		 * @return {boolean} True when read-only.
		 */
		readOnly() {
			return ['afgerond', 'gearchiveerd'].includes(this.request.status)
		},
		/**
		 * The tab definitions.
		 *
		 * @return {Array<object>} The tabs.
		 */
		tabs() {
			return [
				{ id: 'intake', label: this.t('pipelinq', 'Intake') },
				{ id: 'evidence', label: this.t('pipelinq', 'Evidence') },
				{ id: 'redaction', label: this.t('pipelinq', 'Redaction') },
				{ id: 'bundle', label: this.t('pipelinq', 'Bundle') },
				{ id: 'denial', label: this.t('pipelinq', 'Denial') },
			]
		},
	},
	async mounted() {
		await this.load()
		await this.reloadEvidence()
	},
	methods: {
		articleLabel,
		/**
		 * Load the request.
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const { verzoek } = await avgApi.get(this.id)
				this.request = verzoek
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('pipelinq', 'Could not load the request')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Reload the evidence items.
		 */
		async reloadEvidence() {
			try {
				const { bewijsItems } = await avgApi.evidenceItems(this.id)
				this.evidenceItems = bewijsItems || []
			} catch (e) {
				this.evidenceItems = []
			}
		},
		/**
		 * Request a 60-day extension with a justification prompt.
		 */
		async extend() {
			const reason = window.prompt(this.t('pipelinq', 'Justification for the 60-day extension (min. 30 characters)'), '')
			if (!reason) {
				return
			}
			this.busy = true
			this.error = ''
			try {
				const { verzoek } = await avgApi.extend(this.id, reason)
				this.request = verzoek
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('pipelinq', 'Could not extend the request')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.avg-detail__deadline { margin-bottom: 12px; }
.avg-detail__tabs { display: flex; gap: 4px; flex-wrap: wrap; margin-bottom: 16px; }
.avg-detail__section { padding: 8px 0; }
.avg-detail__grid { display: grid; grid-template-columns: max-content 1fr; gap: 6px 16px; }
.avg-detail__grid dt { font-weight: bold; }
</style>

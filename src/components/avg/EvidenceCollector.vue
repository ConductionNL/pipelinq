<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-evidence">
		<div class="avg-evidence__bar">
			<NcButton type="primary" :disabled="collecting" @click="collect">
				<template v-if="collecting" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('pipelinq', 'Collect evidence from sources') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="summary && summary.failed > 0" type="warning">
			{{ n('pipelinq', '%n source did not respond (manual supplementation may be needed).',
				'%n sources did not respond (manual supplementation may be needed).', summary.failed) }}
		</NcNoteCard>

		<ul v-if="summary" class="avg-evidence__sources">
			<li v-for="(src, i) in summary.sources" :key="i" :class="`avg-evidence__source--${src.status}`">
				{{ src.source }} — {{ n('pipelinq', '%n item', '%n items', src.count) }} ({{ src.status }})
			</li>
		</ul>

		<NcEmptyContent v-if="items.length === 0 && !collecting"
			:name="t('pipelinq', 'No evidence collected yet')" />

		<table v-else-if="items.length" class="avg-evidence__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Source') }}</th>
					<th>{{ t('pipelinq', 'Category') }}</th>
					<th>{{ t('pipelinq', 'Legal basis') }}</th>
					<th>{{ t('pipelinq', 'Duplicate') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="item in items" :key="rowKey(item)">
					<td>{{ item.bronApp }}</td>
					<td>{{ item.categorie }}</td>
					<td>{{ item.rechtsgrond }}</td>
					<td>{{ item.gedupliceerd ? t('pipelinq', 'Yes') : '' }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'EvidenceCollector',
	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		/** The parent request id. */
		verzoekId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			collecting: false,
			summary: null,
			items: [],
		}
	},
	async mounted() {
		await this.loadItems()
	},
	methods: {
		/**
		 * Stable row key for an evidence item.
		 *
		 * @param {object} item The evidence item.
		 * @return {string} The key.
		 */
		rowKey(item) {
			return item.id || item['@self']?.id || `${item.bronApp}-${item.bronObject}`
		},
		/**
		 * Trigger a federated evidence-collection pass.
		 */
		async collect() {
			this.collecting = true
			try {
				const { summary } = await avgApi.collectEvidence(this.verzoekId)
				this.summary = summary
				await this.loadItems()
			} catch (e) {
				this.summary = null
			} finally {
				this.collecting = false
			}
		},
		/**
		 * Load the collected evidence items.
		 */
		async loadItems() {
			try {
				const { bewijsItems } = await avgApi.evidenceItems(this.verzoekId)
				this.items = bewijsItems || []
			} catch (e) {
				this.items = []
			}
		},
	},
}
</script>

<style scoped>
.avg-evidence { display: flex; flex-direction: column; gap: 12px; }
.avg-evidence__sources { list-style: none; padding: 0; margin: 0; }
.avg-evidence__source--timeout { color: var(--color-warning); }
.avg-evidence__source--success { color: var(--color-success); }
.avg-evidence__table { width: 100%; border-collapse: collapse; }
.avg-evidence__table th, .avg-evidence__table td {
	text-align: left; padding: 4px 8px; border-bottom: 1px solid var(--color-border);
}
</style>

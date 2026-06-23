<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - MDM golden-record in-body section (kind:'section') for the declarative
  - type:"detail" MdmMasterEntityDetail page (pipelinq-pos-mdm-detail-declarative).
  - The masterEntity register object's flat fields (masterId / entityType / status
  - / dataQualityScore / …) auto-render via CnObjectDataWidget and its raw
  - sourceRecord children render as a relatedCollections table; this section adds
  - the parts no declarative primitive can express:
  -   1. the server-COMPUTED golden record (survivorship of the merge rules) with
  -      per-attribute provenance (sourceSystem + trustTier) — a projection from
  -      GET /api/mdm/entities/{id}, not stored flat on the schema;
  -   2. the derived source-record lineage list (linkageMethod + confidence);
  -   3. the "Resolve conflicts" modal which recomputes the golden record on save.
  -
  - Self-fetches the projection by id (passed as `masterId` via @objectId, with a
  - cnSectionContext inject fallback) so it refreshes after a conflict resolution.
  -->
<template>
	<div class="mdm-golden-section">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent v-else-if="!entity"
			:name="t('pipelinq', 'Master entity projection not available')" />

		<template v-else>
			<section class="mdm-golden-section__header">
				<NcButton type="secondary" @click="openConflictResolution">
					{{ t('pipelinq', 'Resolve conflicts') }}
				</NcButton>
			</section>

			<section class="mdm-golden-section__block">
				<h4>{{ t('pipelinq', 'Golden record') }}</h4>
				<table class="mdm-golden-section__table">
					<tbody>
						<tr v-for="(value, key) in entity.goldenRecord" :key="key">
							<th>{{ key }}</th>
							<td>{{ value }}</td>
							<td class="mdm-golden-section__prov">
								<span v-if="provenance(key)">
									{{ provenance(key).sourceSystem }} ·
									{{ provenance(key).trustTier }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="mdm-golden-section__block">
				<h4>{{ t('pipelinq', 'Source record lineage') }}</h4>
				<table v-if="sourceRecords.length" class="mdm-golden-section__table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Source system') }}</th>
							<th>{{ t('pipelinq', 'Last seen') }}</th>
							<th>{{ t('pipelinq', 'Linkage') }}</th>
							<th>{{ t('pipelinq', 'Confidence') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="record in sourceRecords" :key="record.sourceRecordId || record.id">
							<td>{{ record.sourceSystem }}</td>
							<td>{{ formatDate(record.lastSeen) }}</td>
							<td>{{ record.linkageMethod }}</td>
							<td>{{ Number(record.linkageConfidence || 0).toFixed(2) }}</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent v-else
					:name="t('pipelinq', 'No source records linked')" />
			</section>

			<MdmConflictResolutionModal v-if="showConflict"
				:entity="entity"
				:source-records="sourceRecords"
				@close="showConflict = false"
				@saved="onConflictSaved" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import MdmConflictResolutionModal from '../../modals/MdmConflictResolutionModal.vue'

export default {
	name: 'MdmGoldenRecordSection',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, MdmConflictResolutionModal },
	inject: {
		cnSectionContext: { default: null },
	},
	props: {
		/** The master-entity id (token-resolved from @objectId by CnBodySections). */
		masterId: {
			type: String,
			default: '',
		},
	},
	data() {
		return {
			loading: true,
			entity: null,
			sourceRecords: [],
			showConflict: false,
		}
	},
	computed: {
		/** The resolved master-entity id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.masterId) {
				return this.masterId
			}
			const ctx = this.cnSectionContext
			const bag = (ctx && typeof ctx === 'object' && 'value' in ctx) ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},
	},
	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.fetchData()
			},
		},
	},
	methods: {
		/**
		 * Fetch the server-computed golden-record projection for this master entity.
		 */
		async fetchData() {
			if (!this.resolvedId) {
				this.loading = false
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mdm/entities/{id}', { id: this.resolvedId }))
				this.entity = data.entity || null
				this.sourceRecords = data.sourceRecords || []
			} catch (e) {
				this.entity = null
			} finally {
				this.loading = false
			}
		},
		provenance(key) {
			return this.entity && this.entity.attributeProvenance && this.entity.attributeProvenance[key]
		},
		formatDate(value) {
			if (!value) return '—'
			return new Date(value).toLocaleString()
		},
		openConflictResolution() {
			this.showConflict = true
		},
		onConflictSaved() {
			this.showConflict = false
			this.fetchData()
		},
	},
}
</script>

<style scoped lang="scss">
.mdm-golden-section {
	display: flex;
	flex-direction: column;
	gap: 20px;

	&__header {
		display: flex;
		justify-content: flex-end;
	}

	&__block h4 {
		margin: 0 0 8px;
		font-weight: 600;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th, td {
			text-align: left;
			padding: 8px 12px;
			border-bottom: 1px solid var(--color-border);
		}
	}

	&__prov {
		color: var(--color-text-maxcontrast);
		font-size: 12px;
	}
}
</style>

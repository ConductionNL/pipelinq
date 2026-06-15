<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="mdm-detail">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="!entity"
			:name="t('pipelinq', 'Master entity not found')" />

		<template v-else>
			<div class="mdm-detail__header">
				<h2>{{ goldenName }}</h2>
				<NcButton type="secondary" @click="openConflictResolution">
					{{ t('pipelinq', 'Resolve conflicts') }}
				</NcButton>
			</div>

			<section>
				<h3>{{ t('pipelinq', 'Golden record') }}</h3>
				<table class="mdm-detail__table">
					<tbody>
						<tr v-for="(value, key) in entity.goldenRecord" :key="key">
							<th>{{ key }}</th>
							<td>{{ value }}</td>
							<td class="mdm-detail__prov">
								<span v-if="provenance(key)">
									{{ provenance(key).sourceSystem }} ·
									{{ provenance(key).trustTier }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section>
				<h3>{{ t('pipelinq', 'Source record lineage') }}</h3>
				<table v-if="sourceRecords.length" class="mdm-detail__table">
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
	name: 'MdmMasterEntityDetailView',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, MdmConflictResolutionModal },
	data() {
		return {
			loading: true,
			entity: null,
			sourceRecords: [],
			showConflict: false,
		}
	},
	computed: {
		masterId() {
			return this.$route.params.id
		},
		goldenName() {
			return (this.entity && this.entity.goldenRecord && this.entity.goldenRecord.name) || this.masterId
		},
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mdm/entities/{id}', { id: this.masterId }))
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
.mdm-detail {
	padding: 20px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 16px;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;
		margin-bottom: 24px;

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

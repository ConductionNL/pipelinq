<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="mdm-list">
		<div class="mdm-list__header">
			<h2>{{ t('pipelinq', 'Master entities') }}</h2>
			<div class="mdm-list__filters">
				<NcSelect v-model="entityType"
					:options="entityTypeOptions"
					:input-label="t('pipelinq', 'Entity type')"
					:placeholder="t('pipelinq', 'All types')"
					@update:model-value="fetchData" />
				<NcCheckboxRadioSwitch :model-value="onlyLowQuality"
					type="switch"
					@update:model-value="toggleLowQuality">
					{{ t('pipelinq', 'Only low quality (< 0.6)') }}
				</NcCheckboxRadioSwitch>
			</div>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="!entities.length"
			:name="t('pipelinq', 'No master entities found')" />

		<table v-else class="mdm-list__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Name') }}</th>
					<th>{{ t('pipelinq', 'Type') }}</th>
					<th>{{ t('pipelinq', 'Quality score') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Last reviewed') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="entity in entities"
					:key="entity.masterId || entity.id"
					class="mdm-list__row"
					@click="openDetail(entity)">
					<td>{{ goldenName(entity) }}</td>
					<td>{{ entity.entityType }}</td>
					<td>
						<span class="mdm-list__badge" :class="badgeClass(entity.dataQualityScore)">
							{{ Number(entity.dataQualityScore || 0).toFixed(2) }}
						</span>
					</td>
					<td>{{ entity.status }}</td>
					<td>{{ formatDate(entity.lastReviewedAt) }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'MdmMasterEntityListView',
	components: { NcCheckboxRadioSwitch, NcEmptyContent, NcLoadingIcon, NcSelect },
	data() {
		return {
			loading: true,
			entities: [],
			entityType: null,
			onlyLowQuality: false,
			entityTypeOptions: ['contact', 'account', 'product', 'vendor'],
		}
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const params = {}
				if (this.entityType) params.entityType = this.entityType
				if (this.onlyLowQuality) params.maxScore = 0.6
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mdm/entities'), { params })
				this.entities = data.entities || []
			} catch (e) {
				this.entities = []
			} finally {
				this.loading = false
			}
		},
		toggleLowQuality(value) {
			this.onlyLowQuality = value
			this.fetchData()
		},
		goldenName(entity) {
			return (entity.goldenRecord && entity.goldenRecord.name) || entity.masterId || entity.id
		},
		badgeClass(score) {
			const value = Number(score || 0)
			if (value > 0.8) return 'mdm-list__badge--good'
			if (value >= 0.6) return 'mdm-list__badge--fair'
			return 'mdm-list__badge--poor'
		},
		formatDate(value) {
			if (!value) return '—'
			return new Date(value).toLocaleDateString()
		},
		openDetail(entity) {
			const id = entity.masterId || entity.id
			this.$router.push({ path: `/mdm/master-entities/${id}` })
		},
	},
}
</script>

<style scoped lang="scss">
.mdm-list {
	padding: 20px;

	&__header {
		margin-bottom: 16px;
	}

	&__filters {
		display: flex;
		align-items: center;
		gap: 16px;
		margin-top: 8px;
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

	&__row {
		cursor: pointer;

		&:hover {
			background: var(--color-background-hover);
		}
	}

	&__badge {
		padding: 2px 8px;
		border-radius: var(--border-radius-pill);
		font-weight: 600;

		&--good { background: var(--color-success, #2d7d46); color: #fff; }
		&--fair { background: var(--color-warning, #c28a00); color: #fff; }
		&--poor { background: var(--color-error, #c0392b); color: #fff; }
	}
}
</style>

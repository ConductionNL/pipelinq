<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="mdm-dup">
		<div class="mdm-dup__header">
			<h2>{{ t('pipelinq', 'Duplicate candidates') }}</h2>
			<NcSelect v-model="entityType"
				:options="entityTypeOptions"
				:input-label="t('pipelinq', 'Entity type')"
				@update:model-value="fetchData" />
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="!candidates.length"
			:name="t('pipelinq', 'No duplicate candidates found')" />

		<table v-else class="mdm-dup__table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Surviving entity') }}</th>
					<th>{{ t('pipelinq', 'Duplicate entity') }}</th>
					<th>{{ t('pipelinq', 'Method') }}</th>
					<th>{{ t('pipelinq', 'Confidence') }}</th>
					<th>{{ t('pipelinq', 'Matched on') }}</th>
					<th />
				</tr>
			</thead>
			<tbody>
				<tr v-for="candidate in candidates" :key="candidate.fromMasterId + candidate.intoMasterId">
					<td>{{ name(candidate.intoEntity) }}</td>
					<td>{{ name(candidate.fromEntity) }}</td>
					<td>{{ candidate.linkageMethod }}</td>
					<td>{{ Number(candidate.linkageConfidence || 0).toFixed(2) }}</td>
					<td>{{ candidate.matchedOn }}</td>
					<td>
						<NcButton type="primary" @click="openMerge(candidate)">
							{{ t('pipelinq', 'Review merge') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>

		<MdmMergeWizardModal v-if="activeCandidate"
			:candidate="activeCandidate"
			@close="activeCandidate = null"
			@merged="onMerged" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'
import MdmMergeWizardModal from '../../modals/MdmMergeWizardModal.vue'

export default {
	name: 'MdmDuplicateCandidatesDashboard',
	components: { NcButton, NcEmptyContent, NcLoadingIcon, NcSelect, MdmMergeWizardModal },
	data() {
		return {
			loading: true,
			candidates: [],
			entityType: 'account',
			entityTypeOptions: ['contact', 'account', 'product', 'vendor'],
			activeCandidate: null,
		}
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl('/apps/pipelinq/api/mdm/duplicates/{entityType}', { entityType: this.entityType }),
				)
				this.candidates = data.candidates || []
			} catch (e) {
				this.candidates = []
			} finally {
				this.loading = false
			}
		},
		name(entity) {
			if (!entity) return '—'
			return (entity.goldenRecord && entity.goldenRecord.name) || entity.masterId || entity.id
		},
		openMerge(candidate) {
			this.activeCandidate = candidate
		},
		onMerged() {
			this.activeCandidate = null
			this.fetchData()
		},
	},
}
</script>

<style scoped lang="scss">
.mdm-dup {
	padding: 20px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		gap: 16px;
		margin-bottom: 16px;
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
}
</style>

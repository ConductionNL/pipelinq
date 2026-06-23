<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<!--
  Master-data DATA-QUALITY in-body section, hosted on the declarative
  type:dashboard "Data quality" page via a kind:'section' bodyWidget
  (pipelinq-dashboards-declarative). The four headline KPIs (average score +
  good/fair/poor buckets) render as endpoint-bound stat widgets in the
  dashboard grid; everything the stat grid cannot express lives here:
  the lowest-quality master-entity table, the sync-queue health cards, and
  the dead-letter retry table (whose Retry POSTs to
  /api/mdm/sync-queue/{id}/retry with a re-queue side-effect). Self-fetches
  GET /api/mdm/dashboard so a Retry refreshes the section in place.
-->
<template>
	<div class="mdm-dq-section">
		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="error"
			:name="t('pipelinq', 'Could not load the dashboard')"
			:description="error" />

		<template v-else>
			<h3>{{ t('pipelinq', 'Lowest quality master entities') }}</h3>
			<table v-if="worstEntities.length" class="mdm-dq-section__table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Name') }}</th>
						<th>{{ t('pipelinq', 'Type') }}</th>
						<th>{{ t('pipelinq', 'Quality score') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="entity in worstEntities" :key="entity.masterId">
						<td>{{ entity.name || entity.masterId }}</td>
						<td>{{ entity.entityType }}</td>
						<td>
							<span class="mdm-dq-section__badge" :class="badgeClass(entity.dataQualityScore)">
								{{ entity.dataQualityScore.toFixed(2) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
			<NcEmptyContent v-else
				:name="t('pipelinq', 'No master entities yet')" />

			<h3>{{ t('pipelinq', 'Sync queue health') }}</h3>
			<div class="mdm-dq-section__cards">
				<div class="mdm-dq-section__card">
					<div class="mdm-dq-section__value">
						{{ queueHealth.queued }}
					</div>
					<div class="mdm-dq-section__cardlabel">
						{{ t('pipelinq', 'Queued') }}
					</div>
				</div>
				<div class="mdm-dq-section__card">
					<div class="mdm-dq-section__value">
						{{ queueHealth.acknowledged }}
					</div>
					<div class="mdm-dq-section__cardlabel">
						{{ t('pipelinq', 'Acknowledged') }}
					</div>
				</div>
				<div class="mdm-dq-section__card mdm-dq-section__card--poor">
					<div class="mdm-dq-section__value">
						{{ queueHealth['dead-letter'] }}
					</div>
					<div class="mdm-dq-section__cardlabel">
						{{ t('pipelinq', 'Dead-letter') }}
					</div>
				</div>
			</div>

			<template v-if="deadLetters.length">
				<h3>{{ t('pipelinq', 'Dead-letter items') }}</h3>
				<table class="mdm-dq-section__table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Target system') }}</th>
							<th>{{ t('pipelinq', 'Change') }}</th>
							<th>{{ t('pipelinq', 'Error') }}</th>
							<th />
						</tr>
					</thead>
					<tbody>
						<tr v-for="item in deadLetters" :key="item.id">
							<td>{{ item.targetSystem }}</td>
							<td>{{ item.changeType }}</td>
							<td>{{ item.errorMessage }}</td>
							<td>
								<NcButton type="tertiary" @click="retry(item)">
									{{ t('pipelinq', 'Retry') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

export default {
	name: 'MdmDataQualitySection',
	components: { NcButton, NcEmptyContent, NcLoadingIcon },
	data() {
		return {
			loading: true,
			error: '',
			worstEntities: [],
			queueHealth: { queued: 0, sending: 0, acknowledged: 0, 'dead-letter': 0, failed: 0 },
			deadLetters: [],
		}
	},
	mounted() {
		this.fetchData()
	},
	methods: {
		async fetchData() {
			this.loading = true
			this.error = ''
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/mdm/dashboard'))
				this.worstEntities = data.worstEntities || []
				this.queueHealth = { ...this.queueHealth, ...(data.queueHealth || {}) }
				this.deadLetters = data.deadLetters || []
			} catch (e) {
				this.error = t('pipelinq', 'The data quality dashboard could not be loaded.')
			} finally {
				this.loading = false
			}
		},
		badgeClass(score) {
			if (score > 0.8) return 'mdm-dq-section__badge--good'
			if (score >= 0.6) return 'mdm-dq-section__badge--fair'
			return 'mdm-dq-section__badge--poor'
		},
		async retry(item) {
			try {
				await axios.post(generateUrl('/apps/pipelinq/api/mdm/sync-queue/{id}/retry', { id: item.id }))
				showSuccess(t('pipelinq', 'Sync item re-queued'))
				this.fetchData()
			} catch (e) {
				showError(t('pipelinq', 'Could not re-queue the sync item'))
			}
		},
	},
}
</script>

<style scoped lang="scss">
.mdm-dq-section {
	&__cards {
		display: flex;
		flex-wrap: wrap;
		gap: 16px;
		margin-bottom: 24px;
	}

	&__card {
		flex: 1 1 160px;
		padding: 16px;
		border-radius: var(--border-radius-large);
		background: var(--color-background-hover);
		text-align: center;

		&--poor { background: var(--color-error, #c0392b); color: #fff; }
	}

	&__value {
		font-size: 28px;
		font-weight: 700;
	}

	&__cardlabel {
		font-size: 13px;
		opacity: 0.9;
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

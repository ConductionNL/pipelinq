<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.
-->
<template>
	<div class="mdm-dq">
		<div class="mdm-dq__header">
			<h2>{{ t('pipelinq', 'Data quality dashboard') }}</h2>
			<NcButton type="secondary" :disabled="loading" @click="fetchData">
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent v-else-if="error"
			:name="t('pipelinq', 'Could not load the dashboard')"
			:description="error" />

		<template v-else>
			<div class="mdm-dq__cards">
				<div class="mdm-dq__card">
					<div class="mdm-dq__value">
						{{ averageScore.toFixed(2) }}
					</div>
					<div class="mdm-dq__label">
						{{ t('pipelinq', 'Average quality score') }}
					</div>
				</div>
				<div class="mdm-dq__card mdm-dq__card--good">
					<div class="mdm-dq__value">
						{{ buckets.good }}
					</div>
					<div class="mdm-dq__label">
						{{ t('pipelinq', 'Good (> 0.8)') }}
					</div>
				</div>
				<div class="mdm-dq__card mdm-dq__card--fair">
					<div class="mdm-dq__value">
						{{ buckets.fair }}
					</div>
					<div class="mdm-dq__label">
						{{ t('pipelinq', 'Fair (0.6 – 0.8)') }}
					</div>
				</div>
				<div class="mdm-dq__card mdm-dq__card--poor">
					<div class="mdm-dq__value">
						{{ buckets.poor }}
					</div>
					<div class="mdm-dq__label">
						<!-- sanitize:false — DOMPurify (t()'s default) encodes the
						     literal "<" to "&lt;" since it reads as a malformed tag;
						     this is a trusted static label with no markup/vars. -->
						{{ t('pipelinq', 'Poor (< 0.6)', undefined, undefined, { sanitize: false }) }}
					</div>
				</div>
			</div>

			<h3>{{ t('pipelinq', 'Lowest quality master entities') }}</h3>
			<table v-if="worstEntities.length" class="mdm-dq__table">
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
							<span class="mdm-dq__badge" :class="badgeClass(entity.dataQualityScore)">
								{{ entity.dataQualityScore.toFixed(2) }}
							</span>
						</td>
					</tr>
				</tbody>
			</table>
			<NcEmptyContent v-else
				:name="t('pipelinq', 'No master entities yet')" />

			<h3>{{ t('pipelinq', 'Sync queue health') }}</h3>
			<div class="mdm-dq__cards">
				<div class="mdm-dq__card">
					<div class="mdm-dq__value">
						{{ queueHealth.queued }}
					</div>
					<div class="mdm-dq__label">
						{{ t('pipelinq', 'Queued') }}
					</div>
				</div>
				<div class="mdm-dq__card">
					<div class="mdm-dq__value">
						{{ queueHealth.acknowledged }}
					</div>
					<div class="mdm-dq__label">
						{{ t('pipelinq', 'Acknowledged') }}
					</div>
				</div>
				<div class="mdm-dq__card mdm-dq__card--poor">
					<div class="mdm-dq__value">
						{{ queueHealth['dead-letter'] }}
					</div>
					<div class="mdm-dq__label">
						{{ t('pipelinq', 'Dead-letter') }}
					</div>
				</div>
			</div>

			<template v-if="deadLetters.length">
				<h3>{{ t('pipelinq', 'Dead-letter items') }}</h3>
				<table class="mdm-dq__table">
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
	name: 'MdmDataQualityDashboard',
	components: { NcButton, NcEmptyContent, NcLoadingIcon },
	data() {
		return {
			loading: true,
			error: '',
			averageScore: 0,
			buckets: { good: 0, fair: 0, poor: 0 },
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
				this.averageScore = Number(data.averageScore || 0)
				this.buckets = data.buckets || { good: 0, fair: 0, poor: 0 }
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
			if (score > 0.8) return 'mdm-dq__badge--good'
			if (score >= 0.6) return 'mdm-dq__badge--fair'
			return 'mdm-dq__badge--poor'
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
.mdm-dq {
	padding: 20px;

	&__header {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin-bottom: 16px;
	}

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

		&--good { background: var(--color-success, #2d7d46); color: #fff; }
		&--fair { background: var(--color-warning, #c28a00); color: #fff; }
		&--poor { background: var(--color-error, #c0392b); color: #fff; }
	}

	&__value {
		font-size: 28px;
		font-weight: 700;
	}

	&__label {
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

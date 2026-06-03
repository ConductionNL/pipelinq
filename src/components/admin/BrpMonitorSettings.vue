<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'BRP Monitor')"
		:description="t('pipelinq', 'Service health of the BRP integration over the last 24 hours, aggregated from the immutable audit trail. No BSN is shown.')">
		<NcLoadingIcon v-if="loading" />
		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-else class="brp-monitor">
			<div class="brp-monitor__tiles">
				<div class="brp-monitor__tile">
					<span class="brp-monitor__value">{{ report.lookups }}</span>
					<span class="brp-monitor__label">{{ t('pipelinq', 'Lookups') }}</span>
				</div>
				<div class="brp-monitor__tile">
					<span class="brp-monitor__value">{{ report.cacheHitRatio }}%</span>
					<span class="brp-monitor__label">{{ t('pipelinq', 'Cache hits') }}</span>
				</div>
				<div class="brp-monitor__tile" :class="{ 'brp-monitor__tile--alert': report.errorRate > 10 }">
					<span class="brp-monitor__value">{{ report.errorRate }}%</span>
					<span class="brp-monitor__label">{{ t('pipelinq', 'Errors') }}</span>
				</div>
				<div class="brp-monitor__tile">
					<span class="brp-monitor__value">{{ report.avgResponseMs }} ms</span>
					<span class="brp-monitor__label">{{ t('pipelinq', 'Avg response time') }}</span>
				</div>
				<div class="brp-monitor__tile">
					<span class="brp-monitor__value">{{ report.refusals }}</span>
					<span class="brp-monitor__label">{{ t('pipelinq', 'Refusals') }}</span>
				</div>
			</div>

			<div class="brp-monitor__cert" :class="certClass">
				<strong>{{ t('pipelinq', 'mTLS certificate') }}:</strong>
				<template v-if="cert.configured && cert.parsable">
					{{ t('pipelinq', 'Expires {date} ({days} days)', { date: cert.validTo, days: cert.daysRemaining }) }}
				</template>
				<template v-else-if="cert.configured">
					{{ t('pipelinq', 'Configured, but the certificate could not be parsed') }}
				</template>
				<template v-else>
					{{ t('pipelinq', 'Not configured') }}
				</template>
			</div>

			<NcButton @click="showDetail = !showDetail">
				{{ showDetail ? t('pipelinq', 'Hide detailed report') : t('pipelinq', 'View detailed report') }}
			</NcButton>

			<div v-if="showDetail" class="brp-monitor__detail">
				<table>
					<tbody>
						<tr>
							<td>{{ t('pipelinq', 'Reporting window since') }}</td>
							<td>{{ report.since }}</td>
						</tr>
						<tr>
							<td>{{ t('pipelinq', 'Total lookups (24h)') }}</td>
							<td>{{ report.lookups }}</td>
						</tr>
						<tr>
							<td>{{ t('pipelinq', 'Cache hit ratio') }}</td>
							<td>{{ report.cacheHitRatio }}%</td>
						</tr>
						<tr>
							<td>{{ t('pipelinq', 'Error rate') }}</td>
							<td>{{ report.errorRate }}%</td>
						</tr>
						<tr>
							<td>{{ t('pipelinq', 'Average response time') }}</td>
							<td>{{ report.avgResponseMs }} ms</td>
						</tr>
						<tr>
							<td>{{ t('pipelinq', 'Authorization refusals') }}</td>
							<td>{{ report.refusals }}</td>
						</tr>
					</tbody>
				</table>
				<NcNoteCard type="info">
					{{ t('pipelinq', 'Per-hour charts and top-errors breakdown become available once the BRP integration is live and the audit trail accumulates traffic.') }}
				</NcNoteCard>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcLoadingIcon, NcNoteCard, NcButton } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'BrpMonitorSettings',
	components: {
		NcSettingsSection,
		NcLoadingIcon,
		NcNoteCard,
		NcButton,
	},
	data() {
		return {
			loading: true,
			error: '',
			showDetail: false,
			report: {
				lookups: 0,
				cacheHitRatio: 0,
				errorRate: 0,
				avgResponseMs: 0,
				refusals: 0,
				since: '',
			},
			cert: { configured: false },
		}
	},
	computed: {
		/**
		 * CSS class for the certificate badge by expiry band.
		 *
		 * @return {object} The class binding.
		 */
		certClass() {
			return {
				'brp-monitor__cert--warning': this.cert.status === 'warning',
				'brp-monitor__cert--critical': this.cert.status === 'critical',
			}
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Fetch the aggregated BRP report from the admin endpoint.
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/brp/report'), {
					headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.error = data.error || t('pipelinq', 'Could not load the BRP report')
					return
				}
				this.report = { ...this.report, ...data }
				this.cert = data.certificate || { configured: false }
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Could not load the BRP report')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.brp-monitor {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.brp-monitor__tiles {
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
}

.brp-monitor__tile {
	display: flex;
	flex-direction: column;
	min-width: 120px;
	padding: 14px 18px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.brp-monitor__tile--alert {
	border-color: var(--color-error);
}

.brp-monitor__value {
	font-size: 22px;
	font-weight: 600;
}

.brp-monitor__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.brp-monitor__cert {
	padding: 10px 14px;
	border-radius: var(--border-radius);
	background: var(--color-background-dark);
}

.brp-monitor__cert--warning {
	background: var(--color-warning);
	color: var(--color-main-background);
}

.brp-monitor__cert--critical {
	background: var(--color-error);
	color: var(--color-main-background);
}

.brp-monitor__detail table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.brp-monitor__detail td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>

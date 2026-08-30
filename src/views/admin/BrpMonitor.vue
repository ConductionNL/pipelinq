<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - BrpMonitor — admin view rendering the BRP monitor tile (lookups, cache-hits, errors,
  - avg response time) and the mTLS client-certificate expiry countdown.
  - REQ-BSN-010 / REQ-BSN-003-02.
  -
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#6.1
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#6.2
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-010
  -->
<template>
	<div class="brp-monitor" data-testid="brp-monitor">
		<h2>{{ t('pipelinq', 'BRP Monitor') }}</h2>

		<div v-if="loading" class="brp-monitor__loading">
			<NcLoadingIcon />
		</div>
		<div v-else-if="loadError" class="brp-monitor__error">
			{{ loadError }}
		</div>
		<div v-else class="brp-monitor__grid">
			<section class="brp-monitor__card">
				<h3>{{ t('pipelinq', 'Last 24 hours') }}</h3>
				<dl>
					<dt>{{ t('pipelinq', 'Lookups') }}</dt>
					<dd>{{ report?.totalLookups ?? 0 }}</dd>
					<dt>{{ t('pipelinq', 'Cache hits') }}</dt>
					<dd>{{ report?.cacheHits ?? 0 }} ({{ cacheHitPct }}%)</dd>
					<dt>{{ t('pipelinq', 'Errors') }}</dt>
					<dd>{{ report?.errorCount ?? 0 }} ({{ errorPct }}%)</dd>
					<dt>{{ t('pipelinq', 'Avg. response time') }}</dt>
					<dd>{{ report?.avgResponseMs ?? 0 }} ms</dd>
				</dl>
			</section>

			<section class="brp-monitor__card">
				<h3>{{ t('pipelinq', 'mTLS Certificate') }}</h3>
				<div v-if="!cert" class="brp-monitor__cert">
					<span
						class="brp-monitor__badge brp-monitor__badge--unconfigured">
						{{ t('pipelinq', 'Not configured') }}
					</span>
				</div>
				<div v-else class="brp-monitor__cert">
					<div>
						<span
							class="brp-monitor__badge"
							:class="['brp-monitor__badge--' + cert.status]">
							{{ certStatusLabel }}
						</span>
					</div>
					<div v-if="cert.expiry">
						<strong>{{ t('pipelinq', 'Expires on') }}:</strong>
						{{ cert.expiry }}
					</div>
					<div v-if="cert.daysLeft !== undefined">
						<strong>{{ t('pipelinq', 'Days remaining') }}:</strong>
						{{ cert.daysLeft }}
					</div>
				</div>
			</section>

			<section v-if="report" class="brp-monitor__card brp-monitor__card--wide">
				<h3>{{ t('pipelinq', 'Reporting period') }}</h3>
				<dl>
					<dt>{{ t('pipelinq', 'From') }}</dt>
					<dd>{{ report.windowStart }}</dd>
					<dt>{{ t('pipelinq', 'To') }}</dt>
					<dd>{{ report.windowEnd }}</dd>
					<dt>{{ t('pipelinq', 'Generated on') }}</dt>
					<dd>{{ report.generatedAt }}</dd>
				</dl>
			</section>
		</div>

		<div class="brp-monitor__actions">
			<NcButton variant="secondary" :disabled="loading" @click="load">
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'BrpMonitor',
	components: {
		NcButton,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: false,
			loadError: '',
			report: null,
			cert: null,
		}
	},

	computed: {
		cacheHitPct() {
			if (!this.report || !this.report.cacheHitRatio) return 0
			return Math.round(this.report.cacheHitRatio * 100)
		},

		errorPct() {
			if (!this.report || !this.report.errorRate) return 0
			return Math.round(this.report.errorRate * 100)
		},

		/**
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#6.1
		 */
		certStatusLabel() {
			if (!this.cert) return ''
			if (this.cert.status === 'ok') return this.t('pipelinq', 'OK')
			if (this.cert.status === 'warning')
				return this.t('pipelinq', 'Expires soon')
			if (this.cert.status === 'critical')
				return this.t('pipelinq', 'Critical — replace now')
			return this.cert.status
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#6.1
		 */
		async load() {
			this.loading = true
			this.loadError = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/brp/monitor')
				const response = await axios.get(url)
				this.report = response.data?.report || null
				this.cert = response.data?.cert || null
			} catch (err) {
				const data = err?.response?.data || {}
				this.loadError =
					data.error || this.t('pipelinq', 'Could not load BRP Monitor.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.brp-monitor {
	padding: 16px;
}

.brp-monitor__grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
	gap: 16px;
	margin-top: 16px;
}

.brp-monitor__card {
	padding: 16px;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e8e8e8);
	border-radius: var(--border-radius-large, 12px);
}

.brp-monitor__card--wide {
	grid-column: span 2;
}

.brp-monitor__card dl {
	display: grid;
	grid-template-columns: auto 1fr;
	gap: 4px 12px;
}

.brp-monitor__card dt {
	color: var(--color-text-maxcontrast, #767676);
}

.brp-monitor__badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 0.85em;
}

.brp-monitor__badge--ok {
	background: var(--color-success, #46ba61);
	color: #fff;
}

.brp-monitor__badge--warning {
	background: var(--color-warning, #e9b94d);
	color: #000;
}

.brp-monitor__badge--critical {
	background: var(--color-error, #c2392a);
	color: #fff;
}

.brp-monitor__badge--unconfigured {
	background: var(--color-background-dark, #f0f0f0);
	color: var(--color-text-maxcontrast, #767676);
}

.brp-monitor__actions {
	margin-top: 16px;
}

.brp-monitor__loading,
.brp-monitor__error {
	padding: 24px;
	text-align: center;
}

.brp-monitor__error {
	color: var(--color-error, #c2392a);
}
</style>

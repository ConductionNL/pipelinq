<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="t('pipelinq', 'Export run')"
		:loading="loading"
		@back="goBack">
		<template #header>
			<CnStatusBadge :status="badgeStatus" :label="statusLabel" />
			<NcButton
				v-if="canRetry"
				variant="primary"
				:disabled="busy"
				@click="retry">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Summary')">
			<dl class="run-summary">
				<dt>{{ t('pipelinq', 'Status') }}</dt>
				<dd>{{ statusLabel }}</dd>
				<dt>{{ t('pipelinq', 'Mode') }}</dt>
				<dd>{{ run.modeUsed || '—' }}</dd>
				<dt>{{ t('pipelinq', 'Rows') }}</dt>
				<dd>{{ run.rowCount || 0 }}</dd>
				<dt>{{ t('pipelinq', 'Bytes') }}</dt>
				<dd>{{ run.byteCount || 0 }}</dd>
				<dt>{{ t('pipelinq', 'Files') }}</dt>
				<dd>{{ run.fileCount || 0 }}</dd>
				<dt>{{ t('pipelinq', 'Started') }}</dt>
				<dd>{{ run.startedAt || '—' }}</dd>
				<dt>{{ t('pipelinq', 'Ended') }}</dt>
				<dd>{{ run.endedAt || '—' }}</dd>
			</dl>
			<p v-if="run.errorMessage" class="run-error">
				{{ run.errorMessage }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'File manifest')">
			<table v-if="manifest.length" class="run-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Path') }}</th>
						<th>{{ t('pipelinq', 'Size') }}</th>
						<th>{{ t('pipelinq', 'Rows') }}</th>
						<th>{{ t('pipelinq', 'SHA-256') }}</th>
						<th>{{ t('pipelinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(file, index) in manifest" :key="index">
						<td>{{ file.path }}</td>
						<td>{{ file.size_bytes }}</td>
						<td>{{ file.rows_in_file }}</td>
						<td class="run-hash">
							{{ file.sha256 }}
						</td>
						<td>{{ file.upload_status }}</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="run-empty">
				{{ t('pipelinq', 'No files in this run') }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Schema snapshots')">
			<div v-for="(snap, index) in snapshots" :key="index" class="run-snapshot">
				<strong>{{ snap.pipelinqSchemaName }}</strong>
				<ul v-if="snap.comparedToPrevious && snap.comparedToPrevious.length" class="run-drift">
					<li v-for="(change, ci) in snap.comparedToPrevious" :key="ci">
						{{ change }}
					</li>
				</ul>
				<span v-else class="run-nodrift">{{ t('pipelinq', 'No schema changes detected') }}</span>
			</div>
			<p v-if="!snapshots.length" class="run-empty">
				{{ t('pipelinq', 'No schema snapshots') }}
			</p>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { exportApi } from '../../services/exportApi.js'

const STATUS_LABELS = {
	pending: 'Queued',
	running: 'Running',
	succeeded: 'Succeeded',
	partial: 'Partial',
	failed: 'Failed',
	skipped_overlap: 'Skipped (overlap)',
}

const BADGE_STATUS = {
	pending: 'info',
	running: 'info',
	succeeded: 'success',
	partial: 'warning',
	failed: 'error',
	skipped_overlap: 'warning',
}

export default {
	name: 'ExportRunDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
	},
	props: {
		exportRunId: {
			type: String,
			default: null,
		},
		id: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			run: {},
			snapshots: [],
			loading: false,
			busy: false,
		}
	},
	computed: {
		/**
		 * The resolved run id from either prop name.
		 *
		 * @return {string|null} The run UUID.
		 */
		runId() {
			return this.exportRunId || this.id || this.$route?.params?.id || null
		},
		/**
		 * The file manifest (defensive default).
		 *
		 * @return {Array<object>} The manifest entries.
		 */
		manifest() {
			return Array.isArray(this.run.fileManifestJson) ? this.run.fileManifestJson : []
		},
		/**
		 * The human-readable status label.
		 *
		 * @return {string} The label.
		 */
		statusLabel() {
			return this.t('pipelinq', STATUS_LABELS[this.run.status] || this.run.status || 'Unknown')
		},
		/**
		 * The status badge severity.
		 *
		 * @return {string} The badge status.
		 */
		badgeStatus() {
			return BADGE_STATUS[this.run.status] || 'info'
		},
		/**
		 * Whether the run can be retried.
		 *
		 * @return {boolean} True for failed / partial / skipped runs.
		 */
		canRetry() {
			return ['failed', 'partial', 'skipped_overlap'].includes(this.run.status)
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		/**
		 * Load the run + its snapshots.
		 */
		async load() {
			if (!this.runId) {
				return
			}
			this.loading = true
			try {
				const data = await exportApi.getRun(this.runId)
				this.run = data.run || {}
				this.snapshots = data.snapshots || []
			} catch (e) {
				showError(e.message || this.t('pipelinq', 'Could not load the run'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Retry this run.
		 */
		async retry() {
			this.busy = true
			try {
				await exportApi.retryRun(this.runId)
				showSuccess(this.t('pipelinq', 'A new run has been queued'))
				this.$router.push({ name: 'ExportRuns' })
			} catch (e) {
				showError(e.message || this.t('pipelinq', 'Could not retry the run'))
			} finally {
				this.busy = false
			}
		},
		/**
		 * Navigate back to the run list.
		 */
		goBack() {
			this.$router.push({ name: 'ExportRuns' })
		},
	},
}
</script>

<style scoped>
.run-summary {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 4px 16px;
}

.run-summary dt {
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.run-error {
	margin-top: 12px;
	color: var(--color-error);
}

.run-table {
	width: 100%;
	border-collapse: collapse;
}

.run-table th,
.run-table td {
	text-align: left;
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.run-hash {
	font-family: monospace;
	font-size: 0.85em;
	word-break: break-all;
}

.run-snapshot {
	margin-bottom: 12px;
}

.run-drift {
	margin: 4px 0 0 16px;
}

.run-nodrift {
	color: var(--color-text-maxcontrast);
}

.run-empty {
	color: var(--color-text-maxcontrast);
}
</style>

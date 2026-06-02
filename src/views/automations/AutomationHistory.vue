<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<div class="automation-history">
		<NcLoadingIcon v-if="loading" />

		<NcEmptyContent
			v-else-if="logs.length === 0"
			:name="t('pipelinq', 'No executions yet')"
			:description="t('pipelinq', 'This automation has not run yet.')" />

		<table v-else class="history-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Triggered at') }}</th>
					<th>{{ t('pipelinq', 'Trigger entity') }}</th>
					<th>{{ t('pipelinq', 'Status') }}</th>
					<th>{{ t('pipelinq', 'Actions executed') }}</th>
					<th>{{ t('pipelinq', 'Error') }}</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="(log, index) in logs" :key="log.id || index">
					<td>{{ formatDate(log.triggeredAt) }}</td>
					<td class="mono">
						{{ log.triggerEntity || '-' }}
					</td>
					<td>
						<CnStatusBadge
							:status="log.status === 'success' ? 'active' : 'error'"
							:label="statusLabel(log.status)" />
					</td>
					<td>{{ actionsSummary(log.actionsExecuted) }}</td>
					<td class="error-cell">
						{{ log.error || '' }}
					</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'

export default {
	name: 'AutomationHistory',
	components: {
		CnStatusBadge,
		NcLoadingIcon,
		NcEmptyContent,
	},
	props: {
		automationId: {
			type: String,
			default: '',
		},
		logs: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
	},
	methods: {
		/**
		 * Localized status label for a log entry.
		 *
		 * @param {string} status The log status.
		 * @return {string} The label.
		 */
		statusLabel(status) {
			return status === 'success' ? this.t('pipelinq', 'Success') : this.t('pipelinq', 'Failure')
		},
		/**
		 * Summarize the executed actions for a log row.
		 *
		 * @param {Array} actions The actionsExecuted array.
		 * @return {string} A short summary.
		 */
		actionsSummary(actions) {
			if (!Array.isArray(actions) || actions.length === 0) {
				return '-'
			}
			return actions.map((a) => a.type).join(', ')
		},
		/**
		 * Format an ISO date for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The display date.
		 */
		formatDate(value) {
			if (!value) {
				return '-'
			}
			return new Date(value).toLocaleString()
		},
	},
}
</script>

<style scoped>
.history-table {
	width: 100%;
	border-collapse: collapse;
}
.history-table th,
.history-table td {
	text-align: left;
	padding: 6px 10px;
	border-bottom: 1px solid var(--color-border);
	vertical-align: top;
}
.history-table th {
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}
.mono {
	font-family: monospace;
	font-size: 0.9em;
}
.error-cell {
	color: var(--color-error);
}
</style>

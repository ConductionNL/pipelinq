<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="automation-history">
		<CnEmptyState v-if="rows.length === 0"
			:title="t('pipelinq', 'No executions yet')"
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
				<tr v-for="(row, idx) in rows" :key="row.id || idx">
					<td>{{ row.triggeredAt || '-' }}</td>
					<td>{{ row.triggerEntity || '-' }}</td>
					<td>
						<CnStatusBadge :status="row.status" :label="statusLabel(row.status)" />
					</td>
					<td>{{ actionsSummary(row.actionsExecuted) }}</td>
					<td>{{ row.error || '' }}</td>
				</tr>
			</tbody>
		</table>
	</div>
</template>

<script>
import { CnEmptyState, CnStatusBadge } from '@conduction/nextcloud-vue'

export default {
	name: 'AutomationHistory',
	components: {
		CnEmptyState,
		CnStatusBadge,
	},
	props: {
		automationId: {
			type: String,
			required: true,
		},
		logs: {
			type: Array,
			default: () => [],
		},
	},
	computed: {
		/**
		 * The logs prop, normalised to an array.
		 *
		 * @return {Array<object>}
		 */
		rows() {
			return Array.isArray(this.logs) ? this.logs : []
		},
	},
	methods: {
		/**
		 * Translate a status code to a user-visible label.
		 *
		 * @param {string} status The raw status code.
		 * @return {string} Localised label.
		 */
		statusLabel(status) {
			switch (status) {
			case 'success':
				return this.t('pipelinq', 'Success')
			case 'failure':
				return this.t('pipelinq', 'Failure')
			case 'queued':
				return this.t('pipelinq', 'Queued')
			default:
				return status || ''
			}
		},
		/**
		 * Build a short comma-separated summary of executed action types.
		 *
		 * @param {Array<object>} actions Array of action result records.
		 * @return {string} Summary or '-' when empty.
		 */
		actionsSummary(actions) {
			if (!Array.isArray(actions) || actions.length === 0) {
				return '-'
			}
			return actions.map((a) => a.type || '').filter(Boolean).join(', ')
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
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}
</style>

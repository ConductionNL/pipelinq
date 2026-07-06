<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable :rows="rows"
		:columns="columns"
		:loading="!loaded"
		:loading-text="t('pipelinq', 'Loading…')"
		hide-header
		borderless
		:empty-text="t('pipelinq', 'No deals closed yet.')"
		@row-click="open">
		<template #column-status="{ row }">
			<span
				class="deal-badge"
				:class="row.status === 'won' ? 'deal-badge--won' : 'deal-badge--lost'">
				{{ row.status === 'won' ? t('pipelinq', 'Won') : t('pipelinq', 'Lost') }}
			</span>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { getLeads, getClients } from '../../../services/dashboardData.js'
import { formatEur } from '../../../services/commercialFormat.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

const MAX_ROWS = 6

/**
 * Table: deals recently won or lost, ordered by close recency, from the
 * client-side cached lead dataset. Rendered with the universal
 * CnDataTable list pattern (ADR-049).
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'RecentlyWonLostWidget',
	components: {
		CnDataTable,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			rows: [],
			columns: [
				{ key: 'title', cellClass: 'cn-cell--strong' },
				{ key: 'client', cellClass: 'cn-cell--muted' },
				{ key: 'status' },
				{ key: 'value', cellClass: 'cn-cell--strong cn-cell--end', format: (v) => formatEur(v) },
			],
		}
	},
	methods: {
		formatEur,
		/**
		 * Load closed leads + clients and build the recently-closed rows.
		 *
		 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
		 */
		async load() {
			try {
				const [leads, clients] = await Promise.all([getLeads(), getClients()])
				const names = new Map((clients || []).map(c => [String(c.id), c.name || c.title || '']))
				this.rows = (leads || [])
					.filter(lead => lead.status === 'won' || lead.status === 'lost')
					.sort((a, b) => String(this.closeKey(b)).localeCompare(String(this.closeKey(a))))
					.slice(0, MAX_ROWS)
					.map(lead => ({
						id: lead.id,
						title: lead.title || this.t('pipelinq', 'Untitled deal'),
						client: names.get(String(lead.client)) || '',
						status: lead.status,
						value: Number(lead.value) || 0,
					}))
			} catch (err) {
				console.error('RecentlyWonLostWidget fetch error:', err)
			} finally {
				this.loaded = true
			}
		},
		/**
		 * Sort key for close recency.
		 *
		 * @param {object} lead - Lead record.
		 * @return {string} The close timestamp proxy.
		 */
		closeKey(lead) {
			return lead.stageEnteredAt || lead.expectedCloseDate || ''
		},
		/**
		 * Navigate to the lead detail page.
		 *
		 * @param {object} row - The row.
		 */
		open(row) {
			if (row.id) {
				this.$router.push({ name: 'LeadDetail', params: { id: String(row.id) } })
			}
		},
	},
}
</script>

<style scoped>
.deal-badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 100px);
	white-space: nowrap;
}

.deal-badge--won {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-element-text, #fff);
}

.deal-badge--lost {
	background: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast, #767676);
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable :rows="rows"
		:columns="columns"
		:loading="!loaded"
		:loading-text="t('pipelinq', 'Loading…')"
		hide-header
		borderless
		:empty-text="t('pipelinq', 'No open deals with a close date.')"
		@row-click="open" />
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { getLeads, getClients } from '../../../services/dashboardData.js'
import { formatEur } from '../../../services/commercialFormat.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

const MAX_ROWS = 6

/**
 * Table: open deals ordered by expected close date ascending (closing
 * soonest first), from the client-side cached lead dataset. Rendered
 * with the universal CnDataTable list pattern (ADR-049).
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'ClosingSoonWidget',
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
				{ key: 'dueDate', cellClass: 'cn-cell--muted', format: (v) => this.formatDate(v) },
				{ key: 'value', cellClass: 'cn-cell--strong cn-cell--end', format: (v) => formatEur(v) },
			],
		}
	},
	methods: {
		formatEur,
		/**
		 * Load open leads + clients and build the closing-soon rows.
		 *
		 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
		 */
		async load() {
			try {
				const [leads, clients] = await Promise.all([getLeads(), getClients()])
				const names = new Map((clients || []).map(c => [String(c.id), c.name || c.title || '']))
				this.rows = (leads || [])
					.filter(lead => lead.status === 'open' && lead.expectedCloseDate)
					.sort((a, b) => String(a.expectedCloseDate).localeCompare(String(b.expectedCloseDate)))
					.slice(0, MAX_ROWS)
					.map(lead => ({
						id: lead.id,
						title: lead.title || this.t('pipelinq', 'Untitled deal'),
						client: names.get(String(lead.client)) || '',
						dueDate: lead.expectedCloseDate,
						value: Number(lead.value) || 0,
					}))
			} catch (err) {
				console.error('ClosingSoonWidget fetch error:', err)
			} finally {
				this.loaded = true
			}
		},
		/**
		 * Format a date-ish string as a short local date.
		 *
		 * @param {string} value - Date string.
		 * @return {string} Localised date.
		 */
		formatDate(value) {
			const key = typeof value === 'string' ? value.slice(0, 10) : ''
			if (!key) return '—'
			const [y, m, d] = key.split('-').map(Number)
			return new Date(y, m - 1, d).toLocaleDateString()
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

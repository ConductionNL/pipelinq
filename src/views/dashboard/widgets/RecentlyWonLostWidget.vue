<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<div class="deal-table-widget">
		<div v-if="!loaded" class="deal-table-widget__empty">
			{{ t('pipelinq', 'Loading…') }}
		</div>
		<div v-else-if="rows.length === 0" class="deal-table-widget__empty">
			{{ t('pipelinq', 'No deals closed yet.') }}
		</div>
		<div v-else class="deal-table-widget__list">
			<div
				v-for="row in rows"
				:key="row.id"
				class="deal-table-widget__row"
				role="link"
				tabindex="0"
				@click="open(row)"
				@keyup.enter="open(row)">
				<span class="deal-table-widget__title">{{ row.title }}</span>
				<span class="deal-table-widget__sub">{{ row.client }}</span>
				<span
					class="deal-table-widget__badge"
					:class="row.status === 'won' ? 'deal-table-widget__badge--won' : 'deal-table-widget__badge--lost'">
					{{ row.status === 'won' ? t('pipelinq', 'Won') : t('pipelinq', 'Lost') }}
				</span>
				<span class="deal-table-widget__amount">{{ formatEur(row.value) }}</span>
			</div>
		</div>
	</div>
</template>

<script>
import { getLeads, getClients } from '../../../services/dashboardData.js'
import { formatEur } from '../../../services/commercialFormat.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

const MAX_ROWS = 6

/**
 * Table: deals recently won or lost, ordered by close recency, from the
 * client-side cached lead dataset.
 *
 * @spec openspec/changes/commercial-dashboard/specs/commercial-dashboard/spec.md
 */
export default {
	name: 'RecentlyWonLostWidget',
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			rows: [],
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
.deal-table-widget {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.deal-table-widget__empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.deal-table-widget__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.deal-table-widget__row {
	display: grid;
	grid-template-columns: 1.6fr 1.2fr auto auto;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	cursor: pointer;
}

.deal-table-widget__row:hover,
.deal-table-widget__row:focus {
	background: var(--color-background-hover);
}

.deal-table-widget__title {
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.deal-table-widget__sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.deal-table-widget__badge {
	font-size: 11px;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill, 100px);
	white-space: nowrap;
}

.deal-table-widget__badge--won {
	background: var(--color-success, #46ba61);
	color: var(--color-primary-element-text, #fff);
}

.deal-table-widget__badge--lost {
	background: var(--color-background-dark, #ededed);
	color: var(--color-text-maxcontrast, #767676);
}

.deal-table-widget__amount {
	font-size: 13px;
	font-weight: 600;
	text-align: end;
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}
</style>

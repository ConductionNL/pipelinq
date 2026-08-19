<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  SLA attainment BREAKDOWN in-body section, hosted on the declarative
  type:dashboard "SLA attainment" page via a kind:'section' bodyWidget
  (pipelinq-dashboards-declarative). The four headline KPIs (overall
  attainment + total/met/breached) render as endpoint-bound stat widgets in
  the dashboard grid, driven by the page's bucket / groupBy pageFilters.
  This section renders the per-group breakdown table (grouped by policy /
  tier / team / target / customer) the stat grid cannot express. It reads the
  bucket + groupBy selections as props (interpolated from @workspace.* by
  CnBodySections) and self-fetches GET /api/sla/attainment, re-querying when
  either selection changes. The server defaults the period to the current
  bucket window when no explicit date is given, so no client-side date math.
-->
<template>
	<section class="sla-breakdown">
		<NcLoadingIcon v-if="loading" :size="28" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else-if="byGroup.length > 0">
			<h3>{{ t('pipelinq', 'Breakdown by {group}', { group: groupLabel }) }}</h3>
			<table class="sla-breakdown__table">
				<thead>
					<tr>
						<th scope="col">{{ groupLabel }}</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Total') }}
						</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Met') }}
						</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Breached') }}
						</th>
						<th scope="col" class="num">
							{{ t('pipelinq', 'Attainment') }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in byGroup" :key="row.groupKey">
						<td>{{ row.groupName }}</td>
						<td class="num">
							{{ row.total }}
						</td>
						<td class="num">
							{{ row.met }}
						</td>
						<td class="num">
							{{ row.breached }}
						</td>
						<td class="num">
							{{ percent(row.attainment) }}
						</td>
					</tr>
				</tbody>
			</table>
		</template>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'SlaAttainmentBreakdownSection',
	components: { NcLoadingIcon, NcNoteCard },
	inject: {
		cnSectionContext: { default: null },
	},
	props: {
		/** Time bucket (day / week / month / quarter), from @workspace.bucket. */
		bucket: { type: String, default: 'month' },
		/** Grouping dimension (policy / tier / team / target / customer), from @workspace.groupBy. */
		groupBy: { type: String, default: 'policy' },
	},
	data() {
		return {
			loading: false,
			error: null,
			payload: { details: { byGroup: [] } },
		}
	},
	computed: {
		effectiveBucket() {
			if (this.bucket) return this.bucket
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx.bucket) || 'month'
		},
		effectiveGroupBy() {
			if (this.groupBy) return this.groupBy
			const ctx = this.cnSectionContext && this.cnSectionContext.workspace
			return (ctx && ctx.groupBy) || 'policy'
		},
		groupOptions() {
			return [
				{ value: 'policy', label: this.t('pipelinq', 'Policy') },
				{ value: 'tier', label: this.t('pipelinq', 'Customer tier') },
				{ value: 'team', label: this.t('pipelinq', 'Team') },
				{ value: 'target', label: this.t('pipelinq', 'Target kind') },
				{ value: 'customer', label: this.t('pipelinq', 'Customer') },
			]
		},
		groupLabel() {
			const match = this.groupOptions.find(o => o.value === this.effectiveGroupBy)
			return match ? match.label : this.t('pipelinq', 'Group')
		},
		byGroup() {
			return (this.payload.details && this.payload.details.byGroup) || []
		},
	},
	watch: {
		effectiveBucket() {
			this.fetchAttainment()
		},
		effectiveGroupBy() {
			this.fetchAttainment()
		},
	},
	mounted() {
		this.fetchAttainment()
	},
	methods: {
		async fetchAttainment() {
			this.loading = true
			this.error = null
			try {
				const url = generateUrl('/apps/pipelinq/api/sla/attainment')
				const params = { bucket: this.effectiveBucket, groupBy: this.effectiveGroupBy }
				const response = await axios.get(url, { params })
				this.payload = response.data || this.payload
			} catch (e) {
				this.error = this.t('pipelinq', 'Failed to load SLA attainment. Please try again.')
			} finally {
				this.loading = false
			}
		},
		percent(value) {
			const n = Number(value) || 0
			return `${(n * 100).toFixed(1)}%`
		},
	},
}
</script>

<style lang="scss" scoped>
.sla-breakdown {
	h3 {
		margin: 0 0 12px;
		font-weight: 600;
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			padding: 8px 12px;
			border-bottom: 1px solid var(--color-border);
			text-align: left;

			&.num {
				text-align: right;
			}
		}

		th {
			font-weight: 600;
			background: var(--color-background-hover);
		}
	}
}
</style>

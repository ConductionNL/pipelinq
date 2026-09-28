<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Posts ranked by engagement rate per network.
  -
  - THE PAGE RENDERS BEFORE THE NUMBERS ARRIVE. pipelinq#1781 fixed a
  - performance page that awaited a per-object fan-out before it painted
  - anything, and this one does not repeat it: the heading and the table
  - shell are in the template unconditionally, one request fills the rows, and
  - nothing here walks a publication to fetch its account. The follower count
  - each rate divides by was copied onto the publication's ranking row by the
  - daily pull, server-side, so the page never asks a second question.
  -
  - The rate rather than the raw count is the point. A company page with 900
  - followers and a spokesperson with 4,000 are not comparable on likes, and
  - an account with no followers recorded shows no rate rather than a zero
  - that would read as "nobody engaged".
  -
  - @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
  -->
<template>
	<div class="social-performance" data-testid="social-performance">
		<h2>{{ t('pipelinq', 'Social performance') }}</h2>

		<NcNoteCard v-if="error" type="error">{{ error }}</NcNoteCard>

		<!-- CnDataTable renders its headers before the rows arrive, so the page
			still paints before the one request that fills it. It only reports
			header clicks; the sorting itself is done here. -->
		<CnDataTable
			:columns="columns"
			:rows="sortedRows"
			:loading="loading"
			:sortKey="sortKey"
			:sortOrder="sortOrder"
			:emptyText="t('pipelinq', 'Nothing has been published yet.')"
			rowKey="id"
			@sort="onSort">
			<template #column-network="{ row }">
				<span
					class="social-performance__network"
					data-testid="social-performance-row">
					<span class="social-performance__icon" aria-hidden="true">
						<component :is="networkIcon(row.network)" :size="16" />
					</span>
					{{ networkLabel(row.network) }}
				</span>
			</template>
			<template #column-publishedAt="{ row }">
				{{ formatDate(row.publishedAt) }}
			</template>
			<template #column-engagementRate="{ row }">
				{{ rate(row.source) }}
			</template>
		</CnDataTable>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { NcNoteCard } from '@nextcloud/vue'
import { fetchPerformance } from '../../services/socialApi.js'
import { networkIcon } from '../../services/socialNetworkIcons.js'
import {
	formatEngagementRate,
	networkLimits,
} from '../../services/socialNetworks.js'

export default {
	name: 'SocialPerformanceView',

	components: {
		CnDataTable,
		NcNoteCard,
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			// The server's own ranking order, until a header is clicked.
			sortKey: 'engagementRate',
			sortOrder: 'desc',
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The columns, every one sortable.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		columns() {
			return [
				{ key: 'network', label: t('pipelinq', 'Network'), sortable: true },
				{
					key: 'publishedAt',
					label: t('pipelinq', 'Published'),
					sortable: true,
				},
				{ key: 'views', label: t('pipelinq', 'Views'), sortable: true },
				{ key: 'likes', label: t('pipelinq', 'Likes'), sortable: true },
				{
					key: 'comments',
					label: t('pipelinq', 'Comments'),
					sortable: true,
				},
				{ key: 'shares', label: t('pipelinq', 'Shares'), sortable: true },
				{
					key: 'followerCount',
					label: t('pipelinq', 'Followers'),
					sortable: true,
				},
				{
					key: 'engagementRate',
					label: t('pipelinq', 'Engagement rate'),
					sortable: true,
				},
			]
		},

		/**
		 * The ranking rows flattened to one value per column, so every column
		 * sorts on the number it shows. `source` keeps the row as the server sent it.
		 *
		 * @return {Array<object>} The table rows.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		tableRows() {
			return this.rows.map((row) => ({
				id: row.publicationId,
				network: row.network,
				publishedAt: row.publishedAt || '',
				views: row.metrics?.views || 0,
				likes: row.metrics?.likes || 0,
				comments: row.metrics?.comments || 0,
				shares: row.metrics?.shares || 0,
				followerCount: row.followerCount || 0,
				engagementRate:
					typeof row.engagementRate === 'number'
						? row.engagementRate
						: null,
				source: row,
			}))
		},

		/**
		 * The rows in the chosen order. A row with no rate sorts last in both
		 * directions, as the server's ranking does: it has nothing to say yet.
		 *
		 * @return {Array<object>} The sorted rows.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		sortedRows() {
			if (!this.sortKey) {
				return this.tableRows
			}

			const key = this.sortKey
			const direction = this.sortOrder === 'desc' ? -1 : 1
			const valueOf = (row) =>
				key === 'network' ? this.networkLabel(row.network) : row[key]

			return [...this.tableRows].sort((left, right) => {
				const a = valueOf(left)
				const b = valueOf(right)
				if (a === null || b === null) {
					return (a === null) - (b === null)
				}

				if (typeof a === 'number' && typeof b === 'number') {
					return (a - b) * direction
				}

				return String(a).localeCompare(String(b)) * direction
			})
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * @param {object} event The table's `{key, order}` sort event.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		onSort(event) {
			this.sortKey = event?.key || null
			this.sortOrder = event?.order || 'asc'
		},

		/**
		 * @param {string} network The network.
		 * @return {object} Its icon component.
		 */
		networkIcon(network) {
			return networkIcon(network)
		},

		/**
		 * @param {string} value An ISO timestamp.
		 * @return {string} It in the reader's locale, or as stored when unreadable.
		 */
		formatDate(value) {
			const date = new Date(value)
			return Number.isNaN(date.getTime()) ? value || '' : date.toLocaleString()
		},

		/**
		 * @param {string} network The network.
		 * @return {string} Its label.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		networkLabel(network) {
			return networkLimits(network).label
		},

		/**
		 * @param {object} row A ranking row.
		 * @return {string} The engagement rate, or a hyphen.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		rate(row) {
			return formatEngagementRate(row)
		},

		/**
		 * One request, after the page has already rendered.
		 *
		 * @return {Promise<void>} Resolves once the rows are in.
		 * @spec openspec/changes/social-publishing/specs/social-metrics/spec.md#requirement-posts-are-ranked-by-engagement-rate-per-network
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.rows = await fetchPerformance()
			} catch {
				this.error = t('pipelinq', 'The numbers could not be loaded.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.social-performance {
	box-sizing: border-box;
	width: 100%;
	max-width: 1240px;
	margin: 0 auto;
	padding: 24px 20px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.social-performance h2 {
	margin: 0;
}

.social-performance__network {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.social-performance__icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 24px;
	height: 24px;
	border-radius: 50%;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}
</style>

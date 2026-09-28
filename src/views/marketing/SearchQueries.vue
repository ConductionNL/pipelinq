<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Search queries (marketing-campaign-attribution): the top queries by clicks
  over a window, aggregated from the Search Console rows the daily import
  wrote. Empty until a property is connected in the settings and the import
  has run.

  @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
-->
<template>
	<div class="search-queries">
		<header class="search-queries__header">
			<h2>{{ t('pipelinq', 'Search queries') }}</h2>
			<NcSelect
				v-model="window"
				class="search-queries__window"
				:options="windowOptions"
				:clearable="false"
				:inputLabel="t('pipelinq', 'Period')"
				label="label"
				@input="fetchRows" />
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="rows.length === 0"
			class="search-queries__empty"
			data-testid="search-queries-empty"
			:name="t('pipelinq', 'No search data yet')"
			:description="emptyDescription">
			<template #icon>
				<Magnify :size="20" />
			</template>
		</NcEmptyContent>

		<section v-else>
			<p class="search-queries__meta">
				{{
					t('pipelinq', '{count} queries between {from} and {to}', {
						count: totalQueries,
						from,
						to,
					})
				}}
				<span v-if="lastImportAt">
					{{
						t('pipelinq', 'Last import: {when}', { when: lastImportAt })
					}}
				</span>
			</p>
			<!-- CnDataTable only reports header clicks; the sorting itself is
				done here, over the rows the one request brought in. -->
			<CnDataTable
				data-testid="search-queries-table"
				:columns="columns"
				:rows="sortedRows"
				:sortKey="sortKey"
				:sortOrder="sortOrder"
				rowKey="query"
				@sort="onSort">
				<template #column-ctr="{ row }">
					{{ percent(row.ctr) }}
				</template>
			</CnDataTable>
		</section>

		<p v-if="error" class="search-queries__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

const DAY = 24 * 60 * 60 * 1000

export default {
	name: 'SearchQueries',
	components: {
		CnDataTable,
		Magnify,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			error: '',
			rows: [],
			totalQueries: 0,
			from: '',
			to: '',
			configured: false,
			lastImportAt: '',
			window: null,
			// The server's own order, until a header is clicked.
			sortKey: 'clicks',
			sortOrder: 'desc',
		}
	},

	computed: {
		/**
		 * @return {Array<object>} The columns, every one sortable.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		columns() {
			const number = (key, label) => ({
				key,
				label,
				sortable: true,
				class: 'search-queries__num',
			})
			return [
				{ key: 'query', label: this.t('pipelinq', 'Query'), sortable: true },
				number('clicks', this.t('pipelinq', 'Clicks')),
				number('impressions', this.t('pipelinq', 'Impressions')),
				number('ctr', this.t('pipelinq', 'CTR')),
				number('position', this.t('pipelinq', 'Position')),
				number('pages', this.t('pipelinq', 'Pages')),
			]
		},

		/**
		 * The rows in the chosen order.
		 *
		 * @return {Array<object>} The sorted rows.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		sortedRows() {
			if (!this.sortKey) {
				return this.rows
			}

			const key = this.sortKey
			const direction = this.sortOrder === 'desc' ? -1 : 1
			return [...this.rows].sort((left, right) => {
				const a = left[key]
				const b = right[key]
				if (key === 'query') {
					return String(a ?? '').localeCompare(String(b ?? '')) * direction
				}
				return (Number(a || 0) - Number(b || 0)) * direction
			})
		},

		/**
		 * The selectable windows.
		 *
		 * @return {Array<object>} days and label per option.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		windowOptions() {
			return [
				{ days: 7, label: this.t('pipelinq', 'Last 7 days') },
				{ days: 28, label: this.t('pipelinq', 'Last 28 days') },
				{ days: 90, label: this.t('pipelinq', 'Last 90 days') },
			]
		},

		/**
		 * What the empty state says: point at the settings when nothing is
		 * connected, at Google's publishing lag when it is.
		 *
		 * @return {string}
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		emptyDescription() {
			if (!this.configured) {
				return this.t(
					'pipelinq',
					'Connect a Search Console property and a service account key under Settings, Marketing traffic. The first import runs within a day.',
				)
			}
			return this.t(
				'pipelinq',
				'The import has not brought in rows for this period yet. Search Console publishes a day about two days later.',
			)
		},
	},

	created() {
		this.window = this.windowOptions[1]
	},

	mounted() {
		this.fetchRows()
	},

	methods: {
		/**
		 * @param {object} event The table's `{key, order}` sort event.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		onSort(event) {
			this.sortKey = event?.key || null
			this.sortOrder = event?.order || 'asc'
		},

		/**
		 * GET /api/marketing/search-queries for the chosen window.
		 *
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		async fetchRows() {
			this.loading = true
			this.error = ''
			const days = this.window?.days || 28
			const today = new Date()
			const start = new Date(today.getTime() - days * DAY)
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/marketing/search-queries',
				)
				const { data } = await axios.get(url, {
					params: {
						from: this.isoDay(start),
						to: this.isoDay(today),
						limit: 100,
					},
				})
				this.rows = data?.rows || []
				this.totalQueries = data?.totalQueries || 0
				this.from = data?.from || ''
				this.to = data?.to || ''
				this.configured = Boolean(data?.configured)
				this.lastImportAt = data?.lastImportAt || ''
			} catch (e) {
				this.rows = []
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load search queries.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {Date} date A date.
		 * @return {string} YYYY-MM-DD in UTC.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		isoDay(date) {
			return date.toISOString().slice(0, 10)
		},

		/**
		 * @param {number} ratio A ratio between 0 and 1.
		 * @return {string} A percentage with one decimal.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
		 */
		percent(ratio) {
			return `${(Number(ratio || 0) * 100).toFixed(1)}%`
		},
	},
}
</script>

<style scoped lang="scss">
.search-queries {
	padding: 20px;
}

.search-queries__header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.search-queries__window {
	min-width: 200px;
}

.search-queries__meta {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

// The parent compound outranks the table's own left alignment.
.search-queries :deep(.cn-data-table .search-queries__num) {
	text-align: end;
}

.search-queries__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

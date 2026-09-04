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
			<div class="search-queries__scroll">
				<table
					class="search-queries__table"
					data-testid="search-queries-table">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Query') }}</th>
							<th scope="col" class="search-queries__num">
								{{ t('pipelinq', 'Clicks') }}
							</th>
							<th scope="col" class="search-queries__num">
								{{ t('pipelinq', 'Impressions') }}
							</th>
							<th scope="col" class="search-queries__num">
								{{ t('pipelinq', 'CTR') }}
							</th>
							<th scope="col" class="search-queries__num">
								{{ t('pipelinq', 'Position') }}
							</th>
							<th scope="col" class="search-queries__num">
								{{ t('pipelinq', 'Pages') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in rows" :key="row.query">
							<td>{{ row.query }}</td>
							<td class="search-queries__num">{{ row.clicks }}</td>
							<td class="search-queries__num">
								{{ row.impressions }}
							</td>
							<td class="search-queries__num">
								{{ percent(row.ctr) }}
							</td>
							<td class="search-queries__num">{{ row.position }}</td>
							<td class="search-queries__num">{{ row.pages }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<p v-if="error" class="search-queries__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'

const DAY = 24 * 60 * 60 * 1000

export default {
	name: 'SearchQueries',
	components: {
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
		}
	},

	computed: {
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

.search-queries__scroll {
	overflow-x: auto;
}

.search-queries__table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
	}

	th {
		font-weight: bold;
	}
}

.search-queries__num {
	text-align: end;
}

.search-queries__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

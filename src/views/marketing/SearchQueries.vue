<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Search insight (board PqZoekinzicht): where residents find us in search
  engines, and what others publish, on one page. Four sections, each over an
  endpoint that already serves its own page:
  - Search queries: the top queries by clicks over a window, aggregated from
    the Search Console rows the daily import wrote;
  - Keywords: the keyword proposals (KeywordIntelligence, embedded);
  - Competitors: the competitor watches (CompetitorWatches, embedded);
  - Connection audit: the stored follow audit, with Check again.

  @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-search-queries-page-lists-top-queries
  @spec openspec/changes/search-insight-hub/specs/marketing/spec.md
-->
<template>
	<div class="search-queries" :class="lookClass">
		<CnPageHeader
			:title="t('pipelinq', 'Search insight')"
			:description="t('pipelinq', 'Where residents find us in search engines, and what others publish · four pages under Marketing')">
			<template #extra>
				<div class="search-queries__header-actions">
					<NcButton
						variant="secondary"
						:disabled="rows.length === 0"
						data-testid="search-queries-download"
						@click="download">
						<template #icon>
							<Download :size="18" />
						</template>
						{{ t('pipelinq', 'Download') }}
					</NcButton>
					<CnBuildiqEditButton />
				</div>
			</template>
		</CnPageHeader>

		<section class="search-queries__card" data-testid="search-queries-section">
			<div class="search-queries__card-head">
				<h2 class="search-queries__card-title">
					{{ t('pipelinq', 'Search queries') }}
				</h2>
				<div class="search-queries__period">
					<span class="search-queries__period-label">{{ t('pipelinq', 'Period') }}</span>
					<CnSegmentedControl
						:modelValue="days"
						:options="windowOptions"
						:ariaLabel="t('pipelinq', 'Period')"
						size="compact"
						@update:modelValue="setDays" />
				</div>
			</div>

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

			<template v-else>
				<p class="search-queries__meta">
					{{ t('pipelinq', '{count} queries between {from} and {to}', { count: totalQueries, from, to }) }}
					<span v-if="lastImportAt">
						· {{ t('pipelinq', 'Last import: {when}', { when: lastImportAt }) }}
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
			</template>

			<p v-if="error" class="search-queries__error" role="alert">
				{{ error }}
			</p>
		</section>

		<section class="search-queries__card" data-testid="search-insight-keywords">
			<div class="search-queries__card-head search-queries__card-head--stacked">
				<h2 class="search-queries__card-title">
					{{ t('pipelinq', 'Keywords') }}
				</h2>
				<span class="search-queries__card-subtitle">
					{{ t('pipelinq', 'Suggestions from the search data: what to improve first') }}
				</span>
			</div>
			<KeywordIntelligence embedded :days="days" />
		</section>

		<section class="search-queries__card" data-testid="search-insight-competitors">
			<div class="search-queries__card-head search-queries__card-head--stacked">
				<h2 class="search-queries__card-title">
					{{ t('pipelinq', 'Competitors') }}
				</h2>
				<span class="search-queries__card-subtitle">
					{{ t('pipelinq', 'Follows feeds, sitemaps, shares of pages, public fediverse timelines and saved searches') }}
				</span>
			</div>
			<CompetitorWatches embedded />
		</section>

		<section class="search-queries__card" data-testid="search-insight-connections">
			<div class="search-queries__card-head">
				<div class="search-queries__card-head--stacked">
					<h2 class="search-queries__card-title">
						{{ t('pipelinq', 'Connection audit') }}
					</h2>
					<span class="search-queries__card-subtitle">
						{{ t('pipelinq', 'Clients with an account on a network we publish on') }}
					</span>
				</div>
				<NcButton
					variant="tertiary"
					:disabled="auditRunning"
					data-testid="search-insight-audit-rerun"
					@click="rerunAudit">
					<template #icon>
						<Refresh :size="18" />
					</template>
					{{ t('pipelinq', 'Check again') }}
				</NcButton>
			</div>
			<p v-if="auditRows.length === 0" class="search-queries__meta">
				{{ t('pipelinq', 'No connections checked yet.') }}
			</p>
			<div v-else class="search-queries__table-card">
				<table class="search-queries__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('pipelinq', 'Username') }}
							</th>
							<th scope="col">
								{{ t('pipelinq', 'Network') }}
							</th>
							<th scope="col">
								{{ t('pipelinq', 'We follow them') }}
							</th>
							<th scope="col">
								{{ t('pipelinq', 'They follow us') }}
							</th>
							<th scope="col">
								{{ t('pipelinq', 'Reason') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in auditRows" :key="row.id || `${row.network}:${row.counterpartHandle}`">
							<td>{{ row.counterpartHandle || '—' }}</td>
							<td>{{ networkLabel(row.network) }}</td>
							<td>{{ auditVerdict(row.weFollowThem) }}</td>
							<td>{{ auditVerdict(row.theyFollowUs) }}</td>
							<td>{{ row.reason || '' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>
	</div>
</template>

<script>
import {
	CnBuildiqEditButton,
	CnDataTable,
	CnPageHeader,
	CnSegmentedControl,
	useLook,
} from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import CompetitorWatches from './CompetitorWatches.vue'
import KeywordIntelligence from './KeywordIntelligence.vue'
import { auditVerdict, CONNECTION_AUDIT_PATH } from '../../services/connectionAudit.js'

const DAY = 24 * 60 * 60 * 1000

/** How many audit rows the section shows; the Connection audit page has them all. */
const AUDIT_ROWS = 5

export default {
	name: 'SearchQueries',
	components: {
		CnBuildiqEditButton,
		CnDataTable,
		CnPageHeader,
		CnSegmentedControl,
		CompetitorWatches,
		Download,
		KeywordIntelligence,
		Magnify,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		Refresh,
	},

	setup() {
		const { lookClass } = useLook()
		return { lookClass }
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
			days: 28,
			// The server's own order, until a header is clicked.
			sortKey: 'clicks',
			sortOrder: 'desc',
			auditRows: [],
			auditRunning: false,
		}
	},

	computed: {
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

		windowOptions() {
			return [
				{ value: 7, label: this.t('pipelinq', 'Last 7 days') },
				{ value: 28, label: this.t('pipelinq', 'Last 28 days') },
				{ value: 90, label: this.t('pipelinq', 'Last 90 days') },
			]
		},

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

	mounted() {
		this.fetchRows()
		this.fetchAudit()
	},

	methods: {
		auditVerdict,

		onSort(event) {
			this.sortKey = event?.key || null
			this.sortOrder = event?.order || 'asc'
		},

		setDays(value) {
			this.days = Number(value) || 28
			this.fetchRows()
		},

		async fetchRows() {
			this.loading = true
			this.error = ''
			const today = new Date()
			const start = new Date(today.getTime() - this.days * DAY)
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
		 * The stored connection audit, or a fresh run of it.
		 *
		 * @param {boolean} refresh Re-run the audit before reading.
		 * @return {Promise<boolean>} Whether the audit answered.
		 */
		async fetchAudit(refresh = false) {
			try {
				const { data } = await axios.get(generateUrl(CONNECTION_AUDIT_PATH), {
					params: refresh ? { refresh: 'true' } : {},
				})
				this.auditRows = (data?.rows || []).slice(0, AUDIT_ROWS)
				return true
			} catch {
				this.auditRows = []
				return false
			}
		},

		async rerunAudit() {
			this.auditRunning = true
			const ok = await this.fetchAudit(true)
			this.auditRunning = false
			if (ok) {
				showSuccess(this.t('pipelinq', 'The connection audit ran again.'))
			} else {
				showError(this.t('pipelinq', 'Could not load the connection audit.'))
			}
		},

		networkLabel(network) {
			const labels = {
				mastodon: 'Mastodon',
				bluesky: 'Bluesky',
				linkedin: 'LinkedIn',
				instagram: 'Instagram',
				facebook: 'Facebook',
			}
			return labels[String(network || '').toLowerCase()] || network || '—'
		},

		/**
		 * The query rows as the table shows them, as a CSV file.
		 *
		 * @return {void}
		 */
		download() {
			const keys = ['query', 'clicks', 'impressions', 'ctr', 'position', 'pages']
			const cell = (value) => {
				const text = value === null || value === undefined ? '' : String(value)
				return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
			}
			const lines = [keys.join(';'), ...this.sortedRows.map((row) => keys.map((key) => cell(row[key])).join(';'))]
			const blob = new Blob(['﻿' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = 'search-queries.csv'
			link.click()
			URL.revokeObjectURL(link.href)
		},

		isoDay(date) {
			return date.toISOString().slice(0, 10)
		},

		percent(ratio) {
			return `${(Number(ratio || 0) * 100).toFixed(1)}%`
		},
	},
}
</script>

<style scoped lang="scss">
.search-queries {
	box-sizing: border-box;
	width: 100%;
	max-width: 1240px;
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.search-queries__header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 10px;
}

.search-queries__card {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 20px 22px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 12px;
}

.search-queries__card-head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 8px 16px;
}

.search-queries__card-head--stacked {
	flex-direction: column;
	align-items: flex-start;
	gap: 2px;
}

.search-queries__card-title {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
}

.search-queries__card-subtitle {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.search-queries__period {
	display: flex;
	align-items: center;
	gap: 12px;
}

.search-queries__period-label {
	font-size: 14px;
	font-weight: 600;
}

.search-queries__meta {
	margin: 0;
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.search-queries__empty {
	margin-top: 8px;
}

.search-queries__error {
	margin: 0;
	color: var(--color-error);
	font-weight: 600;
}

:deep(.search-queries__num) {
	text-align: end;
}

.search-queries__table-card {
	border: 1px solid var(--color-border);
	border-radius: 10px;
	overflow-x: auto;
}

.search-queries__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;

	th,
	td {
		padding: 10px 12px;
		text-align: start;
		border-bottom: 1px solid var(--color-border);
	}

	th {
		color: var(--color-text-maxcontrast);
		font-weight: 600;
	}

	th:first-child,
	td:first-child {
		padding-inline-start: 18px;
	}

	tbody tr:last-child td {
		border-bottom: 0;
	}
}
</style>

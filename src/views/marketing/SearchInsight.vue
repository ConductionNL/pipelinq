<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Search insight (PqZoekinzicht): where residents find us in search engines
  and what others publish, as one page of four cards. Each card is the page
  that already answers its question (search queries, keywords, competitors,
  connection audit); this view only stacks them, so each keeps its own fetch,
  empty state and actions.

  @spec openspec/specs/marketing-ui/spec.md
-->
<template>
	<div class="search-insight">
		<header class="search-insight__header">
			<div>
				<h2>{{ t('pipelinq', 'Search insight') }}</h2>
				<p class="search-insight__subtitle">
					{{ t('pipelinq', 'Where residents find us in search engines, and what others publish · four pages under Marketing') }}
				</p>
			</div>
			<div class="search-insight__actions">
			<NcButton variant="secondary" @click="download">
				<template #icon>
					<Download :size="18" />
				</template>
				{{ t('pipelinq', 'Download') }}
			</NcButton>
			<CnBuildiqEditButton />
			</div>
		</header>
		<section class="search-insight__card">
			<SearchQueries ref="queries" />
		</section>
		<section class="search-insight__card">
			<KeywordIntelligence :embedded="true" />
		</section>
		<section class="search-insight__card">
			<CompetitorWatches />
		</section>
		<section class="search-insight__card">
			<ConnectionAudit />
		</section>
	</div>
</template>

<script>
import { CnBuildiqEditButton } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Download from 'vue-material-design-icons/Download.vue'
import CompetitorWatches from './CompetitorWatches.vue'
import ConnectionAudit from './ConnectionAudit.vue'
import KeywordIntelligence from './KeywordIntelligence.vue'
import SearchQueries from './SearchQueries.vue'

export default {
	name: 'SearchInsight',
	components: {
		CnBuildiqEditButton,
		CompetitorWatches,
		Download,
		NcButton,
		ConnectionAudit,
		KeywordIntelligence,
		SearchQueries,
	},

	methods: {
		/**
		 * Save the search queries of the chosen period as a CSV file, the
		 * columns as the card shows them.
		 *
		 * @spec openspec/specs/marketing-ui/spec.md
		 */
		download() {
			const rows = this.$refs.queries?.sortedRows || []
			const keys = ['query', 'clicks', 'impressions', 'ctr', 'position', 'pages']
			const cell = (value) => {
				const text = value === null || value === undefined ? '' : String(value)
				return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
			}
			const lines = [keys.join(';'), ...rows.map((row) => keys.map((key) => cell(row[key])).join(';'))]
			const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = 'search-queries.csv'
			link.click()
			URL.revokeObjectURL(link.href)
		},
	},
}
</script>

<style scoped lang="scss">
.search-insight {
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.search-insight__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.search-insight__actions {
	display: flex;
	gap: 8px;
}

.search-insight__header h2 {
	margin: 0 0 6px;
	font-size: 28px;
}

.search-insight__subtitle {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.search-insight__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 4px 4px 0;

	:deep(h2) {
		font-size: 17px;
		margin: 0;
	}
}
</style>

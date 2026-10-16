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
			<h2>{{ t('pipelinq', 'Search insight') }}</h2>
			<p class="search-insight__subtitle">
				{{ t('pipelinq', 'Where residents find us in search engines, and what others publish') }}
			</p>
		</header>
		<section class="search-insight__card">
			<SearchQueries />
		</section>
		<section class="search-insight__card">
			<KeywordIntelligence />
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
import CompetitorWatches from './CompetitorWatches.vue'
import ConnectionAudit from './ConnectionAudit.vue'
import KeywordIntelligence from './KeywordIntelligence.vue'
import SearchQueries from './SearchQueries.vue'

export default {
	name: 'SearchInsight',
	components: {
		CompetitorWatches,
		ConnectionAudit,
		KeywordIntelligence,
		SearchQueries,
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

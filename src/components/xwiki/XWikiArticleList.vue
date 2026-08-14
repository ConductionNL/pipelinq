<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - XWikiArticleList — shared renderer used by XWikiWidget and XWikiSidebarTab.
  - Accepts an `articles` array prop and emits `select` on click. Stateless;
  - no store coupling.
  -->
<template>
	<div class="xwiki-article-list">
		<ul v-if="articles.length > 0" class="xwiki-article-list__items">
			<li
				v-for="article in articles"
				:key="article.id || article.title"
				class="xwiki-article-list__item"
				role="button"
				tabindex="0"
				@click="$emit('select', article)"
				@keydown.enter.prevent="$emit('select', article)"
				@keydown.space.prevent="$emit('select', article)">
				<div class="xwiki-article-list__title">
					{{ article.title }}
				</div>
				<div class="xwiki-article-list__meta">
					<span v-if="article.space" class="xwiki-article-list__space">{{
						article.space
					}}</span>
					<span
						v-if="article.modified"
						class="xwiki-article-list__modified"
						>{{ formatDate(article.modified) }}</span
					>
				</div>
			</li>
		</ul>
		<div v-else class="xwiki-article-list__empty">
			{{ t('pipelinq', 'No articles found') }}
		</div>
	</div>
</template>

<script>
export default {
	name: 'XWikiArticleList',
	props: {
		articles: {
			type: Array,
			required: true,
		},
	},
	emits: ['select'],
	methods: {
		formatDate(iso) {
			if (!iso) return ''
			const d = new Date(iso)
			if (isNaN(d.getTime())) return iso
			try {
				return d.toLocaleDateString()
			} catch (e) {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.xwiki-article-list__items {
	list-style: none;
	padding: 0;
	margin: 0;
}

.xwiki-article-list__item {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
	cursor: pointer;
}

.xwiki-article-list__item:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.xwiki-article-list__title {
	font-weight: 600;
	color: var(--color-main-text);
}

.xwiki-article-list__meta {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
	display: flex;
	gap: 8px;
}

.xwiki-article-list__space {
	background: var(--color-background-dark, #eef);
	padding: 0 6px;
	border-radius: 3px;
}

.xwiki-article-list__empty {
	padding: 16px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>

<template>
	<div class="xwiki-article-list">
		<div v-if="articles.length === 0" class="xwiki-article-list__empty">
			{{ t('pipelinq', 'Geen artikelen gevonden') }}
		</div>
		<ul v-else class="xwiki-article-list__items">
			<li
				v-for="article in articles"
				:key="article.id"
				class="xwiki-article-list__item"
				tabindex="0"
				role="button"
				:aria-label="article.title"
				@click="$emit('select', article)"
				@keydown.enter="$emit('select', article)"
				@keydown.space.prevent="$emit('select', article)">
				<span class="xwiki-article-list__title">{{ article.title }}</span>
				<span v-if="article.space" class="xwiki-article-list__badge">{{ article.space }}</span>
				<span v-if="article.modified" class="xwiki-article-list__date">
					{{ formatDate(article.modified) }}
				</span>
			</li>
		</ul>
	</div>
</template>

<script>
/**
 * XWikiArticleList — shared list renderer for xWiki articles.
 *
 * Accepts an `articles` array prop and emits a `select` event when an item is
 * clicked. Used by both XWikiWidget (compact) and XWikiSidebarTab (full).
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.1
 */
export default {
	name: 'XWikiArticleList',

	props: {
		/**
		 * Array of article objects with id, title, space, modified, url.
		 */
		articles: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['select'],

	methods: {
		/**
		 * Format an ISO date string to a human-readable locale date.
		 *
		 * @param {string} iso The ISO date string.
		 * @return {string} Locale-formatted date or empty string.
		 */
		formatDate(iso) {
			if (!iso) return ''
			try {
				return new Date(iso).toLocaleDateString(undefined, {
					year: 'numeric',
					month: 'short',
					day: 'numeric',
				})
			} catch {
				return iso
			}
		},
	},
}
</script>

<style scoped>
.xwiki-article-list__empty {
	padding: 16px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.xwiki-article-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
}

.xwiki-article-list__item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
	border-radius: var(--border-radius);
	transition: background 0.1s;
}

.xwiki-article-list__item:hover,
.xwiki-article-list__item:focus {
	background: var(--color-background-hover);
	outline: none;
}

.xwiki-article-list__title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.xwiki-article-list__badge {
	font-size: 11px;
	padding: 2px 6px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element);
	border-radius: 10px;
	white-space: nowrap;
	flex-shrink: 0;
}

.xwiki-article-list__date {
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}
</style>

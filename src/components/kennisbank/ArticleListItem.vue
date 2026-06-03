<template>
	<div
		class="article-list-item"
		tabindex="0"
		role="button"
		@click="$emit('click')"
		@keydown.enter="$emit('click')">
		<div class="article-list-item__badges">
			<span
				class="status-badge"
				:class="'status-badge--' + (article.status || 'concept')">
				{{ statusLabel }}
			</span>
			<span
				class="visibility-badge"
				:class="'visibility-badge--' + (article.visibility || 'intern')">
				{{ article.visibility === 'openbaar' ? t('pipelinq', 'Public') : t('pipelinq', 'Internal') }}
			</span>
		</div>
		<h3 class="article-list-item__title" v-html="highlightedTitle" />
		<p v-if="article.summary" class="article-list-item__summary" v-html="highlightedSummary" />
		<div class="article-list-item__meta">
			<span v-if="categoryName" class="article-list-item__category">
				{{ categoryName }}
			</span>
			<span v-if="article.tags && article.tags.length" class="article-list-item__tags">
				{{ article.tags.slice(0, 3).join(', ') }}
			</span>
		</div>
	</div>
</template>

<script>
export default {
	name: 'ArticleListItem',
	props: {
		article: {
			type: Object,
			required: true,
		},
		categoryName: {
			type: String,
			default: '',
		},
		searchQuery: {
			type: String,
			default: '',
		},
	},
	computed: {
		statusLabel() {
			const labels = {
				concept: t('pipelinq', 'Draft'),
				gepubliceerd: t('pipelinq', 'Published'),
				gearchiveerd: t('pipelinq', 'Archived'),
			}
			return labels[this.article.status] || this.article.status || t('pipelinq', 'Draft')
		},
		highlightedTitle() {
			return this.highlightText(this.article.title || '')
		},
		highlightedSummary() {
			const summary = this.article.summary || ''
			const truncated = summary.length > 200 ? summary.substring(0, 200) + '...' : summary
			return this.highlightText(truncated)
		},
	},
	methods: {
		highlightText(text) {
			if (!this.searchQuery || this.searchQuery.length < 2) {
				return this.escapeHtml(text)
			}
			const escaped = this.escapeHtml(text)
			const query = this.escapeHtml(this.searchQuery)
			const regex = new RegExp('(' + query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi')
			return escaped.replace(regex, '<mark>$1</mark>')
		},
		escapeHtml(text) {
			const div = document.createElement('div')
			div.textContent = text
			return div.innerHTML
		},
	},
}
</script>

<style scoped>
.article-list-item {
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	cursor: pointer;
	transition: box-shadow 0.2s;
}

.article-list-item:hover,
.article-list-item:focus {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
	outline: none;
}

.article-list-item__badges {
	display: flex;
	gap: 6px;
	margin-bottom: 6px;
}

.article-list-item__title {
	margin: 0 0 4px;
	font-size: 1em;
	font-weight: 600;
}

.article-list-item__summary {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	margin: 0 0 6px;
	display: -webkit-box;
	-webkit-line-clamp: 2;
	-webkit-box-orient: vertical;
	overflow: hidden;
}

.article-list-item__meta {
	display: flex;
	gap: 12px;
	font-size: 0.8em;
	color: var(--color-text-maxcontrast);
}

.article-list-item__category {
	font-weight: 600;
}

.status-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 0.75em;
	font-weight: 600;
	text-transform: uppercase;
}

.status-badge--concept { background: var(--color-warning); color: #000; }
.status-badge--gepubliceerd { background: var(--color-success); color: #fff; }
.status-badge--gearchiveerd { background: var(--color-text-lighter); color: #fff; }

.visibility-badge {
	padding: 2px 8px;
	border-radius: var(--border-radius);
	font-size: 0.75em;
}

.visibility-badge--openbaar { background: var(--color-primary-element-light); }
.visibility-badge--intern { background: var(--color-background-dark); }
</style>

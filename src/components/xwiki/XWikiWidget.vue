<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - XWikiWidget — compact dashboard/detail card showing recent xWiki articles
  - filtered by space/tags/query. Optional debounced search input. Shows the
  - graceful-degradation message when the xWiki proxy reports unavailable.
  -->
<template>
	<div class="xwiki-widget">
		<header class="xwiki-widget__header">
			<h3 class="xwiki-widget__title">
				{{ title }}
			</h3>
			<div v-if="showSearch" class="xwiki-widget__search">
				<input
					v-model="localQuery"
					type="search"
					class="xwiki-widget__search-input"
					:aria-label="t('pipelinq', 'Search knowledge base')"
					:placeholder="t('pipelinq', 'Search knowledge base')"
					@input="onSearchInput" />
			</div>
		</header>
		<div
			v-if="store.available === false && store.status !== null"
			class="xwiki-widget__unavailable">
			{{ t('pipelinq', 'xWiki integration unavailable') }}
		</div>
		<NcLoadingIcon v-else-if="store.loading" />
		<XWikiArticleList v-else :articles="visibleArticles" @select="onSelect" />
		<a
			v-if="hasMore"
			class="xwiki-widget__more"
			href="#"
			@click.prevent="$emit('view-more')">
			{{ t('pipelinq', 'View more') }}
		</a>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useXwikiStore } from '../../store/modules/xwiki.js'
import XWikiArticleList from './XWikiArticleList.vue'

export default {
	name: 'XWikiWidget',
	components: { NcLoadingIcon, XWikiArticleList },
	props: {
		space: {
			type: String,
			default: '',
		},
		tags: {
			type: Array,
			default: () => [],
		},
		query: {
			type: String,
			default: '',
		},
		limit: {
			type: Number,
			default: 5,
		},
		title: {
			type: String,
			default: 'Knowledge base',
		},
		showSearch: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['select', 'view-more'],
	setup() {
		return { store: useXwikiStore() }
	},
	data() {
		return {
			localQuery: this.query,
			debounceHandle: null,
		}
	},
	computed: {
		visibleArticles() {
			return (this.store.articles || []).slice(0, this.limit)
		},
		hasMore() {
			return (this.store.articles || []).length > this.limit
		},
	},
	async mounted() {
		await this.store.checkStatus()
		await this.refresh()
	},
	methods: {
		async refresh() {
			await this.store.search({
				q: this.localQuery,
				space: this.space,
				tags: this.tags,
				limit: this.limit + 1,
			})
		},
		onSelect(article) {
			this.$emit('select', article)
		},
		onSearchInput() {
			if (this.debounceHandle) clearTimeout(this.debounceHandle)
			this.debounceHandle = setTimeout(() => {
				this.refresh()
			}, 300)
		},
	},
}
</script>

<style scoped>
.xwiki-widget {
	display: flex;
	flex-direction: column;
	background: var(--color-main-background, #fff);
	border: 1px solid var(--color-border, #e0e0e0);
	border-radius: var(--border-radius-large, 8px);
	padding: 12px;
}

.xwiki-widget__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 8px;
}

.xwiki-widget__title {
	margin: 0;
}

.xwiki-widget__search-input {
	border: 1px solid var(--color-border, #ccc);
	border-radius: var(--border-radius, 4px);
	padding: 4px 8px;
}

.xwiki-widget__unavailable {
	padding: 16px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.xwiki-widget__more {
	display: block;
	margin-top: 8px;
	text-align: right;
	font-size: 0.9em;
	color: var(--color-primary, #006aa3);
}
</style>

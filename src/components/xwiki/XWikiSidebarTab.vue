<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - XWikiSidebarTab — full sidebar panel with three modes (search / space
  - browser / article viewer). Renders inside any detail-view sidebar.
  -->
<template>
	<div class="xwiki-sidebar-tab">
		<div v-if="store.available === false && store.status !== null" class="xwiki-sidebar-tab__unavailable">
			{{ t('pipelinq', 'xWiki integration unavailable') }}
		</div>
		<template v-else>
			<nav class="xwiki-sidebar-tab__tabs">
				<button
					type="button"
					:class="{ active: mode === 'search' }"
					@click="setMode('search')">
					{{ t('pipelinq', 'Search') }}
				</button>
				<button
					type="button"
					:class="{ active: mode === 'spaces' }"
					@click="setMode('spaces')">
					{{ t('pipelinq', 'Spaces') }}
				</button>
			</nav>
			<div v-if="mode === 'article'">
				<XWikiArticleViewer
					:wiki="selectedWiki"
					:page="selectedPage"
					@back="setMode('search')" />
			</div>
			<div v-else-if="mode === 'spaces'">
				<ul class="xwiki-sidebar-tab__space-list">
					<li
						v-for="s in store.spaces"
						:key="s"
						class="xwiki-sidebar-tab__space"
						@click="browseSpace(s)">
						{{ s }}
					</li>
					<li v-if="!store.spaces.length" class="xwiki-sidebar-tab__empty">
						{{ t('pipelinq', 'No spaces available') }}
					</li>
				</ul>
			</div>
			<div v-else>
				<div class="xwiki-sidebar-tab__search">
					<input
						v-model="searchTerm"
						type="search"
						class="xwiki-sidebar-tab__search-input"
						:aria-label="t('pipelinq', 'Search knowledge base')"
						:placeholder="t('pipelinq', 'Search knowledge base')"
						@input="onSearchInput">
				</div>
				<NcLoadingIcon v-if="store.loading" />
				<XWikiArticleList
					v-else
					:articles="store.articles"
					@select="openArticle" />
			</div>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { useXwikiStore } from '../../store/modules/xwiki.js'
import XWikiArticleList from './XWikiArticleList.vue'
import XWikiArticleViewer from './XWikiArticleViewer.vue'

export default {
	name: 'XWikiSidebarTab',
	components: { NcLoadingIcon, XWikiArticleList, XWikiArticleViewer },
	props: {
		space: {
			type: String,
			default: '',
		},
		tags: {
			type: Array,
			default: () => [],
		},
		contextQuery: {
			type: String,
			default: '',
		},
		limit: {
			type: Number,
			default: 10,
		},
	},
	setup() {
		return { store: useXwikiStore() }
	},
	data() {
		return {
			mode: 'search',
			searchTerm: this.contextQuery,
			selectedWiki: 'xwiki',
			selectedPage: '',
			debounceHandle: null,
		}
	},
	async mounted() {
		await this.store.checkStatus()
		await this.refresh()
	},
	methods: {
		async refresh() {
			await this.store.search({
				q: this.searchTerm,
				space: this.space,
				tags: this.tags,
				limit: this.limit,
			})
		},
		setMode(mode) {
			this.mode = mode
		},
		onSearchInput() {
			if (this.debounceHandle) clearTimeout(this.debounceHandle)
			this.debounceHandle = setTimeout(() => {
				this.refresh()
			}, 300)
		},
		async browseSpace(space) {
			await this.store.getPages(space, { limit: this.limit })
		},
		openArticle(article) {
			this.selectedPage = article.id || article.title
			this.selectedWiki = article.wiki || 'xwiki'
			this.mode = 'article'
		},
	},
}
</script>

<style scoped>
.xwiki-sidebar-tab {
	display: flex;
	flex-direction: column;
	height: 100%;
}

.xwiki-sidebar-tab__tabs {
	display: flex;
	gap: 4px;
	padding: 8px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
}

.xwiki-sidebar-tab__tabs button {
	background: transparent;
	border: 1px solid transparent;
	padding: 4px 12px;
	border-radius: var(--border-radius, 4px);
	cursor: pointer;
}

.xwiki-sidebar-tab__tabs button.active {
	background: var(--color-primary-light, #e6f1fa);
	border-color: var(--color-primary, #006aa3);
}

.xwiki-sidebar-tab__search {
	padding: 8px;
}

.xwiki-sidebar-tab__search-input {
	width: 100%;
	padding: 6px 10px;
	border: 1px solid var(--color-border, #ccc);
	border-radius: var(--border-radius, 4px);
}

.xwiki-sidebar-tab__space-list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.xwiki-sidebar-tab__space {
	padding: 8px 12px;
	cursor: pointer;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
}

.xwiki-sidebar-tab__space:hover {
	background: var(--color-background-hover, #f5f5f5);
}

.xwiki-sidebar-tab__empty {
	padding: 12px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.xwiki-sidebar-tab__unavailable {
	padding: 16px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}
</style>

<template>
	<div class="xwiki-sidebar">
		<!-- Mode tabs -->
		<div class="xwiki-sidebar__modes" role="tablist">
			<button
				role="tab"
				:aria-selected="mode === 'search'"
				:class="['xwiki-sidebar__mode-btn', { 'xwiki-sidebar__mode-btn--active': mode === 'search' }]"
				@click="mode = 'search'">
				{{ t('pipelinq', 'Zoeken') }}
			</button>
			<button
				role="tab"
				:aria-selected="mode === 'browse'"
				:class="['xwiki-sidebar__mode-btn', { 'xwiki-sidebar__mode-btn--active': mode === 'browse' }]"
				@click="switchToBrowse">
				{{ t('pipelinq', 'Bladeren') }}
			</button>
		</div>

		<!-- xWiki unavailable -->
		<div v-if="!xwikiStore.available && checkedStatus" class="xwiki-sidebar__unavailable">
			{{ t('pipelinq', 'xWiki integratie niet beschikbaar') }}
		</div>

		<!-- Checking status -->
		<div v-else-if="!checkedStatus" class="xwiki-sidebar__loading">
			<NcLoadingIcon :size="20" />
		</div>

		<!-- Article viewer mode -->
		<XWikiArticleViewer
			v-else-if="mode === 'article' && selectedPage"
			:wiki="selectedWiki"
			:page="selectedPage"
			@back="backToList" />

		<!-- Search mode -->
		<div v-else-if="mode === 'search'" class="xwiki-sidebar__search-mode">
			<div class="xwiki-sidebar__search-input">
				<NcTextField
					:value="searchQuery"
					:label="t('pipelinq', 'Zoeken in kennisbank')"
					:placeholder="t('pipelinq', 'Zoek een artikel…')"
					@update:value="onSearchInput" />
			</div>

			<div v-if="xwikiStore.loading" class="xwiki-sidebar__loading">
				<NcLoadingIcon :size="20" />
			</div>
			<XWikiArticleList
				v-else
				:articles="xwikiStore.articles"
				@select="onArticleSelect" />
		</div>

		<!-- Browse mode -->
		<div v-else-if="mode === 'browse'" class="xwiki-sidebar__browse-mode">
			<div v-if="xwikiStore.loading" class="xwiki-sidebar__loading">
				<NcLoadingIcon :size="20" />
			</div>
			<XWikiArticleList
				v-else
				:articles="xwikiStore.articles"
				@select="onArticleSelect" />
		</div>
	</div>
</template>

<script>
/**
 * XWikiSidebarTab — sidebar panel with search, space browser, and article viewer.
 *
 * Three modes:
 *  - search (default): search input + article list
 *  - browse: pages in the configured space
 *  - article: inline article viewer with back button
 *
 * Designed to be mounted in client, lead, and request detail sidebars with
 * context-aware filtering via the `contextQuery` prop.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.4
 */
import { NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import XWikiArticleList from './XWikiArticleList.vue'
import XWikiArticleViewer from './XWikiArticleViewer.vue'
import { useXWikiStore } from '../../store/modules/xwiki.js'

export default {
	name: 'XWikiSidebarTab',

	components: {
		NcLoadingIcon,
		NcTextField,
		XWikiArticleList,
		XWikiArticleViewer,
	},

	props: {
		/** Default xWiki space to browse. */
		space: {
			type: String,
			default: '',
		},
		/** Tag filters. */
		tags: {
			type: Array,
			default: () => [],
		},
		/** Pre-filled context query (e.g. from the current entity type/category). */
		contextQuery: {
			type: String,
			default: '',
		},
		/** Maximum results to show in lists. */
		limit: {
			type: Number,
			default: 10,
		},
	},

	data() {
		return {
			/** Current panel mode: 'search' | 'browse' | 'article' */
			mode: 'search',
			searchQuery: this.contextQuery,
			selectedWiki: 'xwiki',
			selectedPage: '',
			checkedStatus: false,
			debounceTimer: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.4
		 */
		xwikiStore() {
			return useXWikiStore()
		},
	},

	async mounted() {
		await this.xwikiStore.checkStatus()
		this.checkedStatus = true

		if (this.xwikiStore.available && this.searchQuery) {
			await this.executeSearch()
		}
	},

	beforeUnmount() {
		clearTimeout(this.debounceTimer)
	},

	methods: {
		/**
		 * Execute the current search query.
		 *
		 * @return {Promise<void>}
		 */
		async executeSearch() {
			await this.xwikiStore.search({
				q: this.searchQuery,
				space: this.space,
				tags: this.tags,
				limit: this.limit,
			})
		},

		/**
		 * Handle debounced search input changes.
		 *
		 * @param {string} value The new query value.
		 * @return {void}
		 */
		onSearchInput(value) {
			this.searchQuery = value
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => {
				this.executeSearch()
			}, 300)
		},

		/**
		 * Switch to browse mode and load space pages.
		 *
		 * @return {Promise<void>}
		 */
		async switchToBrowse() {
			this.mode = 'browse'
			if (this.space) {
				await this.xwikiStore.getPages(this.space, this.limit)
			}
		},

		/**
		 * Handle article selection — switch to article viewer mode.
		 *
		 * @param {object} article The selected article.
		 * @return {void}
		 */
		onArticleSelect(article) {
			if (article.id) {
				this.selectedWiki = 'xwiki'
				// article.id format: "xwiki:Space.Page.WebHome" → extract page part
				this.selectedPage = article.id.includes(':')
					? article.id.split(':')[1]
					: article.id
				this.mode = 'article'
			} else if (article.url) {
				window.open(article.url, '_blank', 'noopener noreferrer')
			}
		},

		/**
		 * Return to the previous list mode from article viewer.
		 *
		 * @return {void}
		 */
		backToList() {
			this.selectedPage = ''
			this.mode = this.space ? 'browse' : 'search'
			this.xwikiStore.clearCurrentArticle()
		},
	},
}
</script>

<style scoped>
.xwiki-sidebar {
	height: 100%;
	overflow: auto;
	display: flex;
	flex-direction: column;
}

.xwiki-sidebar__modes {
	display: flex;
	border-bottom: 1px solid var(--color-border);
	flex-shrink: 0;
}

.xwiki-sidebar__mode-btn {
	flex: 1;
	padding: 8px;
	font-size: 13px;
	background: none;
	border: none;
	cursor: pointer;
	color: var(--color-text-maxcontrast);
	transition: background 0.1s, color 0.1s;
}

.xwiki-sidebar__mode-btn:hover {
	background: var(--color-background-hover);
	color: var(--color-main-text);
}

.xwiki-sidebar__mode-btn--active {
	color: var(--color-primary-element);
	border-bottom: 2px solid var(--color-primary-element);
	font-weight: 600;
}

.xwiki-sidebar__unavailable,
.xwiki-sidebar__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 24px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	flex: 1;
}

.xwiki-sidebar__search-input {
	padding: 8px 12px 4px;
}
</style>

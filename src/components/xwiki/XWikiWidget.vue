<template>
	<div class="xwiki-widget">
		<!-- xWiki unavailable -->
		<div v-if="!xwikiStore.available && !xwikiStore.loading && checkedStatus" class="xwiki-widget__unavailable">
			{{ t('pipelinq', 'xWiki integratie niet beschikbaar') }}
		</div>

		<!-- Loading initial status -->
		<div v-else-if="!checkedStatus" class="xwiki-widget__loading">
			<NcLoadingIcon :size="20" />
		</div>

		<template v-else>
			<!-- Optional search input -->
			<div v-if="showSearch" class="xwiki-widget__search">
				<NcTextField
					:value="localQuery"
					:label="t('pipelinq', 'Zoeken in kennisbank')"
					:placeholder="t('pipelinq', 'Zoek een artikel…')"
					@update:value="onSearchInput" />
			</div>

			<!-- Article list or loading spinner -->
			<div v-if="xwikiStore.loading" class="xwiki-widget__loading">
				<NcLoadingIcon :size="20" />
			</div>
			<XWikiArticleList
				v-else
				:articles="displayedArticles"
				@select="onArticleSelect" />

			<!-- "Meer bekijken" when results exceed limit -->
			<div v-if="xwikiStore.total > limit" class="xwiki-widget__more">
				<NcButton type="tertiary" @click="onViewMore">
					{{ t('pipelinq', 'Meer bekijken ({total})', { total: xwikiStore.total }) }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
/**
 * XWikiWidget — compact article list for dashboards and detail page cards.
 *
 * Configurable via props: space filter, tags, initial query, result limit,
 * widget title, and showSearch toggle. Automatically checks xWiki availability
 * on mount and fetches articles. Shows a graceful unavailability message when
 * xWiki is unreachable.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.2
 */
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import XWikiArticleList from './XWikiArticleList.vue'
import { useXWikiStore } from '../../store/modules/xwiki.js'

export default {
	name: 'XWikiWidget',

	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
		XWikiArticleList,
	},

	props: {
		/** Filter by xWiki space. */
		space: {
			type: String,
			default: '',
		},
		/** Filter by tags (array of strings). */
		tags: {
			type: Array,
			default: () => [],
		},
		/** Initial search query. */
		query: {
			type: String,
			default: '',
		},
		/** Maximum articles to show. */
		limit: {
			type: Number,
			default: 5,
		},
		/** Widget title (unused internally; passed by parent CnDashboardPage slot). */
		title: {
			type: String,
			default: 'Kennisbank',
		},
		/** Whether to show an inline search input. */
		showSearch: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['article-selected'],

	data() {
		return {
			localQuery: this.query,
			checkedStatus: false,
			debounceTimer: null,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.2
		 */
		xwikiStore() {
			return useXWikiStore()
		},
		displayedArticles() {
			return this.xwikiStore.articles.slice(0, this.limit)
		},
	},

	async mounted() {
		await this.xwikiStore.checkStatus()
		this.checkedStatus = true

		if (this.xwikiStore.available) {
			await this.fetchArticles()
		}
	},

	beforeUnmount() {
		clearTimeout(this.debounceTimer)
	},

	methods: {
		/**
		 * Fetch articles for the current props/query state.
		 *
		 * @return {Promise<void>}
		 */
		async fetchArticles() {
			await this.xwikiStore.search({
				q: this.localQuery,
				space: this.space,
				tags: this.tags,
				limit: this.limit,
			})
		},

		/**
		 * Handle debounced search input.
		 *
		 * @param {string} value New search value.
		 * @return {void}
		 */
		onSearchInput(value) {
			this.localQuery = value
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => {
				this.fetchArticles()
			}, 300)
		},

		/**
		 * Handle article selection.
		 *
		 * @param {object} article The selected article.
		 * @return {void}
		 */
		onArticleSelect(article) {
			this.$emit('article-selected', article)
			if (article.url) {
				window.open(article.url, '_blank', 'noopener noreferrer')
			}
		},

		/**
		 * View all results in xWiki (open base URL).
		 *
		 * @return {void}
		 */
		onViewMore() {
			const status = this.xwikiStore
			if (status.version !== undefined) {
				// Open xWiki in new tab; the URL is tracked via status.
				window.open(
					this.space
						? `${this.getXWikiUrl()}/bin/view/${this.space}/`
						: this.getXWikiUrl(),
					'_blank',
					'noopener noreferrer',
				)
			}
		},

		/**
		 * Get the configured xWiki URL from settings store.
		 *
		 * @return {string} The xWiki base URL or empty string.
		 */
		getXWikiUrl() {
			try {
				const { useSettingsStore } = require('../../store/modules/settings.js')
				return useSettingsStore().config?.xwiki_direct_url ?? ''
			} catch {
				return ''
			}
		},
	},
}
</script>

<style scoped>
.xwiki-widget {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.xwiki-widget__unavailable,
.xwiki-widget__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 24px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.xwiki-widget__search {
	padding: 8px 12px 4px;
}

.xwiki-widget__more {
	padding: 4px 12px 8px;
}
</style>

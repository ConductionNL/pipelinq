<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - XWikiWidget — compact dashboard/detail card showing recent xWiki articles
  - filtered by space/tags/query. Optional debounced search input. Shows the
  - graceful-degradation message when the xWiki proxy reports unavailable.
  -->
<template>
	<div class="xwiki-widget" :class="{ 'xwiki-widget--bare': bare }">
		<header v-if="!bare" class="xwiki-widget__header">
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
		<form
			v-else-if="showSearch"
			role="search"
			class="xwiki-widget__search-bar"
			@submit.prevent="refresh">
			<Magnify :size="18" class="xwiki-widget__search-icon" aria-hidden="true" />
			<input
				v-model="localQuery"
				type="search"
				class="xwiki-widget__search-bar-input"
				:aria-label="t('pipelinq', 'Search the knowledge base')"
				:placeholder="t('pipelinq', 'Search the knowledge base')"
				@input="onSearchInput" />
		</form>
		<div
			v-if="store.available === false && store.status !== null"
			class="xwiki-widget__unavailable">
			{{ t('pipelinq', 'xWiki integration unavailable') }}
		</div>
		<NcLoadingIcon v-else-if="store.loading" />
		<XWikiArticleList v-else :articles="visibleArticles" linkExternal />
		<a
			v-if="hasMore"
			class="xwiki-widget__more"
			href="#"
			@click.prevent="$emit('viewMore')">
			{{ t('pipelinq', 'View more') }}
		</a>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import XWikiArticleList from './XWikiArticleList.vue'
import { useXwikiStore } from '../../store/modules/xwiki.js'

export default {
	name: 'XWikiWidget',
	components: { Magnify, NcLoadingIcon, XWikiArticleList },
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

		/**
		 * Draw no card and no title of its own, for a host that already draws
		 * the card (a dashboard widget); the search becomes a full-width field.
		 */
		bare: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['viewMore'],
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

.xwiki-widget--bare {
	gap: 8px;
	padding: 0 var(--cn-board-dashboard-card-inset, 22px) 20px;
	border: 0;
	background: none;
}

.xwiki-widget__search-bar {
	position: relative;
}

.xwiki-widget__search-icon {
	position: absolute;
	top: 11px;
	inset-inline-start: 12px;
	color: var(--color-text-maxcontrast);
}

.xwiki-widget__search-bar-input {
	box-sizing: border-box;
	width: 100%;
	height: 40px;
	/* Nextcloud's input rule sets its own height with a stronger selector;
	   a min-height still lifts the field to 40px. */
	min-height: 40px;
	margin: 0;
	padding-block: 0;
	padding-inline: 38px 12px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
	font-size: 14px;
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
	text-align: end;
	font-size: 0.9em;
	color: var(--color-primary, #006aa3);
}
</style>

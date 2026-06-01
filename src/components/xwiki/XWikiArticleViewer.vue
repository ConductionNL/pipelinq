<template>
	<div class="xwiki-viewer">
		<!-- Loading state -->
		<div v-if="loading" class="xwiki-viewer__loading">
			<NcLoadingIcon :size="24" />
			<span>{{ t('pipelinq', 'Artikel laden…') }}</span>
		</div>

		<!-- Error state -->
		<div v-else-if="error" class="xwiki-viewer__error">
			<span>{{ error }}</span>
		</div>

		<!-- Content -->
		<div v-else-if="article" class="xwiki-viewer__content">
			<div class="xwiki-viewer__header">
				<NcButton type="tertiary" @click="$emit('back')">
					← {{ t('pipelinq', 'Terug') }}
				</NcButton>
				<h4 class="xwiki-viewer__title">{{ article.title }}</h4>
				<a
					v-if="article.url"
					:href="article.url"
					target="_blank"
					rel="noopener noreferrer"
					class="xwiki-viewer__external-link">
					{{ t('pipelinq', 'Open in xWiki') }} ↗
				</a>
			</div>
			<!-- eslint-disable-next-line vue/no-v-html - content is sanitized server-side -->
			<div class="xwiki-viewer__body" v-html="article.content" />
		</div>

		<!-- Empty / not loaded state -->
		<div v-else class="xwiki-viewer__empty">
			{{ t('pipelinq', 'Selecteer een artikel om te bekijken') }}
		</div>
	</div>
</template>

<script>
/**
 * XWikiArticleViewer — inline HTML content viewer for xWiki pages.
 *
 * Accepts `wiki` and `page` props, fetches rendered HTML via the xWiki store,
 * and displays the sanitized content. Emits a `back` event when the back
 * button is clicked.
 *
 * Note: The `v-html` binding is intentional — content is sanitized server-side
 * by XWikiService::sanitizeHtml() before being returned by the proxy.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.3
 */
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useXWikiStore } from '../../store/modules/xwiki.js'

export default {
	name: 'XWikiArticleViewer',

	components: {
		NcButton,
		NcLoadingIcon,
	},

	props: {
		/** The wiki identifier (e.g. "xwiki"). */
		wiki: {
			type: String,
			default: 'xwiki',
		},
		/** The page reference (e.g. "Kennisbank.Paspoort.WebHome"). */
		page: {
			type: String,
			default: '',
		},
	},

	emits: ['back'],

	computed: {
		/**
		 * xWiki Pinia store.
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-4.3
		 */
		xwikiStore() {
			return useXWikiStore()
		},
		loading() {
			return this.xwikiStore.loading
		},
		error() {
			return this.xwikiStore.error
		},
		article() {
			return this.xwikiStore.currentArticle
		},
	},

	watch: {
		page: {
			immediate: true,
			handler(newPage) {
				if (newPage) {
					this.xwikiStore.getPageContent(this.wiki, newPage)
				}
			},
		},
	},

	methods: {},
}
</script>

<style scoped>
.xwiki-viewer {
	height: 100%;
	overflow: auto;
}

.xwiki-viewer__loading,
.xwiki-viewer__error,
.xwiki-viewer__empty {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 8px;
	padding: 24px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	text-align: center;
}

.xwiki-viewer__error {
	color: var(--color-error);
}

.xwiki-viewer__header {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	flex-wrap: wrap;
}

.xwiki-viewer__title {
	flex: 1;
	font-size: 15px;
	font-weight: 600;
	margin: 0;
}

.xwiki-viewer__external-link {
	font-size: 12px;
	color: var(--color-primary-element);
}

.xwiki-viewer__body {
	padding: 16px;
	font-size: 14px;
	line-height: 1.6;
	overflow-wrap: break-word;
}

/* Scope xWiki content styles */
.xwiki-viewer__body :deep(h1),
.xwiki-viewer__body :deep(h2),
.xwiki-viewer__body :deep(h3) {
	margin-top: 16px;
	margin-bottom: 8px;
	font-weight: 600;
}

.xwiki-viewer__body :deep(table) {
	border-collapse: collapse;
	width: 100%;
	margin: 8px 0;
}

.xwiki-viewer__body :deep(td),
.xwiki-viewer__body :deep(th) {
	border: 1px solid var(--color-border);
	padding: 6px 10px;
}

.xwiki-viewer__body :deep(a) {
	color: var(--color-primary-element);
}

.xwiki-viewer__body :deep(img) {
	max-width: 100%;
	height: auto;
}
</style>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - XWikiArticleViewer — inline HTML viewer. Fetches the page via the xwiki
  - store and renders it through v-html. Provides "Open in xWiki" and a back
  - button.
  -
  - The markup is remote: it comes from whatever xWiki instance the admin
  - configured, over which this app has no control, and nothing sanitises it
  - server-side. It is run through DOMPurify here before it reaches v-html.
  -->
<template>
	<div class="xwiki-article-viewer">
		<div class="xwiki-article-viewer__bar">
			<NcButton variant="tertiary" @click="$emit('back')">
				{{ t('pipelinq', 'Back') }}
			</NcButton>
			<a
				v-if="store.currentArticle && store.currentArticle.url"
				class="xwiki-article-viewer__open"
				:href="store.currentArticle.url"
				target="_blank"
				rel="noopener noreferrer">
				{{ t('pipelinq', 'Open in xWiki') }}
			</a>
		</div>
		<NcLoadingIcon v-if="store.loading" />
		<div v-else-if="store.error" class="xwiki-article-viewer__error">
			{{ t('pipelinq', 'Failed to load article') }}: {{ store.error }}
		</div>
		<div v-else-if="store.currentArticle">
			<h3 class="xwiki-article-viewer__title">
				{{ store.currentArticle.title }}
			</h3>
			<!-- eslint-disable-next-line vue/no-v-html -->
			<div class="xwiki-article-viewer__content" v-html="sanitisedContent" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import DOMPurify from 'dompurify'
import { useXwikiStore } from '../../store/modules/xwiki.js'

export default {
	name: 'XWikiArticleViewer',
	components: { NcButton, NcLoadingIcon },
	props: {
		wiki: {
			type: String,
			default: 'xwiki',
		},

		page: {
			type: String,
			required: true,
		},
	},

	emits: ['back'],
	setup() {
		return { store: useXwikiStore() }
	},

	computed: {
		/**
		 * The article body, sanitised before it reaches v-html.
		 *
		 * @return {string} Safe HTML, or '' when no article is loaded.
		 * @spec exclude XSS hardening on remote xWiki markup; openspec/specs/xwiki-proxy
		 *   covers where the content is fetched from, not how it is rendered, so
		 *   there is no requirement to point at.
		 */
		sanitisedContent() {
			const raw = this.store.currentArticle?.content
			return raw ? DOMPurify.sanitize(raw) : ''
		},
	},

	watch: {
		page: {
			immediate: true,
			handler(value) {
				if (value) {
					this.store.getPageContent(this.wiki, value)
				}
			},
		},
	},
}
</script>

<style scoped>
.xwiki-article-viewer__bar {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #e0e0e0);
}

.xwiki-article-viewer__open {
	color: var(--color-primary, #006aa3);
	text-decoration: none;
}

.xwiki-article-viewer__title {
	margin: 12px 8px 8px;
}

.xwiki-article-viewer__content {
	padding: 0 12px 16px;
	max-height: 60vh;
	overflow-y: auto;
}

.xwiki-article-viewer__error {
	padding: 12px;
	color: var(--color-error, #e9322d);
}
</style>

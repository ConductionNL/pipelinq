<template>
	<div class="article-detail">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="article">
			<div class="article-detail__header">
				<div class="article-detail__breadcrumb">
					<router-link :to="{ name: 'Kennisbank' }">
						{{ t('pipelinq', 'Knowledge Base') }}
					</router-link>
					<span> / </span>
					<span>{{ article.title }}</span>
				</div>

				<div class="article-detail__actions">
					<NcButton
						type="secondary"
						@click="$router.push({ name: 'KennisbankEdit', params: { id: article.id } })">
						{{ t('pipelinq', 'Edit') }}
					</NcButton>
				</div>
			</div>

			<div class="article-detail__meta">
				<span
					class="status-badge"
					:class="'status-badge--' + article.status">
					{{ article.status }}
				</span>
				<span
					class="visibility-badge"
					:class="'visibility-badge--' + article.visibility">
					{{ article.visibility === 'openbaar' ? t('pipelinq', 'Public') : t('pipelinq', 'Internal') }}
				</span>
				<span v-if="article.version" class="article-detail__version">
					v{{ article.version }}
				</span>
				<span class="article-detail__author">
					{{ t('pipelinq', 'By') }} {{ article.author }}
				</span>
			</div>

			<div v-if="article.tags && article.tags.length" class="article-detail__tags">
				<span
					v-for="tag in article.tags"
					:key="tag"
					class="tag-chip">
					{{ tag }}
				</span>
			</div>

			<div class="article-detail__body" v-html="renderedBody" />

			<FeedbackSummary v-if="feedback.length > 0" :feedback="feedback" />

			<ArticleFeedback
				:article-id="articleId"
				@feedback-submitted="onFeedbackSubmitted" />
		</template>

		<NcEmptyContent
			v-else
			:name="t('pipelinq', 'Article not found')"
			:description="t('pipelinq', 'The requested article could not be found.')">
			<template #action>
				<NcButton @click="$router.push({ name: 'Kennisbank' })">
					{{ t('pipelinq', 'Back to Knowledge Base') }}
				</NcButton>
			</template>
		</NcEmptyContent>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'
import MarkdownIt from 'markdown-it'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'
import ArticleFeedback from '../../components/kennisbank/ArticleFeedback.vue'
import FeedbackSummary from '../../components/kennisbank/FeedbackSummary.vue'

const md = new MarkdownIt({ html: false, linkify: true, typographer: true })

export default {
	name: 'ArticleDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		ArticleFeedback,
		FeedbackSummary,
	},
	props: {
		articleId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			feedback: [],
		}
	},
	computed: {
		store() {
			return useKennisbankStore()
		},
		article() {
			return this.store.currentArticle
		},
		loading() {
			return this.store.loading
		},
		renderedBody() {
			if (!this.article || !this.article.body) {
				return ''
			}
			return md.render(this.article.body)
		},
	},
	watch: {
		articleId: {
			immediate: true,
			async handler(id) {
				if (id && id !== 'new') {
					await this.store.fetchArticle(id)
					this.feedback = await this.store.fetchArticleFeedback(id)
				}
			},
		},
	},
	created() {
		if (!this.store.categories.length) {
			this.store.fetchCategories()
		}
	},
	methods: {
		async onFeedbackSubmitted() {
			this.feedback = await this.store.fetchArticleFeedback(this.articleId)
		},
	},
}
</script>

<style scoped>
.article-detail {
	padding: 20px;
	max-width: 900px;
	margin: 0 auto;
}

.article-detail__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.article-detail__breadcrumb {
	font-size: 0.9em;
	color: var(--color-text-lighter);
}

.article-detail__breadcrumb a {
	color: var(--color-primary-element);
	text-decoration: none;
}

.article-detail__meta {
	display: flex;
	gap: 8px;
	align-items: center;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.article-detail__version,
.article-detail__author {
	font-size: 0.85em;
	color: var(--color-text-lighter);
}

.article-detail__tags {
	display: flex;
	gap: 6px;
	margin-bottom: 16px;
	flex-wrap: wrap;
}

.tag-chip {
	background: var(--color-background-dark);
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.8em;
}

.article-detail__body {
	line-height: 1.7;
	margin-bottom: 32px;
}

.article-detail__body :deep(img) { max-width: 100%; }
.article-detail__body :deep(code) { background: var(--color-background-dark); padding: 2px 6px; border-radius: 4px; }
.article-detail__body :deep(pre) { background: var(--color-background-dark); padding: 12px; border-radius: var(--border-radius); overflow-x: auto; }

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

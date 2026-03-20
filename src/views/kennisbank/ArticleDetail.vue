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

			<div class="article-detail__feedback">
				<h3>{{ t('pipelinq', 'Was this article helpful?') }}</h3>
				<div class="feedback-buttons">
					<NcButton
						:type="userRating === 'nuttig' ? 'primary' : 'secondary'"
						@click="submitRating('nuttig')">
						{{ t('pipelinq', 'Helpful') }}
					</NcButton>
					<NcButton
						:type="userRating === 'niet_nuttig' ? 'error' : 'secondary'"
						@click="submitRating('niet_nuttig')">
						{{ t('pipelinq', 'Not helpful') }}
					</NcButton>
				</div>

				<div v-if="showSuggestionForm" class="feedback-suggestion">
					<NcTextField
						:value.sync="suggestionText"
						:label="t('pipelinq', 'Suggest an improvement...')"
						:multiline="true" />
					<NcButton
						type="primary"
						:disabled="!suggestionText.trim()"
						@click="submitSuggestion">
						{{ t('pipelinq', 'Submit suggestion') }}
					</NcButton>
				</div>

				<NcButton
					v-if="!showSuggestionForm"
					type="tertiary"
					@click="showSuggestionForm = true">
					{{ t('pipelinq', 'Suggest improvement') }}
				</NcButton>
			</div>
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
import { NcButton, NcLoadingIcon, NcEmptyContent, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'

export default {
	name: 'ArticleDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcTextField,
	},
	props: {
		articleId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			article: null,
			loading: true,
			userRating: null,
			showSuggestionForm: false,
			suggestionText: '',
		}
	},
	computed: {
		renderedBody() {
			if (!this.article || !this.article.body) {
				return ''
			}
			// Basic Markdown rendering — in production, use marked or markdown-it
			return this.article.body
				.replace(/^### (.*$)/gim, '<h3>$1</h3>')
				.replace(/^## (.*$)/gim, '<h2>$1</h2>')
				.replace(/^# (.*$)/gim, '<h1>$1</h1>')
				.replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>')
				.replace(/\*(.*?)\*/gim, '<em>$1</em>')
				.replace(/\n/gim, '<br>')
		},
	},
	mounted() {
		this.fetchArticle()
	},
	methods: {
		async fetchArticle() {
			this.loading = true
			try {
				// Article is fetched from OpenRegister directly
				this.article = null
			} catch (error) {
				console.error('Failed to fetch article:', error)
			} finally {
				this.loading = false
			}
		},
		async submitRating(rating) {
			try {
				const url = generateUrl('/apps/pipelinq/api/kennisbank/feedback')
				await axios.post(url, {
					articleId: this.articleId,
					rating,
				})
				this.userRating = rating
				showSuccess(t('pipelinq', 'Thank you for your feedback'))
			} catch (error) {
				showError(t('pipelinq', 'Failed to submit feedback'))
			}
		},
		async submitSuggestion() {
			try {
				const url = generateUrl('/apps/pipelinq/api/kennisbank/feedback')
				await axios.post(url, {
					articleId: this.articleId,
					rating: 'niet_nuttig',
					comment: this.suggestionText,
				})
				this.suggestionText = ''
				this.showSuggestionForm = false
				showSuccess(t('pipelinq', 'Suggestion submitted successfully'))
			} catch (error) {
				showError(t('pipelinq', 'Failed to submit suggestion'))
			}
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

.article-detail__feedback {
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.feedback-buttons {
	display: flex;
	gap: 8px;
	margin-top: 8px;
	margin-bottom: 16px;
}

.feedback-suggestion {
	margin-top: 12px;
	display: flex;
	flex-direction: column;
	gap: 8px;
	max-width: 500px;
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

<template>
	<div class="kennisbank-detail">
		<div v-if="loading" style="display:flex;justify-content:center;padding:80px">
			<NcLoadingIcon :size="64" />
		</div>
		<template v-else-if="article">
			<div style="margin-bottom:16px">
				<NcButton type="tertiary" @click="$router.push({ name: 'Kennisbank' })">
					<template #icon>
						<ArrowLeft :size="20" />
					</template>
					{{ t('pipelinq', 'Back to Kennisbank') }}
				</NcButton>
			</div>
			<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px">
				<div>
					<h1 :style="article.status === 'gearchiveerd' ? 'text-decoration:line-through;color:var(--color-text-maxcontrast)' : ''">
						{{ article.title }}
					</h1>
					<div style="display:flex;gap:6px">
						<span v-if="article.status === 'gearchiveerd'" class="badge badge--archived">{{ t('pipelinq', 'Archived') }}</span>
						<span v-if="article.status === 'concept'" class="badge badge--draft">{{ t('pipelinq', 'Draft') }}</span>
						<span class="badge" :class="article.visibility === 'openbaar' ? 'badge--public' : 'badge--internal'">
							{{ article.visibility === 'openbaar' ? t('pipelinq', 'Public') : t('pipelinq', 'Internal') }}
						</span>
						<span v-if="needsReview" class="badge badge--review">{{ t('pipelinq', 'Review required') }}</span>
					</div>
				</div>
				<NcButton type="primary" @click="$router.push({ name: 'KennisbankEdit', params: { id: articleId } })">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
			</div>
			<div v-if="article.tags && article.tags.length" style="display:flex;gap:6px;margin-bottom:12px">
				<span v-for="tag in article.tags" :key="tag" style="background:var(--color-background-dark);padding:2px 8px;border-radius:4px;font-size:12px">{{ tag }}</span>
			</div>
			<div style="display:flex;gap:16px;font-size:13px;color:var(--color-text-maxcontrast);margin-bottom:20px;border-bottom:1px solid var(--color-border);padding-bottom:12px">
				<span>{{ t('pipelinq', 'Version') }} {{ article.version || 1 }}</span>
				<span v-if="article.author">{{ t('pipelinq', 'Author:') }} {{ article.author }}</span>
			</div>
			<div class="detail-body" v-html="renderedBody" />
			<div style="margin-top:24px;padding:16px;border:1px solid var(--color-border);border-radius:var(--border-radius);background:var(--color-background-dark)">
				<h3 style="margin:0 0 12px">
					{{ t('pipelinq', 'Was this article helpful?') }}
				</h3>
				<div style="display:flex;gap:8px">
					<NcButton :type="submitted === 'nuttig' ? 'primary' : 'secondary'" @click="rate('nuttig')">
						{{ t('pipelinq', 'Helpful') }}
					</NcButton>
					<NcButton :type="submitted === 'niet_nuttig' ? 'error' : 'secondary'" @click="rate('niet_nuttig')">
						{{ t('pipelinq', 'Not helpful') }}
					</NcButton>
				</div>
				<div v-if="submitted" style="margin-top:8px;color:var(--color-success);font-size:13px">
					{{ t('pipelinq', 'Thank you!') }}
				</div>
			</div>
		</template>
		<div v-else style="text-align:center;padding:80px;color:var(--color-text-maxcontrast)">
			<p>{{ t('pipelinq', 'Article not found') }}</p>
		</div>
	</div>
</template>
<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import MarkdownIt from 'markdown-it'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'
const md = new MarkdownIt({ html: false, linkify: true, typographer: true })
export default {
	name: 'KennisbankDetail',
	components: { NcButton, NcLoadingIcon, ArrowLeft },
	props: { articleId: { type: String, required: true } },
	data() { return { feedback: [], submitted: null } },
	computed: {
		store() { return useKennisbankStore() },
		article() { return this.store.currentArticle },
		loading() { return this.store.loading },
		renderedBody() { return this.article?.body ? md.render(this.article.body) : '' },
		needsReview() { if (this.feedback.length < 5) return false; return (this.feedback.filter(f => f.rating === 'nuttig').length / this.feedback.length) < 0.7 },
	},
	watch: { articleId: { immediate: true, async handler(id) { if (id && id !== 'new') { await this.store.fetchArticle(id); this.feedback = await this.store.fetchArticleFeedback(id) } } } },
	created() { if (!this.store.categories.length) this.store.fetchCategories() },
	methods: { async rate(r) { await this.store.submitFeedback(this.articleId, r); this.submitted = r; this.feedback = await this.store.fetchArticleFeedback(this.articleId) } },
}
</script>
<style scoped>
.kennisbank-detail { padding: 20px; max-width: 900px }

.badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600 }

.badge--public { background: #dcfce7; color: #166534 }

.badge--internal { background: var(--color-background-dark); color: var(--color-text-maxcontrast) }

.badge--archived { background: #fef3c7; color: #92400e }

.badge--draft { background: #dbeafe; color: #1e40af }

.badge--review { background: #fee2e2; color: #991b1b }

.detail-body { line-height: 1.6; font-size: 15px }

.detail-body :deep(img) { max-width: 100% }

.detail-body :deep(code) { background: var(--color-background-dark); padding: 2px 6px; border-radius: 4px }

.detail-body :deep(pre) { background: var(--color-background-dark); padding: 12px; border-radius: var(--border-radius); overflow-x: auto }
</style>

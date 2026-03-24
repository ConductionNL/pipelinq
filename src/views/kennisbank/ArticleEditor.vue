<template>
	<div class="article-editor">
		<div class="article-editor__header">
			<h2>{{ isNew ? t('pipelinq', 'New Article') : t('pipelinq', 'Edit Article') }}</h2>
			<div class="article-editor__actions">
				<NcButton type="tertiary" @click="goBack">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="secondary"
					:disabled="saving"
					@click="save('concept')">
					{{ t('pipelinq', 'Save as draft') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="saving || !isValid"
					@click="save('gepubliceerd')">
					{{ t('pipelinq', 'Publish') }}
				</NcButton>
			</div>
		</div>

		<div v-if="duplicateWarning" class="article-editor__warning">
			{{ t('pipelinq', 'An article with this title already exists.') }}
			<NcButton type="tertiary" @click="goToDuplicate">
				{{ t('pipelinq', 'View existing article') }}
			</NcButton>
		</div>

		<div class="article-editor__form">
			<div class="form-row">
				<NcTextField
					:value.sync="form.title"
					:label="t('pipelinq', 'Title')"
					:required="true"
					@update:value="checkDuplicate" />
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="form.summary"
					:label="t('pipelinq', 'Summary (shown in search results)')"
					:maxlength="500" />
			</div>

			<div class="form-row form-row--split">
				<div class="form-col">
					<label>{{ t('pipelinq', 'Category') }}</label>
					<select v-model="form.category" class="form-select">
						<option :value="null">{{ t('pipelinq', 'No category') }}</option>
						<option
							v-for="cat in categories"
							:key="cat.id"
							:value="cat.id">
							{{ cat.name }}
						</option>
					</select>
				</div>
				<div class="form-col">
					<label>{{ t('pipelinq', 'Visibility') }}</label>
					<select v-model="form.visibility" class="form-select">
						<option value="intern">{{ t('pipelinq', 'Internal (agents only)') }}</option>
						<option value="openbaar">{{ t('pipelinq', 'Public (citizen-facing)') }}</option>
					</select>
				</div>
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="tagsInput"
					:label="t('pipelinq', 'Tags (comma-separated)')" />
			</div>

			<div class="article-editor__body">
				<div class="editor-pane">
					<label>{{ t('pipelinq', 'Content (Markdown)') }}</label>
					<textarea
						v-model="form.body"
						class="markdown-editor"
						:placeholder="t('pipelinq', 'Write your article content in Markdown...')"
						rows="20" />
				</div>
				<div class="preview-pane">
					<label>{{ t('pipelinq', 'Preview') }}</label>
					<div class="markdown-preview" v-html="renderedPreview" />
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import MarkdownIt from 'markdown-it'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'

const md = new MarkdownIt({ html: false, linkify: true, typographer: true })
let duplicateCheckTimeout = null

export default {
	name: 'ArticleEditor',
	components: {
		NcButton,
		NcTextField,
	},
	props: {
		articleId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				title: '',
				body: '',
				summary: '',
				category: null,
				visibility: 'intern',
				tags: [],
				status: 'concept',
			},
			tagsInput: '',
			saving: false,
			duplicateWarning: null,
		}
	},
	computed: {
		store() {
			return useKennisbankStore()
		},
		isNew() {
			return !this.articleId
		},
		isValid() {
			return this.form.title.trim() !== '' && this.form.body.trim() !== ''
		},
		renderedPreview() {
			if (!this.form.body) {
				return ''
			}
			return md.render(this.form.body)
		},
		categories() {
			return this.store.categories
		},
	},
	watch: {
		tagsInput(val) {
			this.form.tags = val.split(',').map(t => t.trim()).filter(t => t !== '')
		},
	},
	async created() {
		if (!this.store.categories.length) {
			await this.store.fetchCategories()
		}
		if (this.articleId) {
			await this.loadArticle()
		}
	},
	methods: {
		async loadArticle() {
			const article = await this.store.fetchArticle(this.articleId)
			if (article) {
				this.form = {
					title: article.title || '',
					body: article.body || '',
					summary: article.summary || '',
					category: article.category || null,
					visibility: article.visibility || 'intern',
					tags: article.tags || [],
					status: article.status || 'concept',
				}
				this.tagsInput = (article.tags || []).join(', ')
			}
		},
		async save(status) {
			if (!this.isValid) {
				return
			}

			this.saving = true
			try {
				const data = { ...this.form, status }
				let result
				if (this.isNew) {
					result = await this.store.createArticle(data)
				} else {
					result = await this.store.updateArticle(this.articleId, data)
				}

				if (result && result.id) {
					showSuccess(
						status === 'gepubliceerd'
							? t('pipelinq', 'Article published')
							: t('pipelinq', 'Draft saved'),
					)
					this.$router.push({ name: 'KennisbankDetail', params: { id: result.id } })
				}
			} catch (error) {
				showError(t('pipelinq', 'Failed to save article'))
			} finally {
				this.saving = false
			}
		},
		goBack() {
			if (this.isNew) {
				this.$router.push({ name: 'Kennisbank' })
			} else {
				this.$router.push({ name: 'KennisbankDetail', params: { id: this.articleId } })
			}
		},
		checkDuplicate(title) {
			clearTimeout(duplicateCheckTimeout)
			duplicateCheckTimeout = setTimeout(async () => {
				if (title && title.trim().length > 3) {
					const duplicate = await this.store.checkDuplicateTitle(title, this.articleId)
					this.duplicateWarning = duplicate || null
				} else {
					this.duplicateWarning = null
				}
			}, 500)
		},
		goToDuplicate() {
			if (this.duplicateWarning && this.duplicateWarning.id) {
				this.$router.push({ name: 'KennisbankDetail', params: { id: this.duplicateWarning.id } })
			}
		},
	},
}
</script>

<style scoped>
.article-editor {
	padding: 20px;
	max-width: 1200px;
	margin: 0 auto;
}

.article-editor__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.article-editor__actions {
	display: flex;
	gap: 8px;
}

.article-editor__warning {
	background: var(--color-warning);
	color: #000;
	padding: 8px 16px;
	border-radius: var(--border-radius);
	margin-bottom: 16px;
	display: flex;
	align-items: center;
	gap: 12px;
}

.article-editor__form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.form-row--split {
	display: flex;
	gap: 16px;
}

.form-col {
	flex: 1;
}

.form-col label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
	font-size: 0.9em;
}

.form-select {
	width: 100%;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
}

.article-editor__body {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	min-height: 400px;
}

.editor-pane label,
.preview-pane label {
	display: block;
	margin-bottom: 4px;
	font-weight: 600;
	font-size: 0.9em;
}

.markdown-editor {
	width: 100%;
	height: calc(100% - 24px);
	min-height: 400px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: monospace;
	font-size: 0.9em;
	resize: vertical;
}

.markdown-preview {
	height: calc(100% - 24px);
	min-height: 400px;
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	overflow-y: auto;
	line-height: 1.7;
}
</style>

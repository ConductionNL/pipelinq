<template>
	<div class="article-editor">
		<div class="article-editor__header">
			<h2>{{ isNew ? t('pipelinq', 'New Article') : t('pipelinq', 'Edit Article') }}</h2>
			<div class="article-editor__actions">
				<NcButton type="tertiary" @click="$router.back()">
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

		<div class="article-editor__form">
			<div class="form-row">
				<NcTextField
					:value.sync="form.title"
					:label="t('pipelinq', 'Title')"
					:required="true"
					:error="errors.title" />
			</div>

			<div class="form-row">
				<NcTextField
					:value.sync="form.summary"
					:label="t('pipelinq', 'Summary (shown in search results)')"
					:maxlength="500" />
			</div>

			<div class="form-row form-row--split">
				<div class="form-col">
					<label>{{ t('pipelinq', 'Visibility') }}</label>
					<select v-model="form.visibility" class="form-select">
						<option value="intern">{{ t('pipelinq', 'Internal (agents only)') }}</option>
						<option value="openbaar">{{ t('pipelinq', 'Public (citizen-facing)') }}</option>
					</select>
				</div>
				<div class="form-col">
					<label>{{ t('pipelinq', 'Tags (comma-separated)') }}</label>
					<NcTextField
						:value.sync="tagsInput"
						:label="t('pipelinq', 'e.g. paspoort, burgerzaken')" />
				</div>
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
				visibility: 'intern',
				categories: [],
				tags: [],
				status: 'concept',
			},
			tagsInput: '',
			saving: false,
			errors: {},
		}
	},
	computed: {
		isNew() {
			return !this.articleId
		},
		isValid() {
			return this.form.title.trim() !== '' && this.form.body.trim() !== ''
		},
		renderedPreview() {
			if (!this.form.body) return ''
			return this.form.body
				.replace(/^### (.*$)/gim, '<h3>$1</h3>')
				.replace(/^## (.*$)/gim, '<h2>$1</h2>')
				.replace(/^# (.*$)/gim, '<h1>$1</h1>')
				.replace(/\*\*(.*?)\*\*/gim, '<strong>$1</strong>')
				.replace(/\*(.*?)\*/gim, '<em>$1</em>')
				.replace(/\n/gim, '<br>')
		},
	},
	watch: {
		tagsInput(val) {
			this.form.tags = val.split(',').map(t => t.trim()).filter(t => t !== '')
		},
	},
	mounted() {
		if (this.articleId) {
			this.loadArticle()
		}
	},
	methods: {
		async loadArticle() {
			// Load article from OpenRegister for editing
		},
		async save(status) {
			if (!this.isValid) return

			this.saving = true
			this.form.status = status

			try {
				if (status === 'gepubliceerd') {
					this.form.publishedAt = new Date().toISOString()
				}

				// Save via OpenRegister objectStore
				showSuccess(
					status === 'gepubliceerd'
						? t('pipelinq', 'Article published')
						: t('pipelinq', 'Draft saved'),
				)
				this.$router.push({ name: 'Kennisbank' })
			} catch (error) {
				showError(t('pipelinq', 'Failed to save article'))
			} finally {
				this.saving = false
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

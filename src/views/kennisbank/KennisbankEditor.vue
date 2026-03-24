<template>
	<div class="kennisbank-editor" style="padding:20px">
		<div style="display:flex;align-items:center;gap:16px;margin-bottom:16px">
			<NcButton type="tertiary" @click="goBack">
				<template #icon>
					<ArrowLeft :size="20" />
				</template>{{ t('pipelinq', 'Back') }}
			</NcButton>
			<h2 style="flex:1;margin:0">
				{{ isNew ? t('pipelinq', 'New Article') : t('pipelinq', 'Edit Article') }}
			</h2>
			<NcButton :disabled="saving" @click="save('concept')">
				{{ t('pipelinq', 'Save Draft') }}
			</NcButton>
			<NcButton type="primary" :disabled="saving || !form.title" @click="save('gepubliceerd')">
				{{ t('pipelinq', 'Publish') }}
			</NcButton>
		</div>
		<div style="margin-bottom:12px">
			<NcTextField :value.sync="form.title" :label="t('pipelinq', 'Title')" />
		</div>
		<div style="margin-bottom:12px">
			<NcTextField :value.sync="form.summary" :label="t('pipelinq', 'Summary')" />
		</div>
		<div style="margin-bottom:12px">
			<NcTextField :value.sync="tagsInput" :label="t('pipelinq', 'Tags (comma separated)')" />
		</div>
		<div style="display:grid;grid-template-columns:1fr 1fr;min-height:500px;border:1px solid var(--color-border);border-radius:var(--border-radius)">
			<textarea v-model="form.body" style="padding:16px;border:none;border-right:1px solid var(--color-border);resize:none;font-family:monospace;font-size:14px;min-height:500px" :placeholder="t('pipelinq', 'Write Markdown...')" />
			<div style="padding:16px;overflow-y:auto;line-height:1.6" v-html="preview" />
		</div>
	</div>
</template>
<script>
import { NcButton, NcTextField } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import MarkdownIt from 'markdown-it'
import { useKennisbankStore } from '../../store/modules/kennisbank.js'
const md = new MarkdownIt({ html: false, linkify: true })
export default {
	name: 'KennisbankEditor',
	components: { NcButton, NcTextField, ArrowLeft },
	props: { articleId: { type: String, default: 'new' } },
	data() { return { form: { title: '', body: '', summary: '', category: null, visibility: 'intern', tags: [] }, tagsInput: '', saving: false } },
	computed: {
		store() { return useKennisbankStore() },
		isNew() { return this.articleId === 'new' },
		preview() { return this.form.body ? md.render(this.form.body) : '<p style="color:var(--color-text-maxcontrast)">Preview</p>' },
	},
	watch: { tagsInput(v) { this.form.tags = v.split(',').map(t => t.trim()).filter(Boolean) } },
	async created() {
		if (!this.isNew) {
			const a = await this.store.fetchArticle(this.articleId)
			if (a) { this.form = { title: a.title || '', body: a.body || '', summary: a.summary || '', category: a.category || null, visibility: a.visibility || 'intern', tags: a.tags || [] }; this.tagsInput = (a.tags || []).join(', ') }
		}
	},
	methods: {
		goBack() { this.isNew ? this.$router.push({ name: 'Kennisbank' }) : this.$router.push({ name: 'KennisbankDetail', params: { id: this.articleId } }) },
		async save(status) {
			if (!this.form.title) return; this.saving = true
			try {
				const data = { ...this.form, status }
				const r = this.isNew ? await this.store.createArticle(data) : await this.store.updateArticle(this.articleId, data)
				if (r?.id) this.$router.push({ name: 'KennisbankDetail', params: { id: r.id } })
			} finally { this.saving = false }
		},
	},
}
</script>

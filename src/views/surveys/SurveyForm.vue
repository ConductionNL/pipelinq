<template>
	<div class="survey-form">
		<h2>{{ surveyId ? t('pipelinq', 'Edit Survey') : t('pipelinq', 'New Survey') }}</h2>
		<div class="field"><label>{{ t('pipelinq', 'Title') }} *</label><input v-model="form.title" type="text" required /></div>
		<div class="field"><label>{{ t('pipelinq', 'Description') }}</label><textarea v-model="form.description" rows="3" /></div>
		<div class="field"><label>{{ t('pipelinq', 'Status') }}</label>
			<select v-model="form.status"><option value="draft">{{ t('pipelinq', 'Draft') }}</option><option value="active">{{ t('pipelinq', 'Active') }}</option><option value="closed">{{ t('pipelinq', 'Closed') }}</option></select>
		</div>
		<QuestionEditor v-model="form.questions" />
		<div class="actions">
			<NcButton @click="$router.back()">{{ t('pipelinq', 'Cancel') }}</NcButton>
			<NcButton type="primary" :disabled="!form.title || saving" @click="save">{{ saving ? '...' : t('pipelinq', 'Save') }}</NcButton>
		</div>
	</div>
</template>
<script>
import { NcButton } from '@nextcloud/vue'
import { useSurveyStore } from '../../store/modules/survey.js'
import QuestionEditor from '../../components/surveys/QuestionEditor.vue'
function uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16) }) }
export default {
	name: 'SurveyForm',
	components: { NcButton, QuestionEditor },
	props: { surveyId: { type: String, default: null } },
	data() { return { saving: false, form: { title: '', description: '', questions: [], status: 'draft', token: uuid() } } },
	computed: { store() { return useSurveyStore() } },
	async mounted() { if (this.surveyId) { await this.store.fetchSurvey(this.surveyId); const s = this.store.currentSurvey; if (s) this.form = { title: s.title || '', description: s.description || '', questions: s.questions || [], status: s.status || 'draft', token: s.token || uuid() } } },
	methods: {
		async save() {
			this.saving = true
			try {
				const data = { ...this.form, updatedAt: new Date().toISOString(), createdBy: OC.currentUser }
				if (!this.surveyId) data.createdAt = new Date().toISOString()
				const result = this.surveyId ? await this.store.updateSurvey(this.surveyId, data) : await this.store.createSurvey(data)
				this.$router.push({ name: 'SurveyDetail', params: { id: result.id } })
			} finally { this.saving = false }
		},
	},
}
</script>
<style scoped>
.survey-form { padding: 20px; max-width: 800px; }
.field { margin-bottom: 16px; }
.field label { display: block; font-weight: 600; margin-bottom: 4px; }
.field input, .field textarea, .field select { width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px; }
.actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
</style>

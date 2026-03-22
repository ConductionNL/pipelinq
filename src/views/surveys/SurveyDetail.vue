<template>
	<div class="survey-detail">
		<NcLoadingIcon v-if="loading" />
		<template v-else-if="survey">
			<h2>{{ survey.title }} <span class="badge">{{ survey.status }}</span></h2>
			<p v-if="survey.description">{{ survey.description }}</p>
			<div v-if="survey.status === 'active' && survey.token" class="link-box">
				<strong>{{ t('pipelinq', 'Public link') }}:</strong>
				<code>{{ publicUrl }}</code>
				<NcButton @click="copyLink">{{ t('pipelinq', 'Copy') }}</NcButton>
			</div>
			<div class="metrics">
				<span>{{ t('pipelinq', 'Responses') }}: {{ store.responseCount }}</span>
				<span v-if="store.npsScore !== null">NPS: {{ store.npsScore }}</span>
				<span v-if="store.satisfactionAverage !== null">{{ t('pipelinq', 'Rating') }}: {{ store.satisfactionAverage }}/5</span>
			</div>
			<h3>{{ t('pipelinq', 'Questions') }} ({{ (survey.questions || []).length }})</h3>
			<ol><li v-for="q in survey.questions || []" :key="q.id">{{ q.text }} <em>({{ q.type }})</em></li></ol>
			<div class="actions">
				<NcButton @click="$router.push({ name: 'SurveyAnalytics', params: { id: surveyId } })">{{ t('pipelinq', 'Analytics') }}</NcButton>
				<NcButton @click="$router.push({ name: 'SurveyEdit', params: { id: surveyId } })">{{ t('pipelinq', 'Edit') }}</NcButton>
				<NcButton type="error" @click="del">{{ t('pipelinq', 'Delete') }}</NcButton>
			</div>
		</template>
	</div>
</template>
<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import { useSurveyStore } from '../../store/modules/survey.js'
export default {
	name: 'SurveyDetail',
	components: { NcButton, NcLoadingIcon },
	props: { surveyId: { type: String, required: true } },
	data() { return { loading: false } },
	computed: {
		store() { return useSurveyStore() },
		survey() { return this.store.currentSurvey },
		publicUrl() { return this.survey?.token ? window.location.origin + generateUrl('/apps/pipelinq/public/survey/' + this.survey.token) : '' },
	},
	async mounted() { this.loading = true; try { await this.store.fetchSurvey(this.surveyId); if (this.survey) await this.store.fetchResponses(this.surveyId, { _limit: 500 }) } finally { this.loading = false } },
	methods: {
		copyLink() { navigator.clipboard.writeText(this.publicUrl) },
		async del() { if (confirm(t('pipelinq', 'Delete this survey and all responses?'))) { await this.store.deleteSurvey(this.surveyId); this.$router.push({ name: 'Surveys' }) } },
	},
}
</script>
<style scoped>
.survey-detail { padding: 20px; max-width: 900px; }
.badge { font-size: 12px; padding: 2px 8px; border-radius: 10px; background: var(--color-background-dark); }
.link-box { padding: 12px; background: var(--color-background-dark); border-radius: 8px; margin: 12px 0; display: flex; gap: 8px; align-items: center; }
.link-box code { flex: 1; font-size: 12px; }
.metrics { display: flex; gap: 20px; margin: 16px 0; font-weight: 600; }
.actions { display: flex; gap: 8px; margin-top: 20px; }
</style>

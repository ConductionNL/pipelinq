<template>
	<div class="analytics">
		<NcLoadingIcon v-if="loading" />
		<template v-else-if="survey">
			<h2>{{ survey.title }} — {{ t('pipelinq', 'Analytics') }}</h2>
			<div class="metrics">
				<span>{{ t('pipelinq', 'Responses') }}: {{ store.responseCount }}</span><span v-if="store.npsScore !== null">NPS: {{ store.npsScore }}</span><span v-if="store.satisfactionAverage !== null">{{ t('pipelinq', 'Avg Rating') }}: {{ store.satisfactionAverage }}/5</span>
			</div>
			<NcButton @click="exportCsv">
				{{ t('pipelinq', 'Export CSV') }}
			</NcButton>
			<div v-for="q in survey.questions || []" :key="q.id" class="q-result">
				<h4>{{ q.text }} ({{ q.type }})</h4>
			</div>
		</template>
	</div>
</template>
<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useSurveyStore } from '../../store/modules/survey.js'
export default {
	name: 'SurveyAnalytics',
	components: { NcButton, NcLoadingIcon },
	props: { surveyId: { type: String, required: true } },
	data() { return { loading: false } },
	computed: { store() { return useSurveyStore() }, survey() { return this.store.currentSurvey } },
	async mounted() { this.loading = true; try { await this.store.fetchSurvey(this.surveyId); if (this.survey) await this.store.fetchResponses(this.surveyId, { _limit: 1000 }) } finally { this.loading = false } },
	methods: {
		exportCsv() {
			if (!this.survey || !this.store.responses.length) return
			const qs = this.survey.questions || []; const hdr = ['ID', 'Date', ...qs.map(q => q.text)]
			const rows = this.store.responses.map(r => { const m = {}; (r.answers || []).forEach(a => { m[a.questionId] = a.value }); return [r.id, r.completedAt, ...qs.map(q => m[q.id] || '')] })
			const csv = [hdr, ...rows].map(r => r.map(c => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\n')
			const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = this.survey.title + '-responses.csv'; a.click()
		},
	},
}
</script>
<style scoped>
.analytics { padding: 20px; max-width: 900px; }
.metrics { display: flex; gap: 20px; margin: 16px 0; font-weight: 600; }
.q-result { margin: 12px 0; padding: 12px; border: 1px solid var(--color-border); border-radius: 8px; }
</style>

<template>
	<div class="survey-list">
		<div class="survey-list__header">
			<h2>{{ t('pipelinq', 'Surveys') }}</h2>
			<NcButton type="primary" @click="$router.push({ name: 'SurveyCreate' })">
				{{ t('pipelinq', 'New Survey') }}
			</NcButton>
		</div>
		<NcLoadingIcon v-if="surveyStore.surveyLoading" />
		<table v-else-if="surveyStore.surveys.length" class="survey-table">
			<thead><tr><th>{{ t('pipelinq', 'Title') }}</th><th>{{ t('pipelinq', 'Status') }}</th><th>{{ t('pipelinq', 'Created') }}</th></tr></thead>
			<tbody>
				<tr v-for="s in surveyStore.surveys" :key="s.id" @click="$router.push({ name: 'SurveyDetail', params: { id: s.id } })">
					<td>{{ s.title }}</td><td>{{ s.status || 'draft' }}</td><td>{{ s.createdAt ? new Date(s.createdAt).toLocaleDateString('nl-NL') : '-' }}</td>
				</tr>
			</tbody>
		</table>
		<p v-else class="empty">
			{{ t('pipelinq', 'No surveys yet. Create your first KTO survey.') }}
		</p>
	</div>
</template>
<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { useSurveyStore } from '../../store/modules/survey.js'
export default {
	name: 'SurveyList',
	components: { NcButton, NcLoadingIcon },
	computed: { surveyStore() { return useSurveyStore() } },
	mounted() { this.surveyStore.fetchSurveys({ _limit: 200 }) },
}
</script>
<style scoped>
.survey-list { padding: 20px; }

.survey-list__header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }

.survey-table { width: 100%; border-collapse: collapse; }

.survey-table th, .survey-table td { padding: 10px 12px; border-bottom: 1px solid var(--color-border); text-align: left; }

.survey-table tr:hover { background: var(--color-background-hover); cursor: pointer; }

.empty { text-align: center; padding: 40px; color: var(--color-text-maxcontrast); }
</style>

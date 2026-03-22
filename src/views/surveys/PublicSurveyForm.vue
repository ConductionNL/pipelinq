<template>
	<div class="public-survey">
		<NcLoadingIcon v-if="loading" />
		<div v-else-if="error" class="msg">{{ error }}</div>
		<div v-else-if="submitted" class="msg success"><h2>{{ t('pipelinq', 'Thank you for your feedback!') }}</h2></div>
		<template v-else-if="survey">
			<h1>{{ survey.title }}</h1>
			<p v-if="survey.description">{{ survey.description }}</p>
			<form @submit.prevent="submit">
				<div v-for="q in survey.questions || []" :key="q.id" class="question">
					<label>{{ q.text }} <span v-if="q.required !== false" class="req">*</span></label>
					<div v-if="q.type === 'nps'" class="nps-row"><button v-for="n in 11" :key="n" type="button" :class="{ sel: answers[q.id] === (n-1) }" @click="$set(answers, q.id, n-1)">{{ n-1 }}</button></div>
					<div v-else-if="q.type === 'rating'" class="stars"><button v-for="n in 5" :key="n" type="button" :class="{ filled: n <= (answers[q.id] || 0) }" @click="$set(answers, q.id, n)">&#9733;</button></div>
					<div v-else-if="q.type === 'multiple_choice'"><label v-for="o in q.options || []" :key="o" class="opt"><input type="radio" :name="'q'+q.id" :value="o" @change="$set(answers, q.id, o)" /> {{ o }}</label></div>
					<div v-else-if="q.type === 'yes_no'" class="yn"><label><input type="radio" :name="'q'+q.id" value="yes" @change="$set(answers, q.id, 'yes')" /> {{ t('pipelinq', 'Yes') }}</label><label><input type="radio" :name="'q'+q.id" value="no" @change="$set(answers, q.id, 'no')" /> {{ t('pipelinq', 'No') }}</label></div>
					<textarea v-else :placeholder="t('pipelinq', 'Your answer...')" rows="3" @input="$set(answers, q.id, $event.target.value)" />
				</div>
				<p v-if="err" class="error">{{ err }}</p>
				<button type="submit" class="submit-btn" :disabled="submitting">{{ t('pipelinq', 'Submit') }}</button>
			</form>
		</template>
	</div>
</template>
<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
export default {
	name: 'PublicSurveyForm',
	components: { NcLoadingIcon },
	props: { token: { type: String, required: true } },
	data() { return { survey: null, loading: true, error: null, submitted: false, submitting: false, err: null, answers: {} } },
	async mounted() {
		try {
			const r = await fetch(generateUrl('/apps/pipelinq/public/survey/' + this.token))
			if (r.status === 404) { this.error = t('pipelinq', 'Survey not found'); return }
			if (r.status === 410) { this.error = t('pipelinq', 'This survey is no longer accepting responses'); return }
			if (!r.ok) { this.error = t('pipelinq', 'Failed to load survey'); return }
			this.survey = await r.json()
		} catch { this.error = t('pipelinq', 'Failed to load survey') } finally { this.loading = false }
	},
	methods: {
		async submit() {
			const qs = this.survey.questions || []; const missing = qs.filter(q => q.required !== false && !this.answers[q.id])
			if (missing.length) { this.err = t('pipelinq', 'Please answer all required questions'); return }
			this.submitting = true; this.err = null
			try {
				const params = new URLSearchParams(window.location.search)
				const body = { answers: Object.entries(this.answers).map(([qId, v]) => ({ questionId: qId, value: String(v) })), entityType: params.get('entity'), entityId: params.get('id') }
				const r = await fetch(generateUrl('/apps/pipelinq/public/survey/' + this.token + '/respond'), { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })
				if (!r.ok) throw new Error((await r.json().catch(() => ({}))).error || 'Failed')
				this.submitted = true
			} catch (e) { this.err = e.message } finally { this.submitting = false }
		},
	},
}
</script>
<style scoped>
.public-survey { max-width: 700px; margin: 0 auto; padding: 32px 20px; }
.msg { text-align: center; padding: 40px; }
.success h2 { color: var(--color-success); }
.question { margin-bottom: 24px; }
.question label { font-weight: 600; display: block; margin-bottom: 8px; }
.req { color: var(--color-error); }
.nps-row button { width: 36px; height: 36px; border: 2px solid var(--color-border); border-radius: 6px; background: white; cursor: pointer; margin: 2px; }
.nps-row button.sel { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.stars button { font-size: 28px; background: none; border: none; cursor: pointer; color: var(--color-border); }
.stars button.filled { color: #f59e0b; }
.opt { display: block; padding: 4px 0; cursor: pointer; }
.yn { display: flex; gap: 20px; }
.question textarea { width: 100%; padding: 8px; border: 1px solid var(--color-border); border-radius: 4px; }
.error { color: var(--color-error); }
.submit-btn { background: var(--color-primary); color: white; border: none; padding: 10px 24px; border-radius: 20px; font-size: 15px; cursor: pointer; font-weight: 600; }
.submit-btn:disabled { opacity: 0.6; }
</style>

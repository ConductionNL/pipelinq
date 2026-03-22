import { defineStore } from 'pinia'
import { useObjectStore } from './object.js'

export const useSurveyStore = defineStore('survey', {
	state: () => ({ surveys: [], currentSurvey: null, responses: [], surveyLoading: false, responsesLoading: false }),
	getters: {
		npsScore(state) {
			if (!state.currentSurvey || !state.responses.length) return null
			const npsIds = (state.currentSurvey.questions || []).filter(q => q.type === 'nps').map(q => q.id)
			if (!npsIds.length) return null
			const vals = []
			for (const r of state.responses) { for (const a of (r.answers || [])) { if (npsIds.includes(a.questionId)) { const v = Number(a.value); if (!isNaN(v) && v >= 0 && v <= 10) vals.push(v) } } }
			if (!vals.length) return null
			return Math.round(((vals.filter(v => v >= 9).length - vals.filter(v => v <= 6).length) / vals.length) * 100)
		},
		satisfactionAverage(state) {
			if (!state.currentSurvey || !state.responses.length) return null
			const rIds = (state.currentSurvey.questions || []).filter(q => q.type === 'rating').map(q => q.id)
			if (!rIds.length) return null
			const vals = []
			for (const r of state.responses) { for (const a of (r.answers || [])) { if (rIds.includes(a.questionId)) { const v = Number(a.value); if (!isNaN(v) && v >= 1 && v <= 5) vals.push(v) } } }
			if (!vals.length) return null
			return Math.round((vals.reduce((s, v) => s + v, 0) / vals.length) * 10) / 10
		},
		responseCount(state) { return state.responses.length },
		completionRate(state) {
			if (!state.currentSurvey || !state.responses.length) return null
			const reqIds = (state.currentSurvey.questions || []).filter(q => q.required !== false).map(q => q.id)
			if (!reqIds.length) return 100
			let ok = 0
			for (const r of state.responses) { const ids = (r.answers || []).map(a => a.questionId); if (reqIds.every(id => ids.includes(id))) ok++ }
			return Math.round((ok / state.responses.length) * 100)
		},
	},
	actions: {
		async _fetch(type, params = {}) {
			const cfg = useObjectStore().objectTypeRegistry[type]
			if (!cfg) return []
			const qp = new URLSearchParams()
			Object.entries(params).forEach(([k, v]) => { if (v != null && v !== '') qp.set(k, v) })
			const url = '/apps/openregister/api/objects/' + cfg.register + '/' + cfg.schema + (qp.toString() ? '?' + qp : '')
			const r = await fetch(url, { headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } })
			if (!r.ok) throw new Error('Failed to fetch ' + type)
			const d = await r.json()
			return d.results || d || []
		},
		async fetchSurveys(params = {}) { this.surveyLoading = true; try { this.surveys = await this._fetch('survey', params); return this.surveys } finally { this.surveyLoading = false } },
		async fetchSurvey(id) { this.surveyLoading = true; try { const cfg = useObjectStore().objectTypeRegistry.survey; const r = await fetch('/apps/openregister/api/objects/' + cfg.register + '/' + cfg.schema + '/' + id, { headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } }); if (!r.ok) throw new Error('Fetch failed'); this.currentSurvey = await r.json(); return this.currentSurvey } finally { this.surveyLoading = false } },
		async createSurvey(data) { const cfg = useObjectStore().objectTypeRegistry.survey; const r = await fetch('/apps/openregister/api/objects/' + cfg.register + '/' + cfg.schema, { method: 'POST', headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' }, body: JSON.stringify(data) }); if (!r.ok) throw new Error('Create failed'); const c = await r.json(); this.surveys.unshift(c); return c },
		async updateSurvey(id, data) { const cfg = useObjectStore().objectTypeRegistry.survey; const r = await fetch('/apps/openregister/api/objects/' + cfg.register + '/' + cfg.schema + '/' + id, { method: 'PUT', headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' }, body: JSON.stringify(data) }); if (!r.ok) throw new Error('Update failed'); const u = await r.json(); const i = this.surveys.findIndex(s => s.id === id); if (i !== -1) this.surveys.splice(i, 1, u); if (this.currentSurvey?.id === id) this.currentSurvey = u; return u },
		async deleteSurvey(id) { const cfg = useObjectStore().objectTypeRegistry.survey; await fetch('/apps/openregister/api/objects/' + cfg.register + '/' + cfg.schema + '/' + id, { method: 'DELETE', headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' } }); this.surveys = this.surveys.filter(s => s.id !== id); if (this.currentSurvey?.id === id) this.currentSurvey = null },
		async fetchResponses(surveyId, params = {}) { this.responsesLoading = true; try { this.responses = await this._fetch('surveyResponse', { surveyId, ...params }); return this.responses } finally { this.responsesLoading = false } },
		async submitPublicResponse(token, data) { const r = await fetch('/apps/pipelinq/public/survey/' + token + '/respond', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) }); if (!r.ok) { const e = await r.json().catch(() => ({})); throw new Error(e.error || 'Submit failed') } return r.json() },
	},
})

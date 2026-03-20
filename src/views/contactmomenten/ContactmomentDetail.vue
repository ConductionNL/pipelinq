<template>
	<div class="contactmoment-detail">
		<NcLoadingIcon v-if="loading" />

		<template v-else-if="cm">
			<div class="contactmoment-detail__header">
				<router-link :to="{ name: 'Contactmomenten' }">
					{{ t('pipelinq', 'Back to Contact Moments') }}
				</router-link>
			</div>

			<h2>{{ cm.onderwerp }}</h2>

			<div class="contactmoment-detail__badges">
				<span class="channel-badge" :class="'channel-badge--' + cm.kanaal">
					{{ cm.kanaal }}
				</span>
				<span v-if="cm.resultaat" class="result-badge">
					{{ cm.resultaat }}
				</span>
				<span class="initiator-badge">
					{{ cm.initiatiefnemer === 'klant' ? t('pipelinq', 'Client initiated') : t('pipelinq', 'Agent initiated') }}
				</span>
			</div>

			<div class="contactmoment-detail__info">
				<div class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Date') }}</span>
					<span>{{ formatDate(cm.timestamp) }}</span>
				</div>
				<div class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Agent') }}</span>
					<span>{{ cm.agent }}</span>
				</div>
				<div v-if="cm.client" class="info-row">
					<span class="info-label">{{ t('pipelinq', 'Client') }}</span>
					<span>{{ cm.client }}</span>
				</div>
			</div>

			<div v-if="cm.toelichting" class="contactmoment-detail__notes">
				<h3>{{ t('pipelinq', 'Notes') }}</h3>
				<p>{{ cm.toelichting }}</p>
			</div>

			<div v-if="cm.metadata && Object.keys(cm.metadata).length > 0" class="contactmoment-detail__metadata">
				<h3>{{ t('pipelinq', 'Channel Details') }}</h3>
				<div
					v-for="(value, key) in cm.metadata"
					:key="key"
					class="info-row">
					<span class="info-label">{{ key }}</span>
					<span>{{ value }}</span>
				</div>
			</div>

			<div class="contactmoment-detail__actions">
				<NcButton
					v-if="cm.resultaat === 'terugbelverzoek'"
					type="primary"
					@click="createCallback">
					{{ t('pipelinq', 'Create callback task') }}
				</NcButton>
				<NcButton type="secondary" @click="createRequest">
					{{ t('pipelinq', 'Create request') }}
				</NcButton>
			</div>
		</template>

		<NcEmptyContent v-else :name="t('pipelinq', 'Contact moment not found')" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent } from '@nextcloud/vue'

export default {
	name: 'ContactmomentDetail',
	components: { NcButton, NcLoadingIcon, NcEmptyContent },
	props: {
		contactmomentId: { type: String, required: true },
	},
	data() {
		return { cm: null, loading: true }
	},
	mounted() { this.fetchData() },
	methods: {
		async fetchData() {
			this.loading = true
			try { this.cm = null } finally { this.loading = false }
		},
		formatDate(dateStr) {
			if (!dateStr) return ''
			return new Date(dateStr).toLocaleString('nl-NL', {
				day: '2-digit', month: '2-digit', year: 'numeric',
				hour: '2-digit', minute: '2-digit',
			})
		},
		createCallback() {
			this.$router.push({ name: 'TaskNew' })
		},
		createRequest() {
			this.$router.push({ name: 'Requests' })
		},
	},
}
</script>

<style scoped>
.contactmoment-detail { padding: 20px; max-width: 800px; margin: 0 auto; }
.contactmoment-detail__header { margin-bottom: 12px; }
.contactmoment-detail__badges { display: flex; gap: 8px; margin-bottom: 16px; }
.contactmoment-detail__info { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; }
.info-row { display: flex; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--color-border); }
.info-label { font-weight: 600; min-width: 120px; color: var(--color-text-lighter); }
.contactmoment-detail__notes { margin-bottom: 20px; }
.contactmoment-detail__notes p { background: var(--color-background-dark); padding: 12px; border-radius: var(--border-radius); }
.contactmoment-detail__metadata { margin-bottom: 20px; }
.contactmoment-detail__actions { display: flex; gap: 8px; margin-top: 20px; }
.channel-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; font-weight: 600; text-transform: capitalize; }
.channel-badge--telefoon { background: #bee3f8; color: #2a4365; }
.channel-badge--email { background: #c6f6d5; color: #22543d; }
.channel-badge--balie { background: #fefcbf; color: #744210; }
.channel-badge--chat { background: #e9d8fd; color: #44337a; }
.channel-badge--social { background: #fed7e2; color: #702459; }
.channel-badge--brief { background: #e2e8f0; color: #2d3748; }
.result-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; background: var(--color-background-dark); }
.initiator-badge { padding: 2px 8px; border-radius: var(--border-radius); font-size: 0.75em; background: var(--color-background-dark); }
</style>

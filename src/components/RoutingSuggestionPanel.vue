<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="routing-panel" role="region" :aria-label="t('pipelinq', 'Suggested agents')">
		<div class="routing-panel__header">
			<h4>{{ t('pipelinq', 'Suggested agents') }}</h4>
			<NcButton v-if="!loading"
				:aria-label="t('pipelinq', 'Refresh')"
				@click="loadSuggestions">
				<template #icon>
					<Refresh :size="16" />
				</template>
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="errorMessage" class="routing-panel__error" role="alert">
			{{ errorMessage }}
		</div>

		<div v-else-if="suggestions.length === 0" class="routing-panel__empty">
			<p>{{ t('pipelinq', 'No agents with matching skills') }}</p>
		</div>

		<div v-else class="routing-panel__list">
			<div
				v-for="suggestion in suggestions"
				:key="suggestion.userId"
				class="agent-suggestion">
				<div class="agent-suggestion__info">
					<span class="agent-name">{{ suggestion.displayName || suggestion.userId }}</span>
					<span class="agent-workload" :title="workloadTitle(suggestion)">
						<span aria-hidden="true">{{ workloadIcon(suggestion) }}</span>
						{{ suggestion.workload }}/{{ suggestion.maxConcurrent || 10 }}
						{{ t('pipelinq', 'items') }}
					</span>
					<div v-if="suggestion.matchedSkill" class="agent-skills">
						<span class="skill-tag">{{ suggestion.matchedSkill }}</span>
					</div>
				</div>
				<NcButton
					:aria-label="t('pipelinq', 'Assign to {name}', { name: suggestion.displayName || suggestion.userId })"
					@click="assign(suggestion)">
					{{ t('pipelinq', 'Assign') }}
				</NcButton>
			</div>
		</div>

		<div v-if="atCapacityCount > 0" class="routing-panel__note">
			{{ atCapacityCount }} {{ t('pipelinq', 'matching agent(s) at capacity') }}
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'

export default {
	name: 'RoutingSuggestionPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		Refresh,
	},
	props: {
		requestId: {
			type: String,
			required: true,
		},
		category: {
			type: String,
			default: '',
		},
		entityType: {
			type: String,
			default: 'request',
		},
	},
	emits: ['assigned'],
	data() {
		return {
			loading: false,
			suggestions: [],
			atCapacityCount: 0,
			errorMessage: '',
		}
	},
	watch: {
		requestId() {
			this.loadSuggestions()
		},
		category() {
			this.loadSuggestions()
		},
	},
	mounted() {
		this.loadSuggestions()
	},
	methods: {
		async loadSuggestions() {
			this.loading = true
			this.suggestions = []
			this.atCapacityCount = 0
			this.errorMessage = ''

			try {
				const url = generateUrl('/apps/pipelinq/api/routing/suggestions')
				const response = await axios.get(url, {
					params: {
						entityType: this.entityType,
						entityId: this.requestId,
					},
				})
				const data = response.data || {}
				this.suggestions = Array.isArray(data.suggestions) ? data.suggestions : []
				this.atCapacityCount = Number(data.atCapacity || 0)
			} catch (error) {
				console.error('Error loading routing suggestions:', error)
				this.errorMessage = this.t('pipelinq', 'Failed to load suggestions')
			} finally {
				this.loading = false
			}
		},

		async assign(suggestion) {
			try {
				this.$emit('assigned', suggestion.userId)
			} catch (error) {
				console.error('Error assigning agent:', error)
				this.errorMessage = this.t('pipelinq', 'Failed to load suggestions')
			}
		},

		workloadIcon(suggestion) {
			// Non-colour indicator for WCAG AA compliance.
			const max = suggestion.maxConcurrent || 10
			const ratio = (suggestion.workload || 0) / max
			if (ratio >= 0.8) return '!!'
			if (ratio >= 0.5) return '!'
			return '='
		},

		workloadTitle(suggestion) {
			return this.t('pipelinq', '{n} of {max} items', {
				n: suggestion.workload || 0,
				max: suggestion.maxConcurrent || 10,
			})
		},
	},
}
</script>

<style scoped>
.routing-panel {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	background: var(--color-background-hover);
}

.routing-panel__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 8px;
}

.routing-panel__header h4 {
	margin: 0;
	font-size: 14px;
	font-weight: 700;
}

.routing-panel__empty,
.routing-panel__error {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	padding: 8px 0;
}

.routing-panel__error {
	color: var(--color-error);
}

.routing-panel__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.agent-suggestion {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
	padding: 8px 12px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.agent-suggestion__info {
	flex: 1;
	min-width: 0;
}

.agent-name {
	font-weight: 600;
	font-size: 14px;
}

.agent-workload {
	margin-left: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.agent-skills {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
	margin-top: 4px;
}

.skill-tag {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 8px;
	font-size: 10px;
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.routing-panel__note {
	margin-top: 8px;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>

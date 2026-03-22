<template>
	<div class="routing-panel">
		<div class="routing-panel__header">
			<h4>{{ t('pipelinq', 'Suggested agents') }}</h4>
			<NcButton v-if="!loading" :aria-label="t('pipelinq', 'Refresh')" @click="loadSuggestions">
				<template #icon>
					<Refresh :size="16" />
				</template>
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="suggestions.length === 0" class="routing-panel__empty">
			<p>{{ t('pipelinq', 'No agents with matching skills') }}</p>
		</div>

		<div v-else class="routing-panel__list">
			<div
				v-for="suggestion in suggestions"
				:key="suggestion.profile.userId"
				class="agent-suggestion">
				<div class="agent-suggestion__info">
					<span class="agent-name">{{ suggestion.profile.userId }}</span>
					<span class="agent-workload">
						{{ suggestion.workload }}/{{ suggestion.profile.maxConcurrent || 10 }}
						{{ t('pipelinq', 'items') }}
					</span>
					<div v-if="suggestion.matchingSkills.length" class="agent-skills">
						<span
							v-for="skill in suggestion.matchingSkills"
							:key="skill.id"
							class="skill-tag">
							{{ skill.title }}
						</span>
					</div>
				</div>
				<NcButton @click="$emit('assign', suggestion.profile.userId)">
					{{ t('pipelinq', 'Assign') }}
				</NcButton>
			</div>
		</div>

		<div v-if="atCapacityCount > 0" class="routing-panel__note">
			{{ t('pipelinq', '{count} matching agent(s) at capacity', { count: atCapacityCount }) }}
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { useSkillsStore } from '../store/modules/skills.js'
import { useAgentProfilesStore } from '../store/modules/agentProfiles.js'
import { findMatchingAgents, sortByWorkload, filterByCapacity } from '../services/queueUtils.js'

export default {
	name: 'RoutingSuggestionPanel',
	components: {
		NcButton,
		NcLoadingIcon,
		Refresh,
	},
	props: {
		category: {
			type: String,
			default: null,
		},
	},
	emits: ['assign'],
	data() {
		return {
			loading: false,
			suggestions: [],
			atCapacityCount: 0,
		}
	},
	mounted() {
		this.loadSuggestions()
	},
	watch: {
		category() {
			this.loadSuggestions()
		},
	},
	methods: {
		async loadSuggestions() {
			this.loading = true
			this.suggestions = []
			this.atCapacityCount = 0

			try {
				const skillsStore = useSkillsStore()
				const profilesStore = useAgentProfilesStore()

				await Promise.all([
					skillsStore.fetchSkills(),
					profilesStore.fetchProfiles(),
				])

				const matchingProfiles = findMatchingAgents(
					this.category,
					skillsStore.skills,
					profilesStore.profiles,
				)

				// Calculate workload for each matching agent
				const agentsWithWorkload = await Promise.all(
					matchingProfiles.map(async (profile) => {
						const workload = await profilesStore.getWorkload(profile.userId)
						const matchingSkills = this.getMatchingSkills(profile, skillsStore.skills)
						return { profile, workload, matchingSkills }
					}),
				)

				const { available, atCapacity } = filterByCapacity(agentsWithWorkload)
				this.suggestions = sortByWorkload(available)
				this.atCapacityCount = atCapacity
			} catch (error) {
				console.error('Error loading routing suggestions:', error)
			} finally {
				this.loading = false
			}
		},

		getMatchingSkills(profile, allSkills) {
			if (!profile.skills) return []
			return allSkills.filter(s => profile.skills.includes(s.id))
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

.routing-panel__empty {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	padding: 8px 0;
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

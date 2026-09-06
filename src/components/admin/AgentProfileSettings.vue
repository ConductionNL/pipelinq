<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Agent Profiles')"
		:description="
			t('pipelinq', 'Assign skills and configure routing for agents')
		">
		<NcLoadingIcon v-if="loading" />

		<div v-else class="agent-settings">
			<div v-for="profile in profiles" :key="profile.id" class="agent-item">
				<div v-if="editingId !== profile.id" class="agent-item__display">
					<div class="agent-item__info">
						<span class="agent-name">{{ profile.userId }}</span>
						<span
							v-if="profile.isAvailable === false"
							class="unavailable-tag"
							>{{ t('pipelinq', 'Unavailable') }}</span
						>
						<span class="agent-meta">
							{{
								getSkillNames(profile).join(', ')
								|| t('pipelinq', 'No skills')
							}}
							·
							{{
								t('pipelinq', 'max {n} items', {
									n: profile.maxConcurrent || 10,
								})
							}}
						</span>
					</div>
					<div class="agent-item__actions">
						<NcButton @click="startEdit(profile)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton variant="error" @click="deleteProfile(profile)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</div>
				</div>

				<div v-else class="agent-item__edit">
					<div class="edit-field">
						<label for="agent-edit-user-id">{{
							t('pipelinq', 'User ID')
						}}</label>
						<input
							id="agent-edit-user-id"
							v-model="editForm.userId"
							type="text"
							:disabled="!!editForm.id" />
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Skills') }}</label>
						<div class="skill-checkboxes">
							<label
								v-for="skill in allSkills"
								:key="skill.id"
								class="skill-checkbox">
								<input
									type="checkbox"
									:checked="
										(editForm.skills || []).includes(skill.id)
									"
									@change="
										toggleSkill(skill.id, $event.target.checked)
									" />
								{{ skill.title }}
							</label>
						</div>
					</div>
					<div class="edit-row">
						<div class="edit-field">
							<label for="agent-edit-max-concurrent">{{
								t('pipelinq', 'Max concurrent items')
							}}</label>
							<input
								id="agent-edit-max-concurrent"
								v-model.number="editForm.maxConcurrent"
								type="number"
								min="1" />
						</div>
						<div class="edit-field">
							<label>
								<input
									v-model="editForm.isAvailable"
									type="checkbox" />
								{{ t('pipelinq', 'Available for routing') }}
							</label>
						</div>
					</div>
					<div class="edit-actions">
						<NcButton @click="cancelEdit">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
						<NcButton variant="primary" @click="saveEdit">
							{{ t('pipelinq', 'Save') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="agent-add">
				<NcButton @click="addProfile">
					{{ t('pipelinq', '+ Add Agent Profile') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import { useAgentProfilesStore } from '../../store/modules/agentProfiles.js'
import { useSkillsStore } from '../../store/modules/skills.js'

export default {
	name: 'AgentProfileSettings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcSettingsSection,
	},

	data() {
		return {
			editingId: null,
			editForm: {},
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-9
		 */
		profilesStore() {
			return useAgentProfilesStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-11
		 */
		skillsStore() {
			return useSkillsStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-6
		 */
		loading() {
			return this.profilesStore.loading
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-8
		 */
		profiles() {
			return this.profilesStore.profiles
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-2
		 */
		allSkills() {
			return this.skillsStore.skills
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-7
	 */
	mounted() {
		this.profilesStore.fetchProfiles()
		this.skillsStore.fetchSkills()
	},

	methods: {
		/**
		 * @param {object} profile The agent profile whose skills to name.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-5
		 */
		getSkillNames(profile) {
			if (!profile.skills || !this.allSkills.length) return []
			return profile.skills
				.map((id) => this.allSkills.find((s) => s.id === id))
				.filter(Boolean)
				.map((s) => s.title)
		},

		/**
		 * @param {object} profile The agent profile to edit.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-12
		 */
		startEdit(profile) {
			this.editingId = profile.id
			this.editForm = { ...profile }
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-3
		 */
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
		},

		/**
		 * @param {string} skillId Identifier of the skill.
		 * @param {boolean} checked Whether the skill is now selected.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-13
		 */
		toggleSkill(skillId, checked) {
			const skills = [...(this.editForm.skills || [])]
			if (checked) {
				if (!skills.includes(skillId)) skills.push(skillId)
			} else {
				const idx = skills.indexOf(skillId)
				if (idx >= 0) skills.splice(idx, 1)
			}
			this.editForm = { ...this.editForm, skills }
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-10
		 */
		async saveEdit() {
			await this.profilesStore.saveProfile(this.editForm)
			this.cancelEdit()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-1
		 */
		async addProfile() {
			const userId = prompt(t('pipelinq', 'Enter Nextcloud user ID:'))
			if (!userId) return
			await this.profilesStore.saveProfile({
				userId,
				skills: [],
				maxConcurrent: 10,
				isAvailable: true,
			})
		},

		/**
		 * @param {object} profile The agent profile to delete.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-4
		 */
		async deleteProfile(profile) {
			if (
				confirm(
					t('pipelinq', 'Delete agent profile for "{userId}"?', {
						userId: profile.userId,
					}),
				)
			) {
				await this.profilesStore.deleteProfile(profile.id)
			}
		},
	},
}
</script>

<style scoped>
.agent-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.agent-item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px 16px;
}

.agent-item__display {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.agent-name {
	font-weight: 700;
}

.unavailable-tag {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-error);
	color: #fff;
	margin-inline-start: 6px;
}

.agent-meta {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.agent-item__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.agent-item__edit {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.edit-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.edit-field label {
	font-weight: 600;
	font-size: 13px;
}

.edit-field input[type='text'],
.edit-field input[type='number'] {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.edit-row {
	display: flex;
	gap: 16px;
}

.skill-checkboxes {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.skill-checkbox {
	display: flex;
	align-items: center;
	gap: 4px;
	font-size: 13px;
	font-weight: normal !important;
}

.edit-actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}

.agent-add {
	margin-top: 8px;
}
</style>

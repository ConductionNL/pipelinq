<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Skills')"
		:description="t('pipelinq', 'Define skills for routing requests to the right agents')">
		<NcLoadingIcon v-if="loading" />

		<div v-else class="skill-settings">
			<div v-for="skill in skills" :key="skill.id" class="skill-item">
				<div v-if="editingId !== skill.id" class="skill-item__display">
					<div class="skill-item__info">
						<span class="skill-title">{{ skill.title }}</span>
						<span v-if="skill.isActive === false" class="inactive-tag">{{ t('pipelinq', 'Inactive') }}</span>
						<span class="skill-meta">
							{{ (skill.categories || []).join(', ') || t('pipelinq', 'No categories') }}
						</span>
					</div>
					<div class="skill-item__actions">
						<NcButton @click="startEdit(skill)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton type="error" @click="deleteSkill(skill)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</div>
				</div>

				<div v-else class="skill-item__edit">
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Title') }}</label>
						<input v-model="editForm.title" type="text">
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Description') }}</label>
						<textarea v-model="editForm.description" />
					</div>
					<div class="edit-field">
						<label>{{ t('pipelinq', 'Categories (comma-separated)') }}</label>
						<input v-model="editForm.categoriesInput" type="text">
					</div>
					<div class="edit-field">
						<label>
							<input v-model="editForm.isActive" type="checkbox">
							{{ t('pipelinq', 'Active') }}
						</label>
					</div>
					<div class="edit-actions">
						<NcButton @click="cancelEdit">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
						<NcButton type="primary" @click="saveEdit">
							{{ t('pipelinq', 'Save') }}
						</NcButton>
					</div>
				</div>
			</div>

			<div class="skill-add">
				<NcButton @click="addSkill">
					{{ t('pipelinq', '+ Add Skill') }}
				</NcButton>
			</div>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcButton, NcLoadingIcon, NcSettingsSection } from '@nextcloud/vue'
import { useSkillsStore } from '../../store/modules/skills.js'

export default {
	name: 'SkillSettings',
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
		skillsStore() {
			return useSkillsStore()
		},
		loading() {
			return this.skillsStore.loading
		},
		skills() {
			return this.skillsStore.skills
		},
	},
	mounted() {
		this.skillsStore.fetchSkills()
	},
	methods: {
		startEdit(skill) {
			this.editingId = skill.id
			this.editForm = {
				...skill,
				categoriesInput: (skill.categories || []).join(', '),
			}
		},
		cancelEdit() {
			this.editingId = null
			this.editForm = {}
		},
		async saveEdit() {
			const data = {
				...this.editForm,
				categories: this.editForm.categoriesInput
					? this.editForm.categoriesInput.split(',').map(c => c.trim()).filter(Boolean)
					: [],
			}
			delete data.categoriesInput
			await this.skillsStore.saveSkill(data)
			this.cancelEdit()
		},
		async addSkill() {
			await this.skillsStore.saveSkill({
				title: t('pipelinq', 'New Skill'),
				isActive: true,
				categories: [],
			})
		},
		async deleteSkill(skill) {
			if (confirm(t('pipelinq', 'Delete skill "{title}"?', { title: skill.title }))) {
				await this.skillsStore.deleteSkill(skill.id)
			}
		},
	},
}
</script>

<style scoped>
.skill-settings {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.skill-item {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 12px 16px;
}

.skill-item__display {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
}

.skill-title {
	font-weight: 700;
}

.inactive-tag {
	font-size: 11px;
	padding: 1px 6px;
	border-radius: 8px;
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	margin-left: 6px;
}

.skill-meta {
	display: block;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}

.skill-item__actions {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.skill-item__edit {
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

.edit-field input,
.edit-field textarea {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.edit-actions {
	display: flex;
	gap: 4px;
	justify-content: flex-end;
}

.skill-add {
	margin-top: 8px;
}
</style>

<template>
	<CnSettingsSection :name="t('pipelinq', 'Product Categories')">
		<template #actions>
			<NcButton variant="secondary" @click="startAdding">
				{{ t('pipelinq', '+ Add Category') }}
			</NcButton>
		</template>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="categories.length === 0 && !adding" class="category-manager__empty">
			<p>{{ t('pipelinq', 'No product categories configured yet.') }}</p>
		</div>

		<div v-else class="category-manager__list">
			<div
				v-for="cat in sortedCategories"
				:key="cat.id"
				class="category-item"
				:class="{ 'category-item--editing': editingId === cat.id }">
				<template v-if="editingId === cat.id">
					<div class="category-item__edit">
						<input
							ref="editInput"
							v-model="editForm.name"
							class="category-item__input"
							:placeholder="t('pipelinq', 'Category name')"
							:aria-label="t('pipelinq', 'Category name')"
							@keyup.enter="saveEdit(cat.id)"
							@keyup.escape="cancelEdit">
						<input
							v-model="editForm.description"
							class="category-item__input category-item__input--desc"
							:placeholder="t('pipelinq', 'Description (optional)')"
							:aria-label="t('pipelinq', 'Category description')">
						<div class="category-item__actions">
							<NcButton variant="primary" :disabled="!editForm.name.trim()" @click="saveEdit(cat.id)">
								{{ t('pipelinq', 'Save') }}
							</NcButton>
							<NcButton @click="cancelEdit">
								{{ t('pipelinq', 'Cancel') }}
							</NcButton>
						</div>
					</div>
				</template>
				<template v-else>
					<div class="category-item__content">
						<span class="category-item__name">{{ cat.name }}</span>
						<span v-if="cat.description" class="category-item__desc">{{ cat.description }}</span>
					</div>
					<div class="category-item__buttons">
						<NcButton variant="tertiary" @click="startEditing(cat)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton variant="tertiary" @click="confirmRemove(cat)">
							{{ t('pipelinq', 'Remove') }}
						</NcButton>
					</div>
				</template>
			</div>

			<!-- Inline add form -->
			<div v-if="adding" class="category-item category-item--adding">
				<div class="category-item__edit">
					<input
						ref="addInput"
						v-model="addForm.name"
						class="category-item__input"
						:placeholder="t('pipelinq', 'Category name')"
						:aria-label="t('pipelinq', 'New category name')"
						@keyup.enter="saveNew"
						@keyup.escape="cancelAdding">
					<input
						v-model="addForm.description"
						class="category-item__input category-item__input--desc"
						:placeholder="t('pipelinq', 'Description (optional)')"
						:aria-label="t('pipelinq', 'New category description')">
					<div class="category-item__actions">
						<NcButton variant="primary" :disabled="!addForm.name.trim()" @click="saveNew">
							{{ t('pipelinq', 'Add') }}
						</NcButton>
						<NcButton @click="cancelAdding">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ProductCategoryManager',
	components: {
		CnSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},
	data() {
		return {
			categories: [],
			loading: false,
			adding: false,
			addForm: { name: '', description: '' },
			editingId: null,
			editForm: { name: '', description: '' },
			error: null,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-59
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-62
		 */
		sortedCategories() {
			return [...this.categories].sort((a, b) => {
				const orderA = a.order ?? 999
				const orderB = b.order ?? 999
				if (orderA !== orderB) return orderA - orderB
				return (a.name || '').localeCompare(b.name || '')
			})
		},
	},
	async mounted() {
		await this.fetchCategories()
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-58
		 */
		async fetchCategories() {
			this.loading = true
			try {
				const results = await this.objectStore.fetchCollection('productCategory', { _limit: 100 })
				this.categories = results || []
			} catch {
				this.categories = []
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-63
		 */
		startAdding() {
			this.adding = true
			this.addForm = { name: '', description: '' }
			this.error = null
			this.$nextTick(() => {
				this.$refs.addInput?.focus()
			})
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-55
		 */
		cancelAdding() {
			this.adding = false
			this.addForm = { name: '', description: '' }
			this.error = null
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-61
		 */
		async saveNew() {
			const name = this.addForm.name.trim()
			if (!name) return

			this.error = null
			try {
				await this.objectStore.saveObject('productCategory', {
					name,
					description: this.addForm.description.trim(),
					order: this.categories.length,
				})
				this.adding = false
				this.addForm = { name: '', description: '' }
				await this.fetchCategories()
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Failed to create category')
			}
		},
		/**
		 * @param cat
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-64
		 */
		startEditing(cat) {
			this.editingId = cat.id
			this.editForm = {
				name: cat.name || '',
				description: cat.description || '',
			}
			this.error = null
			this.$nextTick(() => {
				this.$refs.editInput?.[0]?.focus()
			})
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-56
		 */
		cancelEdit() {
			this.editingId = null
			this.editForm = { name: '', description: '' }
			this.error = null
		},
		/**
		 * @param id
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-60
		 */
		async saveEdit(id) {
			const name = this.editForm.name.trim()
			if (!name) return

			this.error = null
			try {
				await this.objectStore.saveObject('productCategory', {
					id,
					name,
					description: this.editForm.description.trim(),
				})
				this.editingId = null
				this.editForm = { name: '', description: '' }
				await this.fetchCategories()
			} catch (e) {
				this.error = e.message || t('pipelinq', 'Failed to update category')
			}
		},
		/**
		 * @param cat
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-57
		 */
		async confirmRemove(cat) {
			const message = t('pipelinq', 'Are you sure you want to remove "{name}"?', { name: cat.name })
			if (confirm(message)) {
				try {
					await this.objectStore.deleteObject('productCategory', cat.id)
					await this.fetchCategories()
				} catch (e) {
					this.error = e.message || t('pipelinq', 'Failed to remove category')
				}
			}
		},
	},
}
</script>

<style scoped>
.category-manager__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.category-manager__list {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.category-item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.category-item--editing,
.category-item--adding {
	background: var(--color-background-dark);
}

.category-item__content {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.category-item__name {
	font-weight: 600;
}

.category-item__desc {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.category-item__buttons {
	display: flex;
	gap: 4px;
	flex-shrink: 0;
}

.category-item__edit {
	display: flex;
	flex-direction: column;
	gap: 8px;
	width: 100%;
}

.category-item__input {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px;
	font-size: 14px;
	background: var(--color-main-background);
}

.category-item__input--desc {
	font-size: 13px;
}

.category-item__actions {
	display: flex;
	gap: 8px;
}
</style>

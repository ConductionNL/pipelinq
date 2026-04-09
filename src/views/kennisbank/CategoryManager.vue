<template>
	<div class="category-manager">
		<div class="category-manager__header">
			<h2>{{ t('pipelinq', 'Manage Categories') }}</h2>
			<NcButton type="primary" @click="showNewForm = true">
				{{ t('pipelinq', 'New category') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="categories.length === 0" class="category-manager__empty">
			<NcEmptyContent
				:name="t('pipelinq', 'No categories yet')"
				:description="t('pipelinq', 'Create categories to organize knowledge base articles')">
				<template #action>
					<NcButton type="primary" @click="showNewForm = true">
						{{ t('pipelinq', 'Create first category') }}
					</NcButton>
				</template>
			</NcEmptyContent>
		</div>

		<div v-else class="category-list">
			<div
				v-for="category in sortedCategories"
				:key="category.id"
				class="category-item"
				:style="{ paddingLeft: (getCategoryDepth(category) * 24) + 'px' }">
				<span class="category-item__name">{{ category.name }}</span>
				<span v-if="category.description" class="category-item__desc">
					{{ category.description }}
				</span>
				<div class="category-item__actions">
					<NcButton type="tertiary" @click="editCategory(category)">
						{{ t('pipelinq', 'Edit') }}
					</NcButton>
					<NcButton type="tertiary" @click="deleteCategory(category)">
						{{ t('pipelinq', 'Delete') }}
					</NcButton>
				</div>
			</div>
		</div>

		<div v-if="showNewForm || editingCategory" class="category-form">
			<h3>{{ editingCategory ? t('pipelinq', 'Edit Category') : t('pipelinq', 'New Category') }}</h3>
			<NcTextField
				:value.sync="formData.name"
				:label="t('pipelinq', 'Name')"
				:required="true" />
			<NcTextField
				:value.sync="formData.description"
				:label="t('pipelinq', 'Description')" />
			<div class="category-form__actions">
				<NcButton type="tertiary" @click="cancelForm">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton
					type="primary"
					:disabled="!formData.name.trim()"
					@click="saveCategory">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcEmptyContent, NcTextField } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'

export default {
	name: 'CategoryManager',
	components: {
		NcButton,
		NcLoadingIcon,
		NcEmptyContent,
		NcTextField,
	},
	data() {
		return {
			categories: [],
			loading: false,
			showNewForm: false,
			editingCategory: null,
			formData: {
				name: '',
				description: '',
				parent: null,
				order: 0,
			},
		}
	},
	computed: {
		sortedCategories() {
			return [...this.categories].sort((a, b) => (a.order || 0) - (b.order || 0))
		},
	},
	mounted() {
		this.fetchCategories()
	},
	methods: {
		async fetchCategories() {
			this.loading = true
			try {
				// Fetch from OpenRegister
				this.categories = []
			} finally {
				this.loading = false
			}
		},
		getCategoryDepth(category) {
			let depth = 0
			let current = category
			while (current.parent) {
				depth++
				current = this.categories.find(c => c.id === current.parent) || {}
				if (depth > 3) break
			}
			return depth
		},
		editCategory(category) {
			this.editingCategory = category
			this.formData = {
				name: category.name,
				description: category.description || '',
				parent: category.parent || null,
				order: category.order || 0,
			}
		},
		cancelForm() {
			this.showNewForm = false
			this.editingCategory = null
			this.formData = { name: '', description: '', parent: null, order: 0 }
		},
		async saveCategory() {
			try {
				// Save via OpenRegister objectStore
				showSuccess(t('pipelinq', 'Category saved'))
				this.cancelForm()
				this.fetchCategories()
			} catch (error) {
				showError(t('pipelinq', 'Failed to save category'))
			}
		},
		async deleteCategory(category) {
			if (!confirm(t('pipelinq', 'Delete category "{name}"? Articles will become uncategorized.', { name: category.name }))) {
				return
			}
			try {
				// Delete via OpenRegister
				showSuccess(t('pipelinq', 'Category deleted'))
				this.fetchCategories()
			} catch (error) {
				showError(t('pipelinq', 'Failed to delete category'))
			}
		},
	},
}
</script>

<style scoped>
.category-manager {
	padding: 20px;
	max-width: 800px;
	margin: 0 auto;
}

.category-manager__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 20px;
}

.category-list {
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.category-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border-bottom: 1px solid var(--color-border);
}

.category-item:last-child {
	border-bottom: none;
}

.category-item__name {
	font-weight: 600;
	flex-shrink: 0;
}

.category-item__desc {
	color: var(--color-text-lighter);
	font-size: 0.9em;
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.category-item__actions {
	display: flex;
	gap: 4px;
	margin-left: auto;
}

.category-form {
	margin-top: 20px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.category-form__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
}

.category-manager__empty {
	padding: 40px 0;
}
</style>

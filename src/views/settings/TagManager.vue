<template>
	<CnSettingsSection :name="title">
		<template #actions>
			<NcButton variant="secondary" @click="startAdding">
				{{ addLabel }}
			</NcButton>
		</template>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="tags.length === 0 && !adding" class="tag-manager__empty">
			<p>{{ t('pipelinq', 'No items configured yet.') }}</p>
		</div>

		<div v-else class="tag-manager__list">
			<div v-for="tag in tags"
				:key="tag.id"
				class="tag-chip"
				:class="{ 'tag-chip--editing': editingId === tag.id }">
				<template v-if="editingId === tag.id">
					<input ref="editInput"
						v-model="editName"
						class="tag-chip__input"
						:aria-label="t('pipelinq', 'Rename tag {name}', { name: tag.name })"
						@keyup.enter="saveRename(tag.id)"
						@keyup.escape="cancelEdit">
					<button class="tag-chip__action tag-chip__action--save"
						:title="t('pipelinq', 'Save')"
						@click="saveRename(tag.id)">
						&#10003;
					</button>
					<button class="tag-chip__action tag-chip__action--cancel"
						:title="t('pipelinq', 'Cancel')"
						@click="cancelEdit">
						&#10005;
					</button>
				</template>
				<template v-else>
					<span class="tag-chip__label"
						:title="t('pipelinq', 'Double-click to rename')"
						@dblclick="startEditing(tag)">
						{{ tag.name }}
					</span>
					<button class="tag-chip__remove"
						:title="t('pipelinq', 'Remove')"
						@click="confirmRemove(tag)">
						&times;
					</button>
				</template>
			</div>

			<!-- Inline add form -->
			<div v-if="adding" class="tag-chip tag-chip--adding">
				<input ref="addInput"
					v-model="newName"
					class="tag-chip__input"
					:placeholder="addPlaceholder"
					:aria-label="addPlaceholder"
					@keyup.enter="saveNew"
					@keyup.escape="cancelAdding">
				<button class="tag-chip__action tag-chip__action--save"
					:title="t('pipelinq', 'Add')"
					@click="saveNew">
					&#10003;
				</button>
				<button class="tag-chip__action tag-chip__action--cancel"
					:title="t('pipelinq', 'Cancel')"
					@click="cancelAdding">
					&#10005;
				</button>
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

export default {
	name: 'TagManager',
	components: {
		CnSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		title: {
			type: String,
			required: true,
		},
		tags: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
		addLabel: {
			type: String,
			/**
			 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-91
			 */
			default() { return t('pipelinq', '+ Add') },
		},
		addPlaceholder: {
			type: String,
			/**
			 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-91
			 */
			default() { return t('pipelinq', 'Enter name...') },
		},
		usageCheck: {
			type: Function,
			default: null,
		},
	},
	data() {
		return {
			adding: false,
			newName: '',
			editingId: null,
			editName: '',
			error: null,
		}
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-94
		 */
		startAdding() {
			this.adding = true
			this.newName = ''
			this.error = null
			this.$nextTick(() => {
				this.$refs.addInput?.focus()
			})
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-88
		 */
		cancelAdding() {
			this.adding = false
			this.newName = ''
			this.error = null
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-92
		 */
		async saveNew() {
			const name = this.newName.trim()
			if (!name) return

			// Check for duplicate names.
			const duplicate = this.tags.some(
				tag => tag.name.toLowerCase() === name.toLowerCase(),
			)
			if (duplicate) {
				this.error = t('pipelinq', 'An item with the name "{name}" already exists.', { name })
				return
			}

			this.error = null
			try {
				// $emit returns the vm, not the handler's promise, so we invoke the
				// listener directly to await the action and catch any rejection.
				await this.$attrs.onAdd?.(name)
				this.adding = false
				this.newName = ''
			} catch (e) {
				this.error = e.message
			}
		},
		/**
		 * @param tag
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-95
		 */
		startEditing(tag) {
			this.editingId = tag.id
			this.editName = tag.name
			this.error = null
			this.$nextTick(() => {
				this.$refs.editInput?.[0]?.focus()
			})
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-89
		 */
		cancelEdit() {
			this.editingId = null
			this.editName = ''
			this.error = null
		},
		/**
		 * @param id
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-93
		 */
		async saveRename(id) {
			const name = this.editName.trim()
			if (!name) return

			// Check for duplicate names (excluding the item being renamed).
			const duplicate = this.tags.some(
				tag => tag.id !== id && tag.name.toLowerCase() === name.toLowerCase(),
			)
			if (duplicate) {
				this.error = t('pipelinq', 'An item with the name "{name}" already exists.', { name })
				return
			}

			this.error = null
			try {
				// $emit returns the vm, not the handler's promise, so we invoke the
				// listener directly to await the action and catch any rejection.
				await this.$attrs.onRename?.(id, name)
				this.editingId = null
				this.editName = ''
			} catch (e) {
				this.error = e.message
			}
		},
		/**
		 * @param tag
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-90
		 */
		async confirmRemove(tag) {
			let message = t('pipelinq', 'Are you sure you want to remove "{name}"?', { name: tag.name })

			if (this.usageCheck) {
				try {
					const count = await this.usageCheck(tag.name)
					if (count > 0) {
						message = t('pipelinq', '{count} items currently use "{name}". They will retain their value, but it will no longer be available for new items.', { count, name: tag.name })
					}
				} catch (e) {
					// Non-blocking — proceed with generic message
				}
			}

			if (confirm(message)) {
				this.$emit('remove', tag.id)
			}
		},
	},
}
</script>

<style scoped>
.tag-manager__empty {
	color: var(--color-text-maxcontrast);
	padding: 8px 0;
}

.tag-manager__list {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	min-height: 44px;
	align-items: center;
}

.tag-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 4px 8px;
	border-radius: var(--border-radius-pill, 16px);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
	font-size: 13px;
	line-height: 1.4;
}

.tag-chip--editing,
.tag-chip--adding {
	background-color: var(--color-background-dark);
	padding: 2px 4px;
}

.tag-chip__label {
	cursor: default;
	user-select: none;
}

.tag-chip__input {
	border: none;
	background: transparent;
	font-size: 13px;
	padding: 2px 4px;
	width: 120px;
	outline: none;
	color: inherit;
}

.tag-chip__remove {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 16px;
	line-height: 1;
	padding: 0 2px;
	color: var(--color-text-maxcontrast);
	opacity: 0.7;
}

.tag-chip__remove:hover {
	opacity: 1;
	color: var(--color-error);
}

.tag-chip__action {
	background: none;
	border: none;
	cursor: pointer;
	font-size: 14px;
	line-height: 1;
	padding: 0 2px;
}

.tag-chip__action--save {
	color: var(--color-success);
}

.tag-chip__action--cancel {
	color: var(--color-text-maxcontrast);
}
</style>

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
			<div
				v-for="tag in tags"
				:key="tag.id"
				class="tag-chip"
				:class="{ 'tag-chip--editing': editingId === tag.id }">
				<template v-if="editingId === tag.id">
					<input
						ref="editInput"
						v-model="editName"
						class="tag-chip__input"
						:aria-label="
							t('pipelinq', 'Rename tag {name}', {
								name: labelOf(tag),
							})
						"
						@keyup.enter="saveRename(tag.id)"
						@keyup.escape="cancelEdit" />
					<button
						class="tag-chip__action tag-chip__action--save"
						:title="t('pipelinq', 'Save')"
						@click="saveRename(tag.id)">
						&#10003;
					</button>
					<button
						class="tag-chip__action tag-chip__action--cancel"
						:title="t('pipelinq', 'Cancel')"
						@click="cancelEdit">
						&#10005;
					</button>
				</template>
				<template v-else>
					<span
						class="tag-chip__label"
						:title="t('pipelinq', 'Double-click to rename')"
						@dblclick="startEditing(tag)">
						{{ labelOf(tag) }}
					</span>
					<button
						class="tag-chip__remove"
						:title="t('pipelinq', 'Remove')"
						@click="confirmRemove(tag)">
						&times;
					</button>
				</template>
			</div>

			<!-- Inline add form -->
			<div v-if="adding" class="tag-chip tag-chip--adding">
				<input
					ref="addInput"
					v-model="newName"
					class="tag-chip__input"
					:placeholder="addPlaceholder"
					:aria-label="addPlaceholder"
					@keyup.enter="saveNew"
					@keyup.escape="cancelAdding" />
				<button
					class="tag-chip__action tag-chip__action--save"
					:title="t('pipelinq', 'Add')"
					@click="saveNew">
					&#10003;
				</button>
				<button
					class="tag-chip__action tag-chip__action--cancel"
					:title="t('pipelinq', 'Cancel')"
					@click="cancelAdding">
					&#10005;
				</button>
			</div>
		</div>

		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<CnDeleteDialog
			v-if="deleting"
			ref="deleteDialog"
			:item="deleting"
			:nameFormatter="labelOf"
			:dialogTitle="t('pipelinq', 'Remove {name}', { name: labelOf(deleting) })"
			:confirmLabel="t('pipelinq', 'Remove')"
			@confirm="confirmDelete"
			@close="deleting = null" />
	</CnSettingsSection>
</template>

<script>
import { CnDeleteDialog, CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'TagManager',
	components: {
		CnDeleteDialog,
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
			default() {
				return t('pipelinq', '+ Add')
			},
		},

		addPlaceholder: {
			type: String,
			/**
			 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-91
			 */
			default() {
				return t('pipelinq', 'Enter name…')
			},
		},

		/**
		 * Remove a value: `(id) => Promise`. The server refuses a value that
		 * records still use, and the dialog shows its sentence.
		 */
		remove: {
			type: Function,
			required: true,
		},

		/**
		 * Display label of a stored tag name; the name itself when not set.
		 * Renaming still edits the stored name.
		 */
		tagLabel: {
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
			// The value the remove dialog asks about.
			deleting: null,
		}
	},

	methods: {
		/**
		 * @param {object} tag The tag.
		 * @return {string} Its display label.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		labelOf(tag) {
			return this.tagLabel ? this.tagLabel(tag.name) : tag.name
		},

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
				(tag) => tag.name.toLowerCase() === name.toLowerCase(),
			)
			if (duplicate) {
				this.error = t(
					'pipelinq',
					'An item with the name "{name}" already exists.',
					{ name },
				)
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
		 * @param {object} tag The tag to edit.
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
		 * @param {string} id Identifier of the tag being renamed.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-93
		 */
		async saveRename(id) {
			const name = this.editName.trim()
			if (!name) return

			// Check for duplicate names (excluding the item being renamed).
			const duplicate = this.tags.some(
				(tag) =>
					tag.id !== id && tag.name.toLowerCase() === name.toLowerCase(),
			)
			if (duplicate) {
				this.error = t(
					'pipelinq',
					'An item with the name "{name}" already exists.',
					{ name },
				)
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
		 * Ask before removing a value.
		 *
		 * @param {object} tag The tag the user asked to remove.
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
		 */
		confirmRemove(tag) {
			this.deleting = tag
		},

		/**
		 * Remove the value, and show the server's refusal when records still use it.
		 *
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-a-vocabulary-value-in-use-cannot-be-removed
		 */
		async confirmDelete() {
			const dialog = this.$refs.deleteDialog
			try {
				await this.remove(this.deleting.id)
				dialog?.setResult({ success: true })
			} catch (error) {
				dialog?.setResult({ error: error?.message || t('pipelinq', 'Deleting failed.') })
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

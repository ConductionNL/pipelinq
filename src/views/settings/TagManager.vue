<template>
	<CnSettingsSection :name="title">
		<template #actions>
			<NcButton variant="secondary" @click="startAdding">
				<template #icon>
					<Plus :size="20" />
				</template>
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
						type="button"
						class="tag-chip__remove"
						:title="t('pipelinq', 'Remove')"
						:aria-label="t('pipelinq', 'Remove')"
						@click="confirmRemove(tag)">
						<Close :size="18" />
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
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import Plus from 'vue-material-design-icons/Plus.vue'

export default {
	name: 'TagManager',
	components: {
		Close,
		CnSettingsSection,
		Plus,
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

		usageCheck: {
			type: Function,
			default: null,
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

	emits: ['remove'],

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
		 * @param {object} tag The tag the user asked to remove.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-90
		 */
		async confirmRemove(tag) {
			let message = t(
				'pipelinq',
				'Are you sure you want to remove "{name}"?',
				{ name: this.labelOf(tag) },
			)

			if (this.usageCheck) {
				try {
					const count = await this.usageCheck(tag.name)
					if (count > 0) {
						message = t(
							'pipelinq',
							'{count} items currently use "{name}". They will retain their value, but it will no longer be available for new items.',
							{ count, name: this.labelOf(tag) },
						)
					}
				} catch {
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
	min-height: 44px;
	align-items: center;
}

/* A tag is a 34px pill, 12px in, its name 14/600 in the light primary ink,
   the remove button a 26px round target at its end (PqBeheer). */
.tag-chip {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	box-sizing: border-box;
	height: 34px;
	padding: 0 4px 0 12px;
	border-radius: 17px;
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text, var(--color-main-text));
	font-size: 14px;
	font-weight: 600;
	line-height: normal;
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

/* Nextcloud gives every button a 34-40px clickable area and a ground; the
   remove target is the board's 26px circle on the chip. */
.tag-chip__remove {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 26px;
	min-width: 26px;
	height: 26px;
	min-height: 26px;
	margin: 0;
	padding: 0;
	border: 0;
	border-radius: 13px;
	background: transparent;
	color: inherit;
	cursor: pointer;
}

.tag-chip__remove:hover,
.tag-chip__remove:focus-visible {
	background: color-mix(in srgb, currentColor 12%, transparent);
	color: var(--color-error-text, var(--color-error));
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

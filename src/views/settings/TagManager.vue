<template>
	<div class="tag-manager">
		<div class="tag-manager__header">
			<h3>{{ title }}</h3>
			<NcButton type="secondary" @click="startAdding">
				{{ addLabel }}
			</NcButton>
		</div>

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
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'TagManager',
	components: {
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
			default() { return t('pipelinq', '+ Add') },
		},
		addPlaceholder: {
			type: String,
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
		startAdding() {
			this.adding = true
			this.newName = ''
			this.error = null
			this.$nextTick(() => {
				this.$refs.addInput?.focus()
			})
		},
		cancelAdding() {
			this.adding = false
			this.newName = ''
			this.error = null
		},
		async saveNew() {
			const name = this.newName.trim()
			if (!name) return

			this.error = null
			try {
				await this.$emit('add', name)
				this.adding = false
				this.newName = ''
			} catch (e) {
				this.error = e.message
			}
		},
		startEditing(tag) {
			this.editingId = tag.id
			this.editName = tag.name
			this.error = null
			this.$nextTick(() => {
				this.$refs.editInput?.[0]?.focus()
			})
		},
		cancelEdit() {
			this.editingId = null
			this.editName = ''
			this.error = null
		},
		async saveRename(id) {
			const name = this.editName.trim()
			if (!name) return

			this.error = null
			try {
				await this.$emit('rename', id, name)
				this.editingId = null
				this.editName = ''
			} catch (e) {
				this.error = e.message
			}
		},
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
.tag-manager {
	margin-bottom: 24px;
}

.tag-manager__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	margin-bottom: 12px;
}

.tag-manager__header h3 {
	margin: 0;
}

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

<template>
	<div class="entity-notes">
		<h3>{{ t('pipelinq', 'Notes') }}</h3>

		<div class="entity-notes__input">
			<textarea
				v-model="newMessage"
				:placeholder="t('pipelinq', 'Add a note...')"
				class="entity-notes__textarea"
				rows="3"
				@keydown="onNewNoteKeydown" />
			<NcButton
				type="primary"
				:disabled="submitting || newMessage.trim() === ''"
				@click="addNote">
				{{ submitting ? t('pipelinq', 'Saving...') : t('pipelinq', 'Add note') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" />

		<div v-else-if="notes.length === 0" class="entity-notes__empty">
			<p>{{ t('pipelinq', 'No notes yet') }}</p>
		</div>

		<div v-else class="entity-notes__list">
			<div
				v-for="note in notes"
				:key="note.id"
				class="entity-notes__item">
				<div class="entity-notes__item-header">
					<span class="entity-notes__author">{{ note.authorName }}</span>
					<span class="entity-notes__time">{{ formatTime(note.timestamp) }}</span>
					<template v-if="note.isOwn && editingNoteId !== note.id">
						<NcButton
							type="tertiary"
							class="entity-notes__action"
							@click="startEditing(note)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
						<NcButton
							type="tertiary"
							class="entity-notes__action"
							@click="deleteNote(note.id)">
							{{ t('pipelinq', 'Delete') }}
						</NcButton>
					</template>
				</div>

				<!-- Inline editing mode -->
				<div v-if="editingNoteId === note.id" class="entity-notes__edit">
					<textarea
						v-model="editMessage"
						class="entity-notes__textarea"
						rows="3"
						@keydown="onEditKeydown" />
					<div class="entity-notes__edit-actions">
						<NcButton
							type="primary"
							:disabled="saving || editMessage.trim() === ''"
							@click="saveEdit(note.id)">
							{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Save') }}
						</NcButton>
						<NcButton @click="cancelEditing">
							{{ t('pipelinq', 'Cancel') }}
						</NcButton>
					</div>
				</div>

				<!-- Display mode -->
				<p v-else class="entity-notes__message">
					{{ note.message }}
				</p>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'EntityNotes',
	components: {
		NcButton,
		NcLoadingIcon,
	},
	props: {
		objectType: {
			type: String,
			required: true,
		},
		objectId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			notes: [],
			newMessage: '',
			loading: false,
			submitting: false,
			editingNoteId: null,
			editMessage: '',
			saving: false,
		}
	},
	watch: {
		objectId() {
			this.fetchNotes()
		},
	},
	mounted() {
		this.fetchNotes()
	},
	methods: {
		async fetchNotes() {
			this.loading = true
			try {
				const response = await fetch(
					`/apps/pipelinq/api/notes/${this.objectType}/${this.objectId}`,
					{
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				if (response.ok) {
					const data = await response.json()
					this.notes = data.notes || []
				}
			} catch {
				this.notes = []
			}
			this.loading = false
		},

		onNewNoteKeydown(event) {
			if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
				event.preventDefault()
				this.addNote()
			}
		},

		async addNote() {
			if (this.newMessage.trim() === '') return
			this.submitting = true
			try {
				const response = await fetch(
					`/apps/pipelinq/api/notes/${this.objectType}/${this.objectId}`,
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify({ message: this.newMessage }),
					},
				)
				if (response.ok) {
					this.newMessage = ''
					await this.fetchNotes()
				}
			} catch {
				// Submit failed silently
			}
			this.submitting = false
		},

		startEditing(note) {
			this.editingNoteId = note.id
			this.editMessage = note.message
		},

		cancelEditing() {
			this.editingNoteId = null
			this.editMessage = ''
		},

		onEditKeydown(event) {
			if ((event.ctrlKey || event.metaKey) && event.key === 'Enter') {
				event.preventDefault()
				if (this.editingNoteId !== null) {
					this.saveEdit(this.editingNoteId)
				}
			}
		},

		async saveEdit(noteId) {
			if (this.editMessage.trim() === '') return
			this.saving = true
			try {
				const response = await fetch(
					`/apps/pipelinq/api/notes/single/${noteId}`,
					{
						method: 'PUT',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify({ message: this.editMessage }),
					},
				)
				if (response.ok) {
					this.editingNoteId = null
					this.editMessage = ''
					await this.fetchNotes()
				}
			} catch {
				// Edit failed silently
			}
			this.saving = false
		},

		async deleteNote(noteId) {
			try {
				const response = await fetch(
					`/apps/pipelinq/api/notes/single/${noteId}`,
					{
						method: 'DELETE',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				if (response.ok) {
					await this.fetchNotes()
				}
			} catch {
				// Delete failed silently
			}
		},

		formatTime(timestamp) {
			if (!timestamp) return ''
			const date = new Date(timestamp)
			const now = new Date()
			const diff = now - date

			// Less than 1 minute
			if (diff < 60000) return t('pipelinq', 'Just now')
			// Less than 1 hour
			if (diff < 3600000) {
				const mins = Math.floor(diff / 60000)
				return n('pipelinq', '%n minute ago', '%n minutes ago', mins)
			}
			// Less than 24 hours
			if (diff < 86400000) {
				const hours = Math.floor(diff / 3600000)
				return n('pipelinq', '%n hour ago', '%n hours ago', hours)
			}
			// Otherwise show date
			return date.toLocaleDateString(undefined, {
				year: 'numeric',
				month: 'short',
				day: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},
	},
}
</script>

<style scoped>
.entity-notes {
	margin-top: 40px;
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.entity-notes__input {
	display: flex;
	gap: 12px;
	align-items: flex-end;
	margin-bottom: 20px;
}

.entity-notes__textarea {
	flex: 1;
	padding: 8px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	font-family: inherit;
	font-size: 14px;
	resize: vertical;
	min-height: 60px;
}

.entity-notes__textarea:focus {
	border-color: var(--color-primary);
	outline: none;
}

.entity-notes__empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}

.entity-notes__list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.entity-notes__item {
	padding: 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.entity-notes__item-header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 6px;
}

.entity-notes__author {
	font-weight: 600;
	font-size: 13px;
}

.entity-notes__time {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.entity-notes__action {
	margin-left: auto;
}

.entity-notes__action + .entity-notes__action {
	margin-left: 0;
}

.entity-notes__edit {
	margin-top: 8px;
}

.entity-notes__edit-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
}

.entity-notes__message {
	margin: 0;
	font-size: 14px;
	white-space: pre-wrap;
	word-break: break-word;
}
</style>

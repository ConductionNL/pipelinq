<template>
	<div class="contact-detail">
		<div class="contact-detail__header">
			<NcButton @click="$emit('navigate', 'contacts')">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New contact') }}
			</h2>
			<h2 v-else>
				{{ contactData.name || t('pipelinq', 'Contact') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<!-- Edit / Create mode -->
		<ContactForm
			v-else-if="editing || isNew"
			:contact="contactData"
			:pre-selected-client="preSelectedClient"
			@save="onFormSave"
			@cancel="onFormCancel" />

		<!-- View mode -->
		<div v-else class="contact-detail__info">
			<div class="contact-detail__actions">
				<NcButton type="primary" @click="editing = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>

			<div v-if="contactData.contactsUid" class="sync-badge">
				{{ t('pipelinq', 'Synced with Contacts') }}
			</div>

			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Role') }}</label>
					<span>{{ contactData.role || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Email') }}</label>
					<span>{{ contactData.email || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Phone') }}</label>
					<span>{{ contactData.phone || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Client') }}</label>
					<a
						v-if="contactData.client"
						class="client-link"
						@click="$emit('navigate', 'client-detail', contactData.client)">
						{{ clientName }}
					</a>
					<span v-else>-</span>
				</div>
			</div>
		</div>

		<!-- Notes section -->
		<EntityNotes
			v-if="!isNew && !loading && !editing"
			object-type="pipelinq_contact"
			:object-id="contactId" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ContactForm from './ContactForm.vue'
import EntityNotes from '../../components/EntityNotes.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		ContactForm,
		EntityNotes,
	},
	props: {
		contactId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			clientName: '-',
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			if (!this.contactId) return true
			return this.contactId === 'new' || this.contactId.startsWith('new?')
		},
		preSelectedClient() {
			if (this.contactId && this.contactId.startsWith('new?client=')) {
				return this.contactId.replace('new?client=', '')
			}
			return null
		},
		loading() {
			return this.objectStore.isLoading('contact')
		},
		contactData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('contact', this.contactId) || {}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('contact', this.contactId)
			this.loadClientName()
		}
	},
	methods: {
		async loadClientName() {
			const clientId = this.contactData.client
			if (clientId) {
				try {
					const client = await this.objectStore.fetchObject('client', clientId)
					this.clientName = client?.name || t('pipelinq', '[Deleted client]')
				} catch {
					this.clientName = t('pipelinq', '[Deleted client]')
				}
			}
		},
		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('contact', formData)
			if (result) {
				this.syncToContacts(result.id || this.contactId)
				if (this.isNew) {
					this.$emit('navigate', 'contact-detail', result.id)
				} else {
					await this.objectStore.fetchObject('contact', this.contactId)
					this.loadClientName()
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('contact')
				showError(error?.message || t('pipelinq', 'Failed to save contact. Please try again.'))
			}
		},
		async syncToContacts(objectId) {
			try {
				await fetch('/apps/pipelinq/api/contacts-sync/write-back', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({ objectType: 'contact', objectId }),
				})
			} catch {
				// Sync failure is non-blocking
			}
		},
		onFormCancel() {
			if (this.isNew) {
				this.$emit('navigate', 'contacts')
			} else {
				this.editing = false
			}
		},
		async confirmDelete() {
			if (confirm(t('pipelinq', 'Are you sure you want to delete this contact?'))) {
				this.cleanupNotes('pipelinq_contact', this.contactId)
				const success = await this.objectStore.deleteObject('contact', this.contactId)
				if (success) {
					this.$emit('navigate', 'contacts')
				} else {
					const error = this.objectStore.getError('contact')
					showError(error?.message || t('pipelinq', 'Failed to delete contact.'))
				}
			}
		},
		async cleanupNotes(objectType, objectId) {
			try {
				await fetch(`/apps/pipelinq/api/notes/${objectType}/${objectId}`, {
					method: 'DELETE',
					headers: { requesttoken: OC.requestToken, 'OCS-APIREQUEST': 'true' },
				})
			} catch {
				// Cleanup failure is non-blocking
			}
		},
	},
}
</script>

<style scoped>
.contact-detail {
	padding: 20px;
	max-width: 800px;
}

.contact-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.contact-detail__actions {
	display: flex;
	gap: 12px;
	margin-bottom: 20px;
}

.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field {
	margin-bottom: 8px;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.client-link {
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: underline;
}

.client-link:hover {
	color: var(--color-primary-hover);
}

.sync-badge {
	display: inline-block;
	padding: 4px 10px;
	background: #dcfce7;
	color: #166534;
	border: 1px solid #86efac;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 16px;
}
</style>

<template>
	<div v-if="editing || isNew">
		<div class="contact-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New contact') }}
			</h2>
			<h2 v-else>
				{{ contactData.name || t('pipelinq', 'Contact') }}
			</h2>
		</div>
		<ContactForm
			:contact="contactData"
			:pre-selected-client="preSelectedClient"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="contactData.name || t('pipelinq', 'Contact')"
		:subtitle="t('pipelinq', 'Contact')"
		:back-route="{ name: 'Contacts' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_contact"
		:object-id="contactId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="confirmDelete">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Contact Information')">
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
						@click="$router.push({ name: 'ClientDetail', params: { id: contactData.client } })">
						{{ clientName }}
					</a>
					<span v-else>-</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- Relationships -->
		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Relationships')">
			<ContactRelationships
				:entity-id="contactId"
				entity-type="contact"
				:entity-name="contactData.name || ''" />
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ContactForm from './ContactForm.vue'
import ContactRelationships from '../../components/ContactRelationships.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		ContactForm,
		ContactRelationships,
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
			return !this.contactId || this.contactId === 'new'
		},
		preSelectedClient() {
			return this.$route.query.client || null
		},
		loading() {
			return this.objectStore.loading.contact || false
		},
		contactData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('contact', this.contactId) || {}
		},
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.contact || {}
			return {
				title: t('pipelinq', 'Contact'),
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
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
					this.$router.push({ name: 'ContactDetail', params: { id: result.id } })
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
				this.$router.push({ name: 'Contacts' })
			} else {
				this.editing = false
			}
		},
		async confirmDelete() {
			if (confirm(t('pipelinq', 'Are you sure you want to delete this contact?'))) {
				const success = await this.objectStore.deleteObject('contact', this.contactId)
				if (success) {
					this.$router.push({ name: 'Contacts' })
				} else {
					const error = this.objectStore.getError('contact')
					showError(error?.message || t('pipelinq', 'Failed to delete contact.'))
				}
			}
		},
	},
}
</script>

<style scoped>
.contact-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
	padding: 20px 20px 0;
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

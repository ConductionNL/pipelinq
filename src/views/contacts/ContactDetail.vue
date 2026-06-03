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
		<template #actions>
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

		<!-- BSN validation + BRP lookup -->
		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'BSN verification (BRP)')">
			<BrpLookupCard :contact-id="contactId" @timeline-event="onTimelineEvent" />
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ContactForm from './ContactForm.vue'
import ContactRelationships from '../../components/ContactRelationships.vue'
import BrpLookupCard from '../../components/BrpLookupCard.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ContactDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		ContactForm,
		ContactRelationships,
		BrpLookupCard,
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-35
		 */
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.contactId || this.contactId === 'new'
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-38
		 */
		preSelectedClient() {
			return this.$route.query.client || null
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-33
		 */
		loading() {
			return this.objectStore.loading.contact || false
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-31
		 */
		contactData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('contact', this.contactId) || {}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-39
		 */
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
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-34
	 */
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('contact', this.contactId)
			this.loadClientName()
		}
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-32
		 */
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-37
		 */
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
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-40
		 */
		async syncToContacts(objectId) {
			try {
				await fetch(generateUrl('/apps/pipelinq/api/contacts-sync/write-back'), {
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
		/**
		 * Surface a successful BRP lookup and refresh the contact so the
		 * server-written verifiedBSN/geheimhouding flags and the audit-backed
		 * timeline are reflected. The event text never contains the BSN.
		 *
		 * @param {object} event The timeline event ({ action, text }).
		 *
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.6
		 */
		async onTimelineEvent(event) {
			showSuccess(event.text)
			if (!this.isNew) {
				await this.objectStore.fetchObject('contact', this.contactId)
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-36
		 */
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Contacts' })
			} else {
				this.editing = false
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-30
		 */
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

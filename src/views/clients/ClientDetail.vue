<template>
	<div class="client-detail">
		<div class="client-detail__header">
			<NcButton @click="$router.push({ name: 'Clients' })">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New client') }}
			</h2>
			<h2 v-else>
				{{ clientData.name || t('pipelinq', 'Client') }}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" />

		<!-- Edit / Create mode -->
		<ClientForm
			v-else-if="editing || isNew"
			:client="clientData"
			@save="onFormSave"
			@cancel="onFormCancel" />

		<!-- View mode -->
		<div v-else class="client-detail__info">
			<div class="client-detail__actions">
				<NcButton type="primary" @click="editing = true">
					{{ t('pipelinq', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="showDeleteWarning">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>

			<div v-if="clientData.contactsUid" class="sync-badge">
				{{ t('pipelinq', 'Synced with Contacts') }}
			</div>

			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Type') }}</label>
					<span>{{ clientData.type || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Email') }}</label>
					<span>{{ clientData.email || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Phone') }}</label>
					<span>{{ clientData.phone || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Website') }}</label>
					<span>{{ clientData.website || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Address') }}</label>
					<span>{{ clientData.address || '-' }}</span>
				</div>
			</div>
			<div v-if="clientData.notes" class="info-field info-field--full">
				<label>{{ t('pipelinq', 'Notes') }}</label>
				<p>{{ clientData.notes }}</p>
			</div>
		</div>

		<!-- Contacts section -->
		<div v-if="!isNew && !loading && !editing" class="client-detail__section">
			<div class="section-header">
				<h3>{{ t('pipelinq', 'Contacts') }}</h3>
				<NcButton @click="addContact">
					{{ t('pipelinq', 'Add contact') }}
				</NcButton>
			</div>

			<div v-if="contacts.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No contacts found') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Name') }}</th>
						<th>{{ t('pipelinq', 'Role') }}</th>
						<th>{{ t('pipelinq', 'Email') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="contact in contacts"
						:key="contact.id"
						class="viewTableRow"
						@click="$router.push({ name: 'ContactDetail', params: { id: contact.id } })">
						<td>{{ contact.name || '-' }}</td>
						<td>{{ contact.role || '-' }}</td>
						<td>{{ contact.email || '-' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		</div>

		<!-- Leads section -->
		<div v-if="!isNew && !loading && !editing" class="client-detail__section">
			<div class="section-header">
				<h3>{{ t('pipelinq', 'Leads') }}</h3>
			</div>

			<div v-if="leads.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No leads found') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Title') }}</th>
						<th>{{ t('pipelinq', 'Stage') }}</th>
						<th>{{ t('pipelinq', 'Value') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="lead in leads"
						:key="lead.id"
						class="viewTableRow"
						@click="$router.push({ name: 'LeadDetail', params: { id: lead.id } })">
						<td>{{ lead.title || '-' }}</td>
						<td>{{ lead.stage || '-' }}</td>
						<td>{{ lead.value || '-' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		</div>

		<!-- Requests section -->
		<div v-if="!isNew && !loading && !editing" class="client-detail__section">
			<div class="section-header">
				<h3>{{ t('pipelinq', 'Requests') }}</h3>
				<NcButton @click="createRequest">
					{{ t('pipelinq', 'New request') }}
				</NcButton>
			</div>

			<div v-if="requests.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No requests found') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Title') }}</th>
						<th>{{ t('pipelinq', 'Status') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="request in requests"
						:key="request.id"
						class="viewTableRow"
						@click="$router.push({ name: 'RequestDetail', params: { id: request.id } })">
						<td>{{ request.title || '-' }}</td>
						<td>{{ request.status || '-' }}</td>
					</tr>
				</tbody>
			</table>
		</div>
		</div>

		<!-- Notes section -->
		<EntityNotes
			v-if="!isNew && !loading && !editing"
			object-type="pipelinq_client"
			:object-id="clientId" />

		<!-- Delete warning dialog -->
		<NcDialog
			v-if="showDelete"
			:name="t('pipelinq', 'Delete client')"
			@closing="showDelete = false">
			<p>
				{{ t('pipelinq', 'Are you sure you want to delete "{name}"?', { name: clientData.name }) }}
			</p>
			<p v-if="contacts.length || leads.length || requests.length" class="delete-warning">
				{{ t('pipelinq', 'This client has linked entities:') }}
			</p>
			<ul v-if="contacts.length || leads.length || requests.length" class="delete-warning-list">
				<li v-if="contacts.length">
					{{ n('pipelinq', '%n contact', '%n contacts', contacts.length) }}
				</li>
				<li v-if="leads.length">
					{{ n('pipelinq', '%n lead', '%n leads', leads.length) }}
				</li>
				<li v-if="requests.length">
					{{ n('pipelinq', '%n request', '%n requests', requests.length) }}
				</li>
			</ul>
			<template #actions>
				<NcButton @click="showDelete = false">
					{{ t('pipelinq', 'Cancel') }}
				</NcButton>
				<NcButton type="error" @click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ClientForm from './ClientForm.vue'
import EntityNotes from '../../components/EntityNotes.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientDetail',
	components: {
		NcButton,
		NcLoadingIcon,
		NcDialog,
		ClientForm,
		EntityNotes,
	},
	props: {
		clientId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			requests: [],
			contacts: [],
			leads: [],
			showDelete: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.clientId || this.clientId === 'new'
		},
		loading() {
			return this.objectStore.loading.client || false
		},
		clientData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('client', this.clientId) || {}
		},
	},
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('client', this.clientId)
			await this.fetchRelated()
		}
	},
	methods: {
		async onFormSave(formData) {
			const result = await this.objectStore.saveObject('client', formData)
			if (result) {
				this.syncToContacts(result.id || this.clientId)
				if (this.isNew) {
					this.$router.push({ name: 'ClientDetail', params: { id: result.id } })
				} else {
					await this.objectStore.fetchObject('client', this.clientId)
					this.editing = false
				}
			} else {
				const error = this.objectStore.getError('client')
				showError(error?.message || t('pipelinq', 'Failed to save client. Please try again.'))
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
					body: JSON.stringify({ objectType: 'client', objectId }),
				})
			} catch {
				// Sync failure is non-blocking
			}
		},
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Clients' })
			} else {
				this.editing = false
			}
		},
		showDeleteWarning() {
			this.showDelete = true
		},
		async confirmDelete() {
			this.showDelete = false
			this.cleanupNotes('pipelinq_client', this.clientId)
			const success = await this.objectStore.deleteObject('client', this.clientId)
			if (success) {
				this.$router.push({ name: 'Clients' })
			} else {
				const error = this.objectStore.getError('client')
				showError(error?.message || t('pipelinq', 'Failed to delete client.'))
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
		async fetchRelated() {
			const allRequests = await this.objectStore.fetchCollection('request', {
				_limit: 50,
				client: this.clientId,
			})
			this.requests = allRequests || []

			const allContacts = await this.objectStore.fetchCollection('contact', {
				_limit: 50,
				client: this.clientId,
			})
			this.contacts = allContacts || []

			try {
				const allLeads = await this.objectStore.fetchCollection('lead', {
					_limit: 50,
					client: this.clientId,
				})
				this.leads = allLeads || []
			} catch {
				this.leads = []
			}
		},
		createRequest() {
			this.$router.push({ name: 'RequestDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		addContact() {
			this.$router.push({ name: 'ContactDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
	},
}
</script>

<style scoped>
.client-detail {
	padding: 20px;
	max-width: 800px;
}

.client-detail__header {
	display: flex;
	align-items: center;
	gap: 16px;
	margin-bottom: 20px;
}

.client-detail__actions {
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

.info-field span,
.info-field p {
	margin: 0;
}

.info-field--full {
	margin-top: 16px;
}

.client-detail__section {
	margin-top: 40px;
	border-top: 1px solid var(--color-border);
	padding-top: 20px;
}

.section-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 16px;
}

.viewTableContainer {
	background: var(--color-main-background);
	border-radius: var(--border-radius);
	overflow: hidden;
	box-shadow: 0 2px 4px var(--color-box-shadow);
	border: 1px solid var(--color-border);
}

.viewTable {
	width: 100%;
	border-collapse: collapse;
	background-color: var(--color-main-background);
}

.viewTable th,
.viewTable td {
	padding: 12px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
	vertical-align: middle;
}

.viewTable th {
	background-color: var(--color-background-dark);
	font-weight: 500;
	color: var(--color-text-maxcontrast);
}

.viewTableRow {
	cursor: pointer;
	transition: background-color 0.2s ease;
}

.viewTableRow:hover {
	background: var(--color-background-hover);
}

.section-empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	padding: 20px;
}

.delete-warning {
	font-weight: bold;
	margin-top: 12px;
}

.delete-warning-list {
	margin: 8px 0;
	padding-left: 20px;
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

<template>
	<div v-if="editing || isNew">
		<div class="client-detail__header">
			<NcButton @click="onFormCancel">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2 v-if="isNew">
				{{ t('pipelinq', 'New client') }}
			</h2>
			<h2 v-else>
				{{ clientData.name || t('pipelinq', 'Client') }}
			</h2>
		</div>
		<ClientForm
			:client="clientData"
			@save="onFormSave"
			@cancel="onFormCancel" />
	</div>

	<CnDetailPage
		v-else
		:title="clientData.name || t('pipelinq', 'Client')"
		:subtitle="t('pipelinq', 'Client')"
		:back-route="{ name: 'Clients' }"
		:back-label="t('pipelinq', 'Back to list')"
		:loading="loading"
		:sidebar="!isNew && !loading"
		object-type="pipelinq_client"
		:object-id="clientId"
		:sidebar-props="sidebarProps">
		<template #header-actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="showDeleteWarning">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Client Information')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Summary')">
			<div class="summary-grid">
				<div class="summary-item">
					<span class="summary-value">{{ openLeadsCount }}</span>
					<span class="summary-label">{{ t('pipelinq', 'Open leads') }}</span>
					<span class="summary-sub">{{ formatCurrency(openLeadsValue) }}</span>
				</div>
				<div class="summary-item">
					<span class="summary-value">{{ wonLeadsCount }}</span>
					<span class="summary-label">{{ t('pipelinq', 'Won leads') }}</span>
					<span class="summary-sub">{{ formatCurrency(wonLeadsValue) }}</span>
				</div>
				<div class="summary-item">
					<span class="summary-value">{{ openRequestsCount }}</span>
					<span class="summary-label">{{ t('pipelinq', 'Open requests') }}</span>
				</div>
				<div class="summary-item">
					<span class="summary-value summary-value--total">{{ formatCurrency(totalValue) }}</span>
					<span class="summary-label">{{ t('pipelinq', 'Total value') }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Contacts')">
			<template #actions>
				<NcButton @click="addContact">
					{{ t('pipelinq', 'Add contact') }}
				</NcButton>
			</template>

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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Leads')">
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
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Requests')">
			<template #actions>
				<NcButton @click="createRequest">
					{{ t('pipelinq', 'New request') }}
				</NcButton>
			</template>

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
		</CnDetailCard>

		<!-- Relationships -->
		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Relationships')">
			<ContactRelationships
				:entity-id="clientId"
				entity-type="client"
				:entity-name="clientData.name || ''" />
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Contactmomenten')">
			<template #actions>
				<NcButton @click="showContactmomentQuickLog = true">
					{{ t('pipelinq', 'Log contactmoment') }}
				</NcButton>
			</template>

			<div v-if="contactmomenten.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'Geen contactmomenten geregistreerd') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Subject') }}</th>
							<th>{{ t('pipelinq', 'Channel') }}</th>
							<th>{{ t('pipelinq', 'Agent') }}</th>
							<th>{{ t('pipelinq', 'Date') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="cm in contactmomenten"
							:key="cm.id"
							class="viewTableRow"
							@click="$router.push({ name: 'ContactmomentDetail', params: { id: cm.id } })">
							<td>{{ cm.subject || '-' }}</td>
							<td>{{ cm.channel || '-' }}</td>
							<td>{{ cm.agent || '-' }}</td>
							<td>{{ formatDate(cm.contactedAt) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Complaints')">
			<template #actions>
				<NcButton @click="createComplaint">
					{{ t('pipelinq', 'Add complaint') }}
				</NcButton>
			</template>

			<div v-if="complaints.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No complaints found') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Title') }}</th>
							<th>{{ t('pipelinq', 'Status') }}</th>
							<th>{{ t('pipelinq', 'Date') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="complaint in complaints"
							:key="complaint.id"
							class="viewTableRow"
							@click="$router.push({ name: 'ComplaintDetail', params: { id: complaint.id } })">
							<td>{{ complaint.title || '-' }}</td>
							<td>{{ complaint.status || '-' }}</td>
							<td>{{ formatDate(complaint._dateCreated || complaint.dateCreated) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<!-- Contactmoment quick-log dialog -->
		<NcDialog
			v-if="showContactmomentQuickLog"
			:name="t('pipelinq', 'Log contactmoment')"
			size="normal"
			@closing="showContactmomentQuickLog = false">
			<ContactmomentQuickLog
				:client-id="clientId"
				:inline="true"
				@saved="onContactmomentSaved"
				@cancel="showContactmomentQuickLog = false" />
		</NcDialog>

		<!-- Delete warning dialog -->
		<NcDialog
			v-if="showDelete"
			:name="t('pipelinq', 'Delete client')"
			@closing="showDelete = false">
			<p>
				{{ t('pipelinq', 'Are you sure you want to delete "{name}"?', { name: clientData.name }) }}
			</p>
			<p v-if="contacts.length || leads.length || requests.length || complaints.length" class="delete-warning">
				{{ t('pipelinq', 'This client has linked entities:') }}
			</p>
			<ul v-if="contacts.length || leads.length || requests.length || complaints.length" class="delete-warning-list">
				<li v-if="contacts.length">
					{{ n('pipelinq', '%n contact', '%n contacts', contacts.length) }}
				</li>
				<li v-if="leads.length">
					{{ n('pipelinq', '%n lead', '%n leads', leads.length) }}
				</li>
				<li v-if="requests.length">
					{{ n('pipelinq', '%n request', '%n requests', requests.length) }}
				</li>
				<li v-if="complaints.length">
					{{ n('pipelinq', '%n complaint', '%n complaints', complaints.length) }}
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
	</CnDetailPage>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ClientForm from './ClientForm.vue'
import ContactRelationships from '../../components/ContactRelationships.vue'
import ContactmomentQuickLog from '../../components/ContactmomentQuickLog.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientDetail',
	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
		ClientForm,
		ContactRelationships,
		ContactmomentQuickLog,
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
			contactmomenten: [],
			complaints: [],
			showDelete: false,
			showContactmomentQuickLog: false,
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
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.client || {}
			return {
				title: t('pipelinq', 'Client'),
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
		openLeadsCount() {
			return this.leads.filter(l => !this.isClosedLead(l)).length
		},
		openLeadsValue() {
			return this.leads
				.filter(l => !this.isClosedLead(l))
				.reduce((sum, l) => sum + (parseFloat(l.value) || 0), 0)
		},
		wonLeadsCount() {
			return this.leads.filter(l => l.status === 'won').length
		},
		wonLeadsValue() {
			return this.leads
				.filter(l => l.status === 'won')
				.reduce((sum, l) => sum + (parseFloat(l.value) || 0), 0)
		},
		openRequestsCount() {
			return this.requests.filter(r => r.status === 'new' || r.status === 'in_progress').length
		},
		totalValue() {
			return this.openLeadsValue + this.wonLeadsValue
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
			const success = await this.objectStore.deleteObject('client', this.clientId)
			if (success) {
				this.$router.push({ name: 'Clients' })
			} else {
				const error = this.objectStore.getError('client')
				showError(error?.message || t('pipelinq', 'Failed to delete client.'))
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

			try {
				const allContactmomenten = await this.objectStore.fetchCollection('contactmoment', {
					_limit: 50,
					client: this.clientId,
					_order: { contactedAt: 'desc' },
				})
				this.contactmomenten = allContactmomenten || []
			} catch {
				this.contactmomenten = []
			}

			try {
				const allComplaints = await this.objectStore.fetchCollection('complaint', {
					_limit: 50,
					client: this.clientId,
					_order: { _dateCreated: 'desc' },
				})
				this.complaints = allComplaints || []
			} catch {
				this.complaints = []
			}
		},
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
		async onContactmomentSaved() {
			this.showContactmomentQuickLog = false
			await this.fetchRelated()
		},
		createRequest() {
			this.$router.push({ name: 'RequestDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		addContact() {
			this.$router.push({ name: 'ContactDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		createComplaint() {
			this.$router.push({ name: 'ComplaintDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		isClosedLead(lead) {
			return lead.status === 'won' || lead.status === 'lost'
		},
		formatCurrency(value) {
			if (value === 0 || value == null) return 'EUR 0'
			return 'EUR ' + new Intl.NumberFormat('nl-NL').format(value)
		},
	},
}
</script>

<style scoped>
.client-detail__header {
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

.info-field span,
.info-field p {
	margin: 0;
}

.info-field--full {
	margin-top: 16px;
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

.summary-grid {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
}

.summary-item {
	display: flex;
	flex-direction: column;
	align-items: center;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-dark);
}

.summary-value {
	font-size: 24px;
	font-weight: bold;
	color: var(--color-main-text);
}

.summary-value--total {
	color: var(--color-primary);
}

.summary-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.summary-sub {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 2px;
}
</style>

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

		<!-- Summary Statistics (klantbeeld-360) -->
		<div v-if="!isNew" class="klantbeeld-stats">
			<div class="stat-tile" @click="scrollToSection('leads')">
				<div class="stat-tile__value">
					{{ openLeadsCount }}
				</div>
				<div class="stat-tile__label">
					{{ t('pipelinq', 'Open Leads') }}
				</div>
				<div v-if="totalLeadValue > 0" class="stat-tile__sub">
					EUR {{ totalLeadValue.toLocaleString('nl-NL') }}
				</div>
			</div>
			<div class="stat-tile" @click="scrollToSection('requests')">
				<div class="stat-tile__value">
					{{ openRequestsCount }}
				</div>
				<div class="stat-tile__label">
					{{ t('pipelinq', 'Open Requests') }}
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-tile__value">
					{{ contacts.length }}
				</div>
				<div class="stat-tile__label">
					{{ t('pipelinq', 'Contacts') }}
				</div>
			</div>
			<div class="stat-tile">
				<div class="stat-tile__value">
					{{ lastContactLabel }}
				</div>
				<div class="stat-tile__label">
					{{ t('pipelinq', 'Last Contact') }}
				</div>
			</div>
		</div>

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

		<CnDetailCard ref="leads" :title="t('pipelinq', 'Leads')">
			<template #actions>
				<NcButton @click="$router.push({ name: 'LeadDetail', params: { id: 'new' }, query: { client: clientId } })">
					{{ t('pipelinq', 'New lead') }}
				</NcButton>
			</template>

			<div v-if="leads.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No leads found') }}</p>
			</div>
			<template v-else>
				<div v-if="openLeads.length > 0" class="viewTableContainer">
					<table class="viewTable">
						<thead>
							<tr>
								<th>{{ t('pipelinq', 'Title') }}</th>
								<th>{{ t('pipelinq', 'Stage') }}</th>
								<th>{{ t('pipelinq', 'Value') }}</th>
								<th>{{ t('pipelinq', 'Assignee') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="lead in openLeads"
								:key="lead.id"
								class="viewTableRow"
								@click="$router.push({ name: 'LeadDetail', params: { id: lead.id } })">
								<td>{{ lead.title || '-' }}</td>
								<td>{{ lead.stage || '-' }}</td>
								<td>{{ lead.value ? 'EUR ' + Number(lead.value).toLocaleString('nl-NL') : '-' }}</td>
								<td>{{ lead.assignee || '-' }}</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div v-if="closedLeads.length > 0" class="closed-section">
					<button class="closed-toggle" @click="showClosedLeads = !showClosedLeads">
						{{ showClosedLeads ? t('pipelinq', 'Hide closed') : t('pipelinq', 'Show {count} closed', { count: closedLeads.length }) }}
					</button>
					<div v-if="showClosedLeads" class="viewTableContainer">
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
									v-for="lead in closedLeads"
									:key="lead.id"
									class="viewTableRow viewTableRow--muted"
									@click="$router.push({ name: 'LeadDetail', params: { id: lead.id } })">
									<td>{{ lead.title || '-' }}</td>
									<td>{{ lead.stage || '-' }}</td>
									<td>{{ lead.value ? 'EUR ' + Number(lead.value).toLocaleString('nl-NL') : '-' }}</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</template>
		</CnDetailCard>

		<CnDetailCard ref="requests" :title="t('pipelinq', 'Requests')">
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
	</CnDetailPage>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ClientForm from './ClientForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ClientDetail',
	components: {
		NcButton,
		NcDialog,
		CnDetailPage,
		CnDetailCard,
		ClientForm,
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
			showClosedLeads: false,
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
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},

		// Klantbeeld-360 computed properties
		openLeads() {
			const closedStages = ['won', 'gewonnen', 'lost', 'verloren', 'closed won', 'closed lost']
			return this.leads.filter(l => !closedStages.includes((l.stage || '').toLowerCase()))
		},
		closedLeads() {
			const closedStages = ['won', 'gewonnen', 'lost', 'verloren', 'closed won', 'closed lost']
			return this.leads.filter(l => closedStages.includes((l.stage || '').toLowerCase()))
		},
		openLeadsCount() {
			return this.openLeads.length
		},
		totalLeadValue() {
			let total = 0
			for (const lead of this.openLeads) {
				total += Number(lead.value) || 0
			}
			return total
		},
		openRequestsCount() {
			return this.requests.filter(r => !['completed', 'rejected', 'converted'].includes(r.status)).length
		},
		lastContactLabel() {
			// Use the most recent updated timestamp from any related entity
			const dates = []
			for (const r of this.requests) {
				if (r.updated) dates.push(new Date(r.updated))
				if (r.created) dates.push(new Date(r.created))
			}
			if (dates.length === 0) return t('pipelinq', 'None')
			dates.sort((a, b) => b - a)
			const lastDate = dates[0]
			const diffDays = Math.floor((Date.now() - lastDate.getTime()) / 86400000)
			if (diffDays === 0) return t('pipelinq', 'Today')
			if (diffDays === 1) return t('pipelinq', 'Yesterday')
			return t('pipelinq', '{count}d ago', { count: diffDays })
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
		},
		createRequest() {
			this.$router.push({ name: 'RequestDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		addContact() {
			this.$router.push({ name: 'ContactDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		scrollToSection(refName) {
			const el = this.$refs[refName]?.$el || this.$refs[refName]
			if (el) {
				el.scrollIntoView({ behavior: 'smooth', block: 'start' })
			}
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

/* Klantbeeld-360 summary stats */
.klantbeeld-stats {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 12px;
	margin-bottom: 20px;
	padding: 0 20px;
}

@media (max-width: 768px) {
	.klantbeeld-stats {
		grid-template-columns: repeat(2, 1fr);
	}
}

.stat-tile {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 12px 16px;
	text-align: center;
	cursor: pointer;
	transition: box-shadow 0.15s;
}

.stat-tile:hover {
	box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.stat-tile__value {
	font-size: 20px;
	font-weight: 700;
}

.stat-tile__label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.5px;
}

.stat-tile__sub {
	font-size: 12px;
	color: var(--color-success);
	font-weight: 600;
	margin-top: 2px;
}

/* Closed leads toggle */
.closed-section {
	margin-top: 12px;
}

.closed-toggle {
	background: none;
	border: none;
	color: var(--color-primary);
	cursor: pointer;
	font-size: 13px;
	padding: 4px 0;
	margin-bottom: 8px;
}

.closed-toggle:hover {
	text-decoration: underline;
}

.viewTableRow--muted {
	opacity: 0.6;
}
</style>

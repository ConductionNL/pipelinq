<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-4.1 -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-4.2 -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-4.3 -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-4.4 -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-4.5 -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-4.6 -->
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
		:sidebar="{ enabled: !isNew && !loading }"
		object-type="pipelinq_client"
		:object-id="clientId"
		:sidebar-props="sidebarProps"
		:sidebar-tabs="sidebarTabs">
		<template #actions>
			<NcButton type="primary" @click="editing = true">
				{{ t('pipelinq', 'Edit') }}
			</NcButton>
			<NcButton type="error" @click="showDeleteWarning">
				{{ t('pipelinq', 'Delete') }}
			</NcButton>
		</template>

		<!--
			Identity — denormalised read-only mirror of the linked Nextcloud
			Contact (CROSS-APP INTERFACE CONTRACT #2). The contact is authoritative;
			editing identity deep-links to the addressbook (pipelinq-unify-client-contact
			REQ-PUCC-001 / REQ-PUCC-004).
		-->
		<CnDetailCard :title="t('pipelinq', 'Identity')">
			<template #actions>
				<NcButton
					v-if="clientData.contactsUid"
					@click="editIdentityInContacts">
					{{ t('pipelinq', 'Edit in Contacts') }}
				</NcButton>
			</template>

			<div v-if="clientData.contactsUid" class="sync-badge">
				{{ t('pipelinq', 'Identity from Nextcloud Contacts') }}
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
			<p class="identity-hint">
				{{ t('pipelinq', 'Name, email, phone, address and website are sourced from the linked Nextcloud contact and are read-only here. Edit them in Contacts.') }}
			</p>
		</CnDetailCard>

		<!--
			Account / relationship — the CRM-specific fields that make this a
			relationship record rather than an identity card (REQ-PUCC-001).
		-->
		<CnDetailCard :title="t('pipelinq', 'Account & relationship')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Lifecycle stage') }}</label>
					<span>{{ clientData.lifecycleStage || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Segment') }}</label>
					<span>{{ clientData.segment || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Account owner') }}</label>
					<span>{{ clientData.accountOwner || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Account status') }}</label>
					<span>{{ clientData.accountStatus || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Industry') }}</label>
					<span>{{ clientData.industry || '-' }}</span>
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
				<div class="summary-item">
					<span class="summary-value summary-value--date">{{ clientSince ? formatDate(clientSince) : '-' }}</span>
					<span class="summary-label">{{ t('pipelinq', 'Client since') }}</span>
				</div>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Contacts')">
			<template #actions>
				<NcButton @click="addContact">
					{{ t('pipelinq', 'Add contact') }}
				</NcButton>
			</template>

			<NcLoadingIcon v-if="sectionLoading.contacts" :size="24" />
			<div v-else-if="sectionError.contacts" class="section-error">
				<p>{{ sectionError.contacts }}</p>
			</div>
			<div v-else-if="contacts.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No contacts linked') }}</p>
				<NcButton type="secondary" @click="addContact">
					{{ t('pipelinq', 'Add Contact') }}
				</NcButton>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Name') }}</th>
							<th>{{ t('pipelinq', 'Role') }}</th>
							<th>{{ t('pipelinq', 'Email') }}</th>
							<th>{{ t('pipelinq', 'Phone') }}</th>
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
							<td>{{ contact.phone || '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Leads')">
			<NcLoadingIcon v-if="sectionLoading.leads" :size="24" />
			<div v-else-if="sectionError.leads" class="section-error">
				<p>{{ sectionError.leads }}</p>
			</div>
			<div v-else-if="recentLeads.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No leads linked') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Title') }}</th>
							<th>{{ t('pipelinq', 'Stage') }}</th>
							<th>{{ t('pipelinq', 'Value') }}</th>
							<th>{{ t('pipelinq', 'Probability') }}</th>
							<th>{{ t('pipelinq', 'Expected close') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="lead in recentLeads"
							:key="lead.id"
							class="viewTableRow"
							@click="$router.push({ name: 'LeadDetail', params: { id: lead.id } })">
							<td>{{ lead.title || '-' }}</td>
							<td>{{ lead.stage || '-' }}</td>
							<td>{{ formatCurrency(lead.value) }}</td>
							<td>{{ formatProbability(lead.probability) }}</td>
							<td>{{ formatDate(lead.expectedCloseDate) }}</td>
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

			<NcLoadingIcon v-if="sectionLoading.requests" :size="24" />
			<div v-else-if="sectionError.requests" class="section-error">
				<p>{{ sectionError.requests }}</p>
			</div>
			<div v-else-if="recentRequests.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No requests linked') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Title') }}</th>
							<th>{{ t('pipelinq', 'Status') }}</th>
							<th>{{ t('pipelinq', 'Priority') }}</th>
							<th>{{ t('pipelinq', 'Requested') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="request in recentRequests"
							:key="request.id"
							class="viewTableRow"
							@click="$router.push({ name: 'RequestDetail', params: { id: request.id } })">
							<td>{{ request.title || '-' }}</td>
							<td>{{ request.status || '-' }}</td>
							<td>{{ request.priority || '-' }}</td>
							<td>{{ formatDate(request.requestedAt || request._dateCreated) }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<!-- Projecten — every project referencing this client. project-task-hierarchy / REQ-PTH-009 Scenario 34. -->
		<CnDetailCard :title="t('pipelinq', 'Projecten')">
			<NcLoadingIcon v-if="sectionLoading.projects" :size="24" />
			<div v-else-if="sectionError.projects" class="section-error">
				<p>{{ sectionError.projects }}</p>
			</div>
			<div v-else-if="recentProjects.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No projects linked') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Name') }}</th>
							<th>{{ t('pipelinq', 'Status') }}</th>
							<th>{{ t('pipelinq', 'Billable') }}</th>
							<th>{{ t('pipelinq', 'Budget hours') }}</th>
							<th>{{ t('pipelinq', 'End date') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="project in recentProjects"
							:key="project.id"
							class="viewTableRow"
							@click="$router.push({ name: 'ProjectDetail', params: { id: project.id } })">
							<td>{{ project.name || '-' }}</td>
							<td>{{ project.status || '-' }}</td>
							<td>{{ project.billable === false ? t('pipelinq', 'Niet-factureerbaar') : t('pipelinq', 'Factureerbaar') }}</td>
							<td>{{ project.budgetHours || 0 }}u</td>
							<td>{{ formatDate(project.endDate) }}</td>
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

			<NcLoadingIcon v-if="sectionLoading.contactmomenten" :size="24" />
			<div v-else-if="sectionError.contactmomenten" class="section-error">
				<p>{{ sectionError.contactmomenten }}</p>
			</div>
			<div v-else-if="recentContactmomenten.length === 0" class="section-empty">
				<p>{{ t('pipelinq', 'No contactmomenten') }}</p>
			</div>
			<div v-else class="viewTableContainer">
				<table class="viewTable">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Subject') }}</th>
							<th>{{ t('pipelinq', 'Channel') }}</th>
							<th>{{ t('pipelinq', 'Date') }}</th>
							<th>{{ t('pipelinq', 'Agent') }}</th>
							<th>{{ t('pipelinq', 'Outcome') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="cm in recentContactmomenten"
							:key="cm.id"
							class="viewTableRow"
							@click="$router.push({ name: 'ContactmomentDetail', params: { id: cm.id } })">
							<td>{{ cm.subject || '-' }}</td>
							<td>{{ cm.channel || '-' }}</td>
							<td>{{ formatDate(cm.contactedAt) }}</td>
							<td>{{ cm.agent || '-' }}</td>
							<td>{{ cm.outcome || '-' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</CnDetailCard>

		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Activity')">
			<ActivityTimeline :entity-type="'client'" :entity-id="clientId" />
		</CnDetailCard>

		<!--
			Communication History — paginated contactmoment feed for this entity.
			@spec openspec/changes/entity-notes/tasks.md#task-6.1
		-->
		<CommunicationHistory
			v-if="!isNew && !loading && !editing"
			entity-type="client"
			:entity-id="clientId" />

		<!--
			Bookings timeline — appointment-booking REQ-APT-014.
			@spec openspec/changes/appointment-booking-11-admin-ui/specs/appointment-booking/spec.md#REQ-APT-014
		-->
		<BookingsCard
			v-if="!isNew"
			:customer-id="clientId" />

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
import { NcButton, NcDialog, NcLoadingIcon } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard } from '@conduction/nextcloud-vue'
import ClientForm from './ClientForm.vue'
import ContactRelationships from '../../components/ContactRelationships.vue'
import ContactmomentQuickLog from '../../components/ContactmomentQuickLog.vue'
import ActivityTimeline from '../../components/ActivityTimeline.vue'
import CommunicationHistory from '../../components/CommunicationHistory.vue'
import BookingsCard from '../../components/bookings/BookingsCard.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { createWithContact } from '../../services/contactSyncApi.js'

export default {
	name: 'ClientDetail',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		CnDetailPage,
		CnDetailCard,
		ClientForm,
		ContactRelationships,
		ContactmomentQuickLog,
		ActivityTimeline,
		CommunicationHistory,
		BookingsCard,
	},
	props: {
		/**
		 * The route param `:id` is forwarded by CnPageRenderer as `id`.
		 * `clientIdProp` keeps the old prop name reachable for direct
		 * usage (legacy callers) while the rest of the component uses
		 * the `clientId` computed alias below.
		 */
		id: {
			type: String,
			default: null,
		},
		clientIdProp: {
			type: String,
			default: null,
		},
		/**
		 * Manifest `config.sidebar` block, spread in by CnPageRenderer.
		 * Read only for its `tabs[]` (forwarded to CnDetailPage via
		 * `sidebar-tabs`); the boolean `:sidebar` binding below still
		 * gates whether the sidebar shows. Object form:
		 * `{ enabled, showMetadata, tabs }`.
		 *
		 * @type {boolean|object|null}
		 */
		sidebar: {
			type: [Boolean, Object],
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
			/**
			 * Projects linked to this client (project-task-hierarchy
			 * REQ-PTH-009 Scenario 34). Fetched alongside the other
			 * cross-schema relations so a slow project query never
			 * blocks the other client sections.
			 */
			projects: [],
			showDelete: false,
			showContactmomentQuickLog: false,
			/**
			 * Per-section loading flags so each relation fetch shows its
			 * own indicator instead of blocking the whole page on the
			 * slowest call (REQ-KB360-006).
			 */
			sectionLoading: {
				contacts: false,
				leads: false,
				contactmomenten: false,
				requests: false,
				complaints: false,
				projects: false,
			},
			/**
			 * Per-section error messages — a failure in one fetch must NOT
			 * prevent other sections from rendering (REQ-KB360-006).
			 */
			sectionError: {
				contacts: null,
				leads: null,
				contactmomenten: null,
				requests: null,
				complaints: null,
				projects: null,
			},
		}
	},
	computed: {
		/**
		 * Resolved client UUID — prefers the renderer-forwarded `:id` route
		 * param, then the legacy `clientIdProp`, then null. Centralised so
		 * the rest of the component reads ONE source of truth and is
		 * agnostic to how the value arrived.
		 *
		 * @return {?string}
		 */
		clientId() {
			return this.id || this.clientIdProp || null
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-13
		 */
		objectStore() {
			return useObjectStore()
		},
		isNew() {
			return !this.clientId || this.clientId === 'new'
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-11
		 */
		loading() {
			return this.objectStore.loading.client || false
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-4
		 */
		clientData() {
			if (this.isNew) return {}
			return this.objectStore.getObject('client', this.clientId) || {}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-21
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry.client || {}
			return {
				title: t('pipelinq', 'Client'),
				register: config.register || '',
				schema: config.schema || '',
				hiddenTabs: ['tasks'],
			}
		},
		/**
		 * Integration sidebar tabs declared in the manifest
		 * (`config.sidebar.tabs`), forwarded to CnDetailPage which
		 * publishes them to the host CnObjectSidebar. Empty when the
		 * manifest declares none.
		 *
		 * @return {Array<object>}
		 */
		sidebarTabs() {
			return (this.sidebar && typeof this.sidebar === 'object' && Array.isArray(this.sidebar.tabs))
				? this.sidebar.tabs
				: []
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-17
		 */
		openLeadsCount() {
			return this.leads.filter(l => !this.isClosedLead(l)).length
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-18
		 */
		openLeadsValue() {
			return this.leads
				.filter(l => !this.isClosedLead(l))
				.reduce((sum, l) => sum + (parseFloat(l.value) || 0), 0)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-24
		 */
		wonLeadsCount() {
			return this.leads.filter(l => l.status === 'won').length
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-25
		 */
		wonLeadsValue() {
			return this.leads
				.filter(l => l.status === 'won')
				.reduce((sum, l) => sum + (parseFloat(l.value) || 0), 0)
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-19
		 */
		openRequestsCount() {
			return this.requests.filter(r => r.status === 'new' || r.status === 'in_progress').length
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-23
		 */
		totalValue() {
			return this.openLeadsValue + this.wonLeadsValue
		},
		/**
		 * @spec openspec/changes/2026-03-20-client-management/tasks.md#task-1.1
		 */
		clientSince() {
			return this.clientData.createdAt || null
		},
		/**
		 * Up to 10 leads sorted by `updatedAt` descending (REQ-KB360-002).
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-002
		 */
		recentLeads() {
			return this.sortByDateDesc(this.leads, 'updatedAt').slice(0, 10)
		},
		/**
		 * Up to 10 contactmomenten sorted by `contactedAt` desc (REQ-KB360-003).
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-003
		 */
		recentContactmomenten() {
			return this.sortByDateDesc(this.contactmomenten, 'contactedAt').slice(0, 10)
		},
		/**
		 * Up to 5 requests sorted by `requestedAt` desc (REQ-KB360-004).
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-004
		 */
		recentRequests() {
			return this.sortByDateDesc(this.requests, 'requestedAt').slice(0, 5)
		},
		/**
		 * Up to 10 projects sorted by `_dateCreated` desc (project-task-hierarchy
		 * REQ-PTH-009 Scenario 34). The Projecten card surfaces every project
		 * referencing this client, regardless of status.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-009
		 */
		recentProjects() {
			return this.sortByDateDesc(this.projects, '_dateCreated').slice(0, 10)
		},
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-12
	 */
	async mounted() {
		if (!this.isNew) {
			await this.objectStore.fetchObject('client', this.clientId)
			await this.fetchRelated()
		}
	},
	methods: {
		/**
		 * @param formData
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-16
		 */
		async onFormSave(formData) {
			// Contact-FIRST create: the `client` schema marks `contactsUid`
			// REQUIRED (the authoritative identity is the NC addressbook contact,
			// never minted locally), so a plain saveObject('client', …) 400s on a
			// new client. Route NEW clients through the backend orchestration that
			// provisions the NC contact and saves with the resolved contactsUid;
			// EDITS keep the existing update-then-writeback flow (the contactsUid
			// already exists and is mirrored).
			if (this.isNew) {
				try {
					const created = await createWithContact('client', formData)
					const id = created?.id ?? created?.['@self']?.id
					if (id) {
						this.$router.push({ name: 'ClientDetail', params: { id } })
						return
					}
					showError(t('pipelinq', 'Failed to save client. Please try again.'))
				} catch (error) {
					const message = error?.response?.data?.error
					showError(message || t('pipelinq', 'Failed to save client. Please try again.'))
				}
				return
			}

			const result = await this.objectStore.saveObject('client', formData)
			if (result) {
				this.syncToContacts(result.id || this.clientId)
				await this.objectStore.fetchObject('client', this.clientId)
				this.editing = false
			} else {
				const error = this.objectStore.getError('client')
				showError(error?.message || t('pipelinq', 'Failed to save client. Please try again.'))
			}
		},
		/**
		 * @param objectId
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-22
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
					body: JSON.stringify({ objectType: 'client', objectId }),
				})
			} catch {
				// Sync failure is non-blocking
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-15
		 */
		onFormCancel() {
			if (this.isNew) {
				this.$router.push({ name: 'Clients' })
			} else {
				this.editing = false
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-20
		 */
		showDeleteWarning() {
			this.showDelete = true
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-5
		 */
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
		/**
		 * Fetch all relation collections in parallel so a slow contactmomenten
		 * page does not block leads from showing. Each section toggles its own
		 * `sectionLoading` flag and writes its own error to `sectionError` on
		 * failure — one bad call NEVER prevents the others from rendering
		 * (REQ-KB360-006).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-006
		 */
		async fetchRelated() {
			const tasks = [
				this.loadSection('contacts', 'contact', {
					_limit: 50,
					client: this.clientId,
				}, this.t('pipelinq', 'Failed to load contacts')),
				this.loadSection('leads', 'lead', {
					_limit: 50,
					client: this.clientId,
				}, this.t('pipelinq', 'Failed to load leads')),
				this.loadSection('requests', 'request', {
					_limit: 50,
					client: this.clientId,
				}, this.t('pipelinq', 'Failed to load requests')),
				this.loadSection('contactmomenten', 'contactmoment', {
					_limit: 50,
					client: this.clientId,
					_order: { contactedAt: 'desc' },
				}, this.t('pipelinq', 'Failed to load contactmomenten')),
				this.loadSection('complaints', 'complaint', {
					_limit: 50,
					client: this.clientId,
					_order: { _dateCreated: 'desc' },
				}, this.t('pipelinq', 'Failed to load complaints')),
				// project-task-hierarchy REQ-PTH-009 Scenario 34: surface
				// projects on this client. fetchUsed semantics — collection
				// filtered by `client` matching this clientId.
				this.loadSection('projects', 'project', {
					_limit: 50,
					client: this.clientId,
					_order: { _dateCreated: 'desc' },
				}, this.t('pipelinq', 'Failed to load projects')),
			]
			// allSettled — one rejected fetch must NOT cancel the others.
			await Promise.allSettled(tasks)
		},
		/**
		 * Generic per-section loader: toggles `sectionLoading[key]`, fetches the
		 * collection, writes the result into `this[key]`, and records a
		 * user-facing error into `sectionError[key]` on failure.
		 *
		 * @param {string} key       Data property + section-state key (e.g. 'leads').
		 * @param {string} schema    Object-store schema slug (e.g. 'lead').
		 * @param {object} params    fetchCollection params.
		 * @param {string} errorMsg  User-facing fallback message.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-006
		 */
		async loadSection(key, schema, params, errorMsg) {
			this.sectionLoading[key] = true
			this.sectionError[key] = null
			try {
				const data = await this.objectStore.fetchCollection(schema, params)
				this[key] = data || []
			} catch (e) {
				this[key] = []
				this.sectionError[key] = errorMsg
				// eslint-disable-next-line no-console
				console.warn('[ClientDetail] section fetch failed', key, e)
			} finally {
				this.sectionLoading[key] = false
			}
		},
		/**
		 * Sort an array of objects by an ISO-date string property descending.
		 * Missing values sort last (treated as epoch).
		 *
		 * @param {Array<object>} rows Source rows.
		 * @param {string} field       Field name holding an ISO-date string.
		 * @return {Array<object>}
		 */
		sortByDateDesc(rows, field) {
			return [...rows].sort((a, b) => {
				const ta = a && a[field] ? Date.parse(a[field]) : 0
				const tb = b && b[field] ? Date.parse(b[field]) : 0
				return (tb || 0) - (ta || 0)
			})
		},
		/**
		 * Format a lead.probability value as a percentage string. Returns
		 * `-` when undefined / null so the table never renders `NaN%`.
		 *
		 * @param {*} value Raw probability (0..100).
		 * @return {string}
		 */
		formatProbability(value) {
			if (value === undefined || value === null || value === '') return '-'
			const n = Number(value)
			if (Number.isNaN(n)) return '-'
			return Math.round(n) + '%'
		},
		/**
		 * @param dateStr
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-10
		 */
		formatDate(dateStr) {
			if (!dateStr) return '-'
			try {
				return new Date(dateStr).toLocaleDateString()
			} catch {
				return dateStr
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-14
		 */
		async onContactmomentSaved() {
			this.showContactmomentQuickLog = false
			await this.fetchRelated()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-7
		 */
		createRequest() {
			this.$router.push({ name: 'RequestDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-3
		 */
		addContact() {
			this.$router.push({ name: 'ContactDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		/**
		 * Deep-link to the Nextcloud Contacts app for the linked contact so the
		 * user can edit the authoritative identity there. Identity is a Nextcloud
		 * Contact keyed by `contactsUid` (CROSS-APP INTERFACE CONTRACT #2); the
		 * pipelinq mirror is read-only.
		 *
		 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-004
		 */
		editIdentityInContacts() {
			const uid = this.clientData.contactsUid
			if (!uid) return
			window.open(generateUrl('/apps/contacts/All contacts/{uid}', { uid }), '_blank', 'noopener')
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-6
		 */
		createComplaint() {
			this.$router.push({ name: 'ComplaintDetail', params: { id: 'new' }, query: { client: this.clientId } })
		},
		isClosedLead(lead) {
			return lead.status === 'won' || lead.status === 'lost'
		},
		/**
		 * Format a numeric value as Dutch-locale EUR (e.g. `€ 42.000`).
		 * `0` and missing values render as `€ 0` (not blank — REQ-KB360-001).
		 *
		 * @param {*} value Raw amount.
		 * @return {string}
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-9
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-001
		 */
		formatCurrency(value) {
			const n = Number(value) || 0
			try {
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: 'EUR',
					maximumFractionDigits: 0,
				}).format(n)
			} catch {
				return '€ ' + n
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
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
}

.section-error {
	text-align: center;
	color: var(--color-error);
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

.identity-hint {
	margin: 12px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.summary-grid {
	display: grid;
	grid-template-columns: repeat(5, 1fr);
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

.summary-value--date {
	font-size: 14px;
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

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-5.1 -->
<!-- @spec openspec/changes/klantbeeld-360/tasks.md#task-5.2 -->
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
		:sidebar="{ enabled: !isNew && !loading }"
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

		<!--
			Contact-person role record. `role` and the parent-account link are the
			pipelinq-authoritative fields; identity (name/email/phone) is a
			denormalised read-only mirror of the linked Nextcloud Contact
			(CROSS-APP INTERFACE CONTRACT #2 / pipelinq-unify-client-contact
			REQ-PUCC-002 / REQ-PUCC-004). Edit identity in the addressbook.
		-->
		<CnDetailCard :title="t('pipelinq', 'Contact person')">
			<template #actions>
				<NcButton
					v-if="contactData.contactsUid"
					@click="editIdentityInContacts">
					{{ t('pipelinq', 'Edit in Contacts') }}
				</NcButton>
			</template>

			<div v-if="contactData.contactsUid" class="sync-badge">
				{{ t('pipelinq', 'Identity from Nextcloud Contacts') }}
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
			<p class="identity-hint">
				{{ t('pipelinq', 'Name, email and phone are sourced from the linked Nextcloud contact and are read-only here. Edit them in Contacts. Role and the linked organisation are managed here.') }}
			</p>
		</CnDetailCard>

		<!-- Parent Organisation — REQ-KB360-030 / REQ-KB360-031 -->
		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Parent Organisation')">
			<div v-if="contactData.client" class="parent-org">
				<div class="parent-org__name">
					<a
						href="#"
						class="parent-org__link"
						@click.prevent="$router.push({ name: 'ClientDetail', params: { id: contactData.client } })">
						{{ clientName }}
					</a>
				</div>
				<div class="parent-org__type">
					{{ clientType || '-' }}
				</div>
			</div>
			<div v-else class="parent-org__empty">
				<p>{{ t('pipelinq', 'No organisation linked') }}</p>
				<NcButton type="secondary" @click="openLinkDialog">
					{{ t('pipelinq', 'Link to Organisation') }}
				</NcButton>
			</div>
		</CnDetailCard>

		<!-- BSN / BRP — bsn-validatie-en-brp-lookup -->
		<BrpContactPanel
			v-if="!isNew"
			:contact-id="contactId"
			@contact-updated="reloadContact" />

		<!-- Relationships -->
		<CnDetailCard v-if="!isNew" :title="t('pipelinq', 'Relationships')">
			<ContactRelationships
				:entity-id="contactId"
				entity-type="contact"
				:entity-name="contactData.name || ''" />
		</CnDetailCard>

		<!--
			Communication History — paginated contactmoment feed for this entity.
			@spec openspec/changes/entity-notes/tasks.md#task-6.2
		-->
		<CommunicationHistory
			v-if="!isNew && !loading && !editing"
			entity-type="contact"
			:entity-id="contactId" />

		<!-- Organisation link dialog — REQ-KB360-032 -->
		<CnFormDialog
			v-if="showLinkDialog"
			ref="linkDialog"
			:dialog-title="t('pipelinq', 'Link to Organisation')"
			:fields="linkDialogFields"
			:confirm-label="t('pipelinq', 'Link')"
			:cancel-label="t('pipelinq', 'Cancel')"
			:success-text="t('pipelinq', 'Organisation linked.')"
			name-field="client"
			@confirm="onLinkConfirm"
			@close="showLinkDialog = false" />
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard, CnFormDialog } from '@conduction/nextcloud-vue'
import ContactForm from './ContactForm.vue'
import ContactRelationships from '../../components/ContactRelationships.vue'
import CommunicationHistory from '../../components/CommunicationHistory.vue'
import BrpContactPanel from '../../components/BrpContactPanel.vue'
import { useObjectStore } from '../../store/modules/object.js'
import { createWithContact } from '../../services/contactSyncApi.js'

export default {
	name: 'ContactDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnFormDialog,
		ContactForm,
		ContactRelationships,
		CommunicationHistory,
		BrpContactPanel,
	},
	props: {
		/**
		 * Route param `:id` forwarded by CnPageRenderer (manifest v2).
		 * `contactIdProp` keeps the legacy prop name accessible without
		 * renaming every call site that pushed `params: { id }`.
		 */
		id: {
			type: String,
			default: null,
		},
		contactIdProp: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			editing: false,
			clientName: '-',
			clientType: '',
			showLinkDialog: false,
			availableClients: [],
		}
	},
	computed: {
		/**
		 * Resolved contact UUID — prefers the renderer-forwarded `:id`
		 * route param, then the legacy `contactIdProp`.
		 *
		 * @return {?string}
		 */
		contactId() {
			return this.id || this.contactIdProp || null
		},
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
		 * CnFormDialog field definition for the organisation linker.
		 * Single async-loaded select backed by `loadClientOptions()`.
		 *
		 * @return {Array<object>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-032
		 */
		linkDialogFields() {
			return [
				{
					key: 'client',
					label: this.t('pipelinq', 'Select organisation'),
					description: this.t('pipelinq', 'Choose a client organisation to link this contact to.'),
					widget: 'select',
					required: true,
					enum: this.loadClientOptions,
				},
			]
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
		 * Refresh the contact object from the store after a BRP lookup.
		 *
		 * The BRP lookup updates Contact.verifiedBSN / brpPersoonId / geheimhouding
		 * server-side; we re-fetch so the UI reflects the new values.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.5
		 */
		async reloadContact() {
			if (this.contactId && this.contactId !== 'new') {
				try {
					await this.objectStore.fetchObject('contact', this.contactId)
				} catch (err) {
					// Best-effort refresh; lookups still succeed even if reload fails.
				}
			}
		},
		/**
		 * Load the linked client's display name and type for the Parent
		 * Organisation card (REQ-KB360-030).
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-32
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-030
		 */
		async loadClientName() {
			const clientId = this.contactData.client
			if (!clientId) {
				this.clientName = '-'
				this.clientType = ''
				return
			}
			try {
				const client = await this.objectStore.fetchObject('client', clientId)
				this.clientName = client?.name || this.t('pipelinq', '[Deleted client]')
				this.clientType = client?.type || ''
			} catch {
				this.clientName = this.t('pipelinq', '[Deleted client]')
				this.clientType = ''
			}
		},
		/**
		 * Open the organisation linker dialog (REQ-KB360-031).
		 *
		 * @return {void}
		 */
		openLinkDialog() {
			this.showLinkDialog = true
		},
		/**
		 * Async option loader for the CnFormDialog client select. Returns
		 * the matching clients as label/value pairs; the schema-driven
		 * dialog stores the chosen option in formData.
		 *
		 * @param {string} query Search query (free-text, may be empty).
		 * @return {Promise<Array<{label: string, value: string}>>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-032
		 */
		async loadClientOptions(query) {
			try {
				const params = { _limit: 50 }
				if (query) {
					params._search = query
				}
				const clients = await this.objectStore.fetchCollection('client', params) || []
				this.availableClients = clients
				return clients.map(c => ({
					label: c.name || c.id,
					value: c.id,
				}))
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('[ContactDetail] loadClientOptions failed', e)
				return []
			}
		},
		/**
		 * CnFormDialog confirm handler. Persists `contact.client` via the
		 * object store and refreshes the Parent Organisation card.
		 *
		 * @param {object} formData Submitted form data (`{ client: {label,value} }`).
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/klantbeeld-360/specs/klantbeeld-360/spec.md#REQ-KB360-032
		 */
		async onLinkConfirm(formData) {
			const selected = formData?.client
			const clientId = selected && typeof selected === 'object' ? selected.value : selected
			if (!clientId) {
				if (this.$refs.linkDialog) {
					this.$refs.linkDialog.setResult({ error: this.t('pipelinq', 'Select organisation') })
				}
				return
			}
			try {
				const payload = { ...this.contactData, client: clientId }
				const result = await this.objectStore.saveObject('contact', payload)
				if (!result) {
					throw new Error('saveObject returned falsy')
				}
				await this.objectStore.fetchObject('contact', this.contactId)
				await this.loadClientName()
				showSuccess(this.t('pipelinq', 'Organisation linked.'))
				if (this.$refs.linkDialog) {
					this.$refs.linkDialog.setResult({ success: true })
				}
				this.showLinkDialog = false
			} catch (e) {
				// eslint-disable-next-line no-console
				console.warn('[ContactDetail] link save failed', e)
				const msg = this.t('pipelinq', 'Failed to link organisation. Please try again.')
				showError(msg)
				if (this.$refs.linkDialog) {
					this.$refs.linkDialog.setResult({ error: msg })
				}
			}
		},
		/**
		 * @param formData
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-37
		 */
		async onFormSave(formData) {
			// Contact-FIRST create: the `contact` schema marks `contactsUid`
			// REQUIRED (the authoritative identity is the NC addressbook contact,
			// never minted locally), so a plain saveObject('contact', …) 400s on a
			// new contact. Route NEW contacts through the backend orchestration that
			// provisions the NC contact and saves with the resolved contactsUid;
			// EDITS keep the existing update-then-writeback flow (the contactsUid
			// already exists and is mirrored).
			if (this.isNew) {
				try {
					const created = await createWithContact('contact', formData)
					const id = created?.id ?? created?.['@self']?.id
					if (id) {
						this.$router.push({ name: 'ContactDetail', params: { id } })
						return
					}
					showError(t('pipelinq', 'Failed to save contact. Please try again.'))
				} catch (error) {
					const message = error?.response?.data?.error
					showError(message || t('pipelinq', 'Failed to save contact. Please try again.'))
				}
				return
			}

			const result = await this.objectStore.saveObject('contact', formData)
			if (result) {
				this.syncToContacts(result.id || this.contactId)
				await this.objectStore.fetchObject('contact', this.contactId)
				this.loadClientName()
				this.editing = false
			} else {
				const error = this.objectStore.getError('contact')
				showError(error?.message || t('pipelinq', 'Failed to save contact. Please try again.'))
			}
		},
		/**
		 * @param objectId
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
		 * Deep-link to the Nextcloud Contacts app for the linked contact so the
		 * user edits the authoritative identity there. Identity is a Nextcloud
		 * Contact keyed by `contactsUid`; the pipelinq mirror is read-only.
		 *
		 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-004
		 */
		editIdentityInContacts() {
			const uid = this.contactData.contactsUid
			if (!uid) return
			window.open(generateUrl('/apps/contacts/All contacts/{uid}', { uid }), '_blank', 'noopener')
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

.identity-hint {
	margin: 12px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.client-link:hover {
	color: var(--color-primary-hover);
}

.sync-badge {
	display: inline-block;
	padding: 4px 10px;
	background: var(--color-success);
	color: var(--color-main-background);
	border: 1px solid var(--color-success);
	border-radius: 12px;
	font-size: 12px;
	font-weight: 600;
	margin-bottom: 16px;
}

.parent-org {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.parent-org__name {
	font-weight: 600;
}

.parent-org__link {
	color: var(--color-primary);
	cursor: pointer;
	text-decoration: underline;
}

.parent-org__link:hover {
	color: var(--color-primary-hover);
}

.parent-org__type {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.parent-org__empty {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 12px;
	padding: 12px 0;
	color: var(--color-text-maxcontrast);
}
</style>

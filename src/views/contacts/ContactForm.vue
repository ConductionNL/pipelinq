<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - ContactForm — contact-person create/edit form.
  -
  - ⚠️ THIS COMPONENT IS CURRENTLY UNMOUNTABLE. It is the last file left in
  - src/views/contacts/; its former siblings ContactList.vue and
  - ContactDetail.vue were removed when contacts moved to the manifest renderer
  - (`src/manifest.json` pages `Contacts` → /contacts and `ContactDetail` →
  - /contacts/:id, both schema-driven, neither naming a component). Nothing
  - imports this file: a repo-wide search for `ContactForm` outside the file
  - itself returns only openspec prose, and `grep -rn "views/contacts/" src/`
  - returns nothing at all. With no importer, no manifest entry and no router
  - entry, no URL on any instance can render it — so there is no page for a
  - Playwright test to navigate to, and no screen for a baseline to capture.
  -
  - The remedy is deletion or re-wiring, which is a product decision and is
  - raised separately rather than taken here.
  -
  - @visual exclude unreachable: zero importers in src/, no manifest page and no router entry name this component, so it is never mounted on any instance and no e2e route exists to drive it
  -->
<template>
	<div class="contact-form">
		<div class="form-group">
			<label for="contact-name">{{ t('pipelinq', 'Name') }} *</label>
			<NcTextField
				id="contact-name"
				label-outside
				:label="t('pipelinq', 'Name')"
				:model-value="form.name"
				:error="!!errors.name"
				:helper-text="errors.name"
				:maxlength="255"
				@update:model-value="
					(v) => {
						form.name = v
						validateField('name')
					}
				" />
		</div>

		<div class="form-group">
			<label for="contact-client">{{ t('pipelinq', 'Client') }} *</label>
			<NcSelect
				v-model="selectedClient"
				input-id="contact-client"
				:aria-label-combobox="t('pipelinq', 'Client')"
				:options="clientOptions"
				:placeholder="t('pipelinq', 'Search for a client...')"
				label="name"
				:reduce="(c) => c.id"
				@search="searchClients"
				@update:model-value="validateField('client')" />
			<p v-if="errors.client" class="field-error">
				{{ errors.client }}
			</p>
		</div>

		<div class="form-row">
			<div class="form-group">
				<label for="contact-role">{{ t('pipelinq', 'Role') }}</label>
				<NcTextField
					id="contact-role"
					label-outside
					:label="t('pipelinq', 'Role')"
					:model-value="form.role"
					@update:model-value="(v) => (form.role = v)" />
			</div>
			<div class="form-group">
				<label for="contact-email">{{ t('pipelinq', 'Email') }}</label>
				<NcTextField
					id="contact-email"
					label-outside
					:label="t('pipelinq', 'Email')"
					:model-value="form.email"
					:error="!!errors.email"
					:helper-text="errors.email"
					type="email"
					@update:model-value="
						(v) => {
							form.email = v
							validateField('email')
						}
					" />
			</div>
		</div>

		<div class="form-group">
			<label for="contact-phone">{{ t('pipelinq', 'Phone') }}</label>
			<NcTextField
				id="contact-phone"
				label-outside
				:label="t('pipelinq', 'Phone')"
				:model-value="form.phone"
				:error="!!errors.phone"
				:helper-text="errors.phone"
				@update:model-value="
					(v) => {
						form.phone = v
						validateField('phone')
					}
				" />
		</div>

		<div class="contact-form__actions">
			<NcButton variant="primary" :disabled="!isValid" @click="onSave">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcTextField, NcSelect } from '@nextcloud/vue'
import { useObjectStore } from '../../store/modules/object.js'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/

export default {
	name: 'ContactForm',
	components: {
		NcButton,
		NcTextField,
		NcSelect,
	},
	props: {
		contact: {
			type: Object,
			default: () => ({}),
		},
		preSelectedClient: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			form: {
				name: '',
				client: null,
				role: '',
				email: '',
				phone: '',
			},
			errors: {
				name: '',
				client: '',
				email: '',
				phone: '',
			},
			selectedClient: null,
			clientOptions: [],
			searchTimeout: null,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-46
		 */
		objectStore() {
			return useObjectStore()
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-43
		 */
		isValid() {
			const hasName = this.form.name.trim().length > 0
			const hasClient = !!this.selectedClient
			const noErrors = Object.values(this.errors).every((e) => !e)
			return hasName && hasClient && noErrors
		},
	},
	watch: {
		contact: {
			immediate: true,
			/**
			 * @param val
			 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-42
			 */
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},
		/**
		 * @param val
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-50
		 */
		selectedClient(val) {
			this.form.client = val
		},
	},
	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-45
	 */
	async mounted() {
		await this.loadInitialClients()
		if (this.preSelectedClient) {
			this.selectedClient = this.preSelectedClient
			await this.ensureClientInOptions(this.preSelectedClient)
		}
	},
	methods: {
		/**
		 * @param data
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-48
		 */
		populateForm(data) {
			this.form = {
				name: data.name || '',
				client: data.client || null,
				role: data.role || '',
				email: data.email || '',
				phone: data.phone || '',
			}
			this.selectedClient = data.client || null
			if (data.client) {
				this.ensureClientInOptions(data.client)
			}
			this.errors = { name: '', client: '', email: '', phone: '' }
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-44
		 */
		async loadInitialClients() {
			const clients = await this.objectStore.fetchCollection('client', {
				_limit: 50,
			})
			this.clientOptions = (clients || []).map((c) => ({
				id: c.id,
				name: c.name || c.id,
			}))
		},
		/**
		 * @param clientId
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-41
		 */
		async ensureClientInOptions(clientId) {
			if (!this.clientOptions.find((c) => c.id === clientId)) {
				try {
					const client = await this.objectStore.fetchObject(
						'client',
						clientId,
					)
					if (client) {
						this.clientOptions.push({
							id: client.id,
							name: client.name || client.id,
						})
					}
				} catch {
					// Client not found
				}
			}
		},
		/**
		 * @param query
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-49
		 */
		searchClients(query) {
			clearTimeout(this.searchTimeout)
			this.searchTimeout = setTimeout(async () => {
				if (query.length > 0) {
					const results = await this.objectStore.fetchCollection(
						'client',
						{
							_search: query,
							_limit: 20,
						},
					)
					this.clientOptions = (results || []).map((c) => ({
						id: c.id,
						name: c.name || c.id,
					}))
				} else {
					await this.loadInitialClients()
				}
			}, 300)
		},
		/**
		 * @param field
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-52
		 */
		validateField(field) {
			switch (field) {
				case 'name':
					if (!this.form.name.trim()) {
						this.errors.name = t('pipelinq', 'Name is required')
					} else {
						this.errors.name = ''
					}
					break
				case 'client':
					if (!this.selectedClient) {
						this.errors.client = t('pipelinq', 'Client is required')
					} else {
						this.errors.client = ''
					}
					break
				case 'email':
					if (this.form.email && !EMAIL_REGEX.test(this.form.email)) {
						this.errors.email = t('pipelinq', 'Invalid email format')
					} else {
						this.errors.email = ''
					}
					break
				case 'phone':
					if (this.form.phone && !PHONE_REGEX.test(this.form.phone)) {
						this.errors.phone = t('pipelinq', 'Invalid phone format')
					} else {
						this.errors.phone = ''
					}
					break
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-51
		 */
		validateAll() {
			this.validateField('name')
			this.validateField('client')
			this.validateField('email')
			this.validateField('phone')
			return this.isValid
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-47
		 */
		onSave() {
			if (!this.validateAll()) {
				return
			}
			const data = { ...this.form }
			if (this.contact?.id) {
				data.id = this.contact.id
			}
			this.$emit('save', data)
		},
	},
}
</script>

<style scoped>
.contact-form {
	max-width: 800px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.field-error {
	color: var(--color-error);
	font-size: 12px;
	margin-top: 4px;
}

.contact-form__actions {
	display: flex;
	gap: 12px;
	margin-top: 20px;
}
</style>

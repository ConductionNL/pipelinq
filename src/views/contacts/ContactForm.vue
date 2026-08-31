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
				labelOutside
				:label="t('pipelinq', 'Name')"
				:modelValue="form.name"
				:error="!!errors.name"
				:helperText="errors.name"
				:maxlength="255"
				@update:modelValue="
					(v) => {
						form.name = v
						validateField('name')
					}
				" />
		</div>

		<div class="form-group" data-testid="contact-form-client">
			<CnResourceSelect
				register="pipelinq"
				schema="client"
				labelField="name"
				inputId="contact-client"
				:modelValue="selectedClient || ''"
				:inputLabel="t('pipelinq', 'Client')"
				:placeholder="t('pipelinq', 'Select or create a client')"
				:preload="true"
				:allowCreate="!preSelectedClient"
				:createHandler="createClient"
				@update:modelValue="onClientSelected" />
			<p v-if="errors.client" class="field-error">
				{{ errors.client }}
			</p>
		</div>

		<ClientCreateDialog
			v-if="clientDialogOpen"
			@created="onClientCreated"
			@close="closeClientDialog" />

		<div class="form-row">
			<div class="form-group">
				<label for="contact-role">{{ t('pipelinq', 'Role') }}</label>
				<NcTextField
					id="contact-role"
					labelOutside
					:label="t('pipelinq', 'Role')"
					:modelValue="form.role"
					@update:modelValue="(v) => (form.role = v)" />
			</div>
			<div class="form-group">
				<label for="contact-email">{{ t('pipelinq', 'Email') }}</label>
				<NcTextField
					id="contact-email"
					labelOutside
					:label="t('pipelinq', 'Email')"
					:modelValue="form.email"
					:error="!!errors.email"
					:helperText="errors.email"
					type="email"
					@update:modelValue="
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
				labelOutside
				:label="t('pipelinq', 'Phone')"
				:modelValue="form.phone"
				:error="!!errors.phone"
				:helperText="errors.phone"
				@update:modelValue="
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
import { CnResourceSelect } from '@conduction/nextcloud-vue'
import { NcButton, NcTextField } from '@nextcloud/vue'
import ClientCreateDialog from '../../dialogs/ClientCreateDialog.vue'
import { useObjectStore } from '../../store/modules/object.js'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/

export default {
	name: 'ContactForm',
	components: {
		ClientCreateDialog,
		CnResourceSelect,
		NcButton,
		NcTextField,
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

	emits: ['cancel', 'save'],

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

			// Inline-create plumbing: the picker hands control to the full
			// client dialog and resumes with whatever it resolves.
			clientDialogOpen: false,
			resolveCreate: null,
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
			 * @param {object} val The incoming value.
			 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-42
			 */
			handler(val) {
				if (val && Object.keys(val).length > 0) {
					this.populateForm(val)
				}
			},
		},

		/**
		 * @param {string} val Identifier of the newly selected client.
		 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-50
		 */
		selectedClient(val) {
			this.form.client = val
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-contacts-ui/tasks.md#task-45
	 */
	mounted() {
		if (this.preSelectedClient) {
			this.selectedClient = this.preSelectedClient
			this.form.client = this.preSelectedClient
		}
	},

	methods: {
		/**
		 * @param {object} data The contact to load into the form.
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
			this.errors = { name: '', client: '', email: '', phone: '' }
		},

		/**
		 * Keep the form and the picker in step, and re-validate.
		 *
		 * @param {string} value The chosen client uuid, or '' when cleared.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		onClientSelected(value) {
			this.selectedClient = value || null
			this.validateField('client')
		},

		/**
		 * CnResourceSelect create hook. `client` marks `contactsUid` REQUIRED
		 * and it is minted server-side, so the typed name opens the full create
		 * dialog rather than being saved on its own.
		 *
		 * @return {Promise<object|null>} The created client, or null if cancelled.
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		createClient() {
			return new Promise((resolve) => {
				this.resolveCreate = resolve
				this.clientDialogOpen = true
			})
		},

		/**
		 * @param {string} id The created client's uuid.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		onClientCreated(id) {
			this.clientDialogOpen = false
			const resolve = this.resolveCreate
			this.resolveCreate = null
			if (resolve) resolve(id ? { id } : null)
		},

		/**
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		closeClientDialog() {
			this.clientDialogOpen = false
			const resolve = this.resolveCreate
			this.resolveCreate = null
			if (resolve) resolve(null)
		},

		/**
		 * @param {string} field Name of the field to validate.
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

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - New contact person from a picker: the library form dialog over the
  - contact schema, asking the name, client, role, email address and phone
  - number with the checks the Contacts page declares (`fieldOverrides`), and
  - a New client button for a client that does not exist yet. The
  - save goes through the contacts-sync create, and the new contact goes back
  - to the form that asked.
  -->
<template>
	<CnFormDialog
		v-if="schema"
		ref="form"
		:schema="schema"
		:item="null"
		register="pipelinq"
		:initialData="seed"
		:dialogTitle="t('pipelinq', 'New contact')"
		:includeFields="includeFields"
		:fieldOverrides="fieldOverrides"
		data-testid="contact-create-dialog"
		@confirm="onConfirm"
		@close="$emit('close')">
		<template #before-fields>
			<NcButton
				variant="tertiary"
				data-testid="contact-create-new-client"
				@click="newClient = true">
				{{ t('pipelinq', 'New client') }}
			</NcButton>
			<ClientCreateDialog
				v-if="newClient"
				:stayOnPage="true"
				@created="onClientCreated"
				@close="newClient = false" />
		</template>
	</CnFormDialog>
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import ClientCreateDialog from './ClientCreateDialog.vue'
import manifest from '../manifest.json'
import { createWithContact } from '../services/contactSyncApi.js'
import { useObjectStore } from '../store/modules/object.js'

const CONTACTS_PAGE = manifest.pages.find((page) => page.id === 'Contacts')

export default {
	name: 'ContactCreateDialog',
	components: {
		ClientCreateDialog,
		CnFormDialog,
		NcButton,
	},

	props: {
		/** The client the contact belongs to, when the picker knows it. */
		client: {
			type: String,
			default: null,
		},

		/** A name to start from (typed in a picker). */
		name: {
			type: String,
			default: '',
		},

		/** The values a picker hands over (`{ name: term }`), as a form's createModal does. */
		initialData: {
			type: Object,
			default: null,
		},
	},

	emits: ['created', 'close'],

	data() {
		return {
			schema: null,
			includeFields: ['name', 'client', 'role', 'email', 'phone'],
			newClient: false,
		}
	},

	computed: {
		/**
		 * @return {object} The Contacts page's field checks.
		 * @spec openspec/changes/party-forms-as-configuration/specs/client-forms/spec.md#requirement-the-client-form-is-configuration-shared-by-every-create
		 */
		fieldOverrides() {
			return (CONTACTS_PAGE && CONTACTS_PAGE.config && CONTACTS_PAGE.config.fieldOverrides) || {}
		},

		/**
		 * @return {object} The values the form starts from.
		 * @spec openspec/changes/party-forms-as-configuration/specs/client-forms/spec.md#requirement-the-client-form-is-configuration-shared-by-every-create
		 */
		seed() {
			const seed = {}
			const name = this.name || this.initialData?.name
			if (name) {
				seed.name = name
			}
			if (this.client) {
				seed.client = this.client
			}
			return seed
		},
	},

	async mounted() {
		const store = useObjectStore()
		await store.fetchSchema('contact')
		this.schema = store.getSchema('contact')
	},

	methods: {
		/**
		 * Put a client created from this form in its client field.
		 *
		 * @param {string} id The new client's id.
		 * @spec openspec/changes/party-forms-as-configuration/specs/client-forms/spec.md#requirement-the-client-form-is-configuration-shared-by-every-create
		 */
		onClientCreated(id) {
			this.newClient = false
			this.$refs.form?.updateField('client', id)
		},

		/**
		 * Create the contact and hand it back to the form that asked.
		 *
		 * @param {object} formData The form's values.
		 * @spec openspec/changes/party-forms-as-configuration/specs/client-forms/spec.md#requirement-the-client-form-is-configuration-shared-by-every-create
		 */
		async onConfirm(formData) {
			let created
			try {
				created = await createWithContact('contact', formData)
			} catch (error) {
				this.$refs.form?.setResult({ error: error?.response?.data?.error || t('pipelinq', 'Failed to create contact.') })
				return
			}
			if (!created || !(created.id || created['@self']?.id)) {
				this.$refs.form?.setResult({ error: t('pipelinq', 'Failed to create contact.') })
				return
			}
			this.$refs.form?.setResult({ success: true })
			this.$emit('created', created)
		},
	},
}
</script>

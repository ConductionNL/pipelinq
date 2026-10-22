<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - New client: the library form dialog over the client schema, with the
  - fields and checks the Clients page declares (`includeFields`,
  - `fieldOverrides`), so the index's edit form, this dialog and any app that
  - reuses the config ask the same. The save goes through the contacts-sync
  - create, which provisions the Nextcloud contact a client requires. Opened
  - from the Clients page, the dashboards and the request form's client
  - picker; a picker gets the new client back and its form stays.
  -->
<template>
	<CnFormDialog
		v-if="schema"
		ref="form"
		:schema="schema"
		:item="null"
		register="pipelinq"
		:initialData="prefill"
		:dialogTitle="t('pipelinq', 'New Client')"
		:includeFields="formConfig.includeFields"
		:fieldOverrides="formConfig.fieldOverrides"
		data-testid="client-create-dialog"
		@confirm="onConfirm"
		@close="$emit('close')" />
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import manifest from '../manifest.json'
import { createClientWithContact } from '../services/contactSyncApi.js'
import { useObjectStore } from '../store/modules/object.js'

const CLIENTS_PAGE = manifest.pages.find((page) => page.id === 'Clients')

export default {
	name: 'ClientCreateDialog',
	components: {
		CnFormDialog,
	},

	props: {
		/** A name to start from (typed in a picker). */
		name: {
			type: String,
			default: '',
		},

		/** Values to start from; set when a form opened this dialog. */
		initialData: {
			type: Object,
			default: null,
		},

		/** Stay on the page after the create instead of opening the client. */
		stayOnPage: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['created', 'close'],

	data() {
		return {
			schema: null,
		}
	},

	computed: {
		/**
		 * @return {{includeFields: string[], fieldOverrides: object}} The Clients page's form config.
		 * @spec openspec/changes/party-forms-as-configuration/specs/client-forms/spec.md#requirement-the-client-form-is-configuration-shared-by-every-create
		 */
		formConfig() {
			const config = (CLIENTS_PAGE && CLIENTS_PAGE.config) || {}
			return { includeFields: config.includeFields || null, fieldOverrides: config.fieldOverrides || {} }
		},

		/**
		 * @return {object} The values the form starts from.
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
		 */
		prefill() {
			const name = this.name || this.initialData?.name || ''
			return name ? { name } : {}
		},

		/**
		 * @return {boolean} Whether a form behind this dialog gets the client back.
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
		 */
		returnsToForm() {
			return this.stayOnPage || this.initialData !== null
		},
	},

	async mounted() {
		const store = useObjectStore()
		await store.fetchSchema('client')
		this.schema = store.getSchema('client')
	},

	methods: {
		/**
		 * Create the client, then open it, or hand it back to the form that asked.
		 *
		 * @param {object} formData The form's values.
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
		 */
		async onConfirm(formData) {
			let created
			try {
				created = await createClientWithContact(formData)
			} catch (error) {
				this.$refs.form?.setResult({ error: error?.response?.data?.error || t('pipelinq', 'Failed to create client.') })
				return
			}
			const id = created?.id ?? created?.['@self']?.id
			if (!id) {
				this.$refs.form?.setResult({ error: t('pipelinq', 'Failed to create client.') })
				return
			}
			this.$refs.form?.setResult({ success: true })
			window.dispatchEvent(new CustomEvent('cn-walkthrough:object-created', {
				detail: { ...created, id, register: 'pipelinq', schema: 'client' },
			}))
			this.$emit('created', id, { ...created, id })
			this.$emit('close')
			if (!this.returnsToForm && this.$router) {
				this.$router.push({ name: 'ClientDetail', params: { id } }).catch(() => {})
			}
		},
	},
}
</script>

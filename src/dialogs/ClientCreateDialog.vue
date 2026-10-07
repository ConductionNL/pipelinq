<template>
	<NcDialog
		:name="t('pipelinq', 'New Client')"
		:open="true"
		size="normal"
		data-testid="client-create-dialog"
		@closing="$emit('close')">
		<ClientForm
			ref="form"
			:initialName="prefillName"
			:showActions="false"
			@save="onSave"
			@update:valid="(v) => (valid = v)" />
		<template #actions>
			<NcButton data-testid="client-create-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid || saving"
				data-testid="client-form-save"
				@click="submit">
				{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcDialog } from '@nextcloud/vue'
import ClientForm from '../views/clients/ClientForm.vue'
import { createWithContact } from '../services/contactSyncApi.js'

export default {
	name: 'ClientCreateDialog',
	components: {
		NcButton,
		NcDialog,
		ClientForm,
	},

	props: {
		/** The name typed into a client picker, prefilled in the form. */
		name: {
			type: String,
			default: '',
		},

		/**
		 * Initial values from nextcloud-vue's select-or-create picker
		 * (`{ name: term }`). Its presence means a form opened this dialog.
		 */
		initialData: {
			type: Object,
			default: null,
		},

		/**
		 * Stay on the page that opened the dialog and hand the new client
		 * back, instead of opening the client. Set by every picker.
		 */
		stayOnPage: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['created', 'close'],
	data() {
		return {
			valid: false,
			saving: false,
		}
	},

	computed: {
		/**
		 * The name to start the form with.
		 *
		 * @return {string} The typed name, or ''.
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
		 */
		prefillName() {
			return this.name || this.initialData?.name || ''
		},

		/**
		 * Whether a form opened this dialog, so the new client goes back to it.
		 *
		 * @return {boolean} True when opened from a picker.
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
		 */
		returnsToForm() {
			return this.stayOnPage || this.initialData !== null
		},
	},

	methods: {
		/**
		 * Navigate to the created record and close.
		 *
		 * A registry modal is mounted by CnAppRoot, which forwards only
		 * `close` — there is no parent to route on the dialog's behalf the way
		 * the old bespoke header-actions component did.
		 *
		 * @param {string} route The detail route name.
		 * @param {string} id The created object's id.
		 * @return {void}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		goToDetail(route, id) {
			this.$emit('close')
			if (id && this.$router) {
				this.$router.push({ name: route, params: { id } }).catch(() => {})
			}
		},

		/**
		 * Tell the getting-started tour a client was created, so its
		 * `create-client` step advances. This dialog saves through the
		 * contact-first endpoint, not through the index page, so the page's
		 * own `cn-walkthrough:object-created` dispatch never runs for it.
		 *
		 * @param {object} created The created client.
		 * @return {void}
		 * @spec openspec/changes/pipelinq-forms-review/specs/walkthrough/spec.md
		 */
		notifyWalkthrough(created) {
			window.dispatchEvent(
				new CustomEvent('cn-walkthrough:object-created', {
					detail: { ...created, register: 'pipelinq', schema: 'client' },
				}),
			)
		},

		/**
		 * Trigger the form's own validate-then-emit flow; `@save` fires onSave.
		 *
		 * @spec openspec/specs/unify-client-contact/spec.md
		 */
		submit() {
			this.$refs.form.onSave()
		},

		/**
		 * Contact-FIRST create: the `client` schema marks `contactsUid` REQUIRED
		 * (the authoritative identity is the Nextcloud addressbook contact, never
		 * minted locally), so a plain objectStore.saveObject('client', …) 400s
		 * with "The required property (contactsUid) is missing". We post the raw
		 * form fields to the backend, which provisions (resolves or creates) the
		 * NC contact via ContactVcardService and saves the client with the
		 * resolved contactsUid + the denormalised name/email/phone mirror.
		 *
		 * Opened from a picker, it hands the new client back and stays on the
		 * page; opened from the Clients index, it opens the new client.
		 *
		 * @param {object} formData The raw create-form fields.
		 * @spec openspec/specs/unify-client-contact/spec.md
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-2
		 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/client-forms/spec.md#requirement-one-client-dialog-that-returns-to-the-form-that-opened-it
		 */
		async onSave(formData) {
			this.saving = true
			try {
				const created = await createWithContact('client', formData)
				const id = created?.id ?? created?.['@self']?.id
				if (id) {
					this.notifyWalkthrough({ ...created, id })
					this.$emit('created', id, { ...created, id })
					if (this.returnsToForm) {
						// A picker opened this dialog: the form behind it stays.
						this.$emit('close')
						return
					}
					this.goToDetail('ClientDetail', id)
					return
				}
				showError(t('pipelinq', 'Failed to create client.'))
			} catch (error) {
				const message = error?.response?.data?.error
				showError(message || t('pipelinq', 'Failed to create client.'))
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

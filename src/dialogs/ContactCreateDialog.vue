<template>
	<NcDialog
		:name="t('pipelinq', 'New contact')"
		:open="true"
		size="normal"
		data-testid="contact-create-dialog"
		@closing="$emit('close')">
		<ContactForm
			:contact="seed"
			:preSelectedClient="client"
			@save="onSave"
			@cancel="$emit('close')" />
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcDialog } from '@nextcloud/vue'
import ContactForm from '../views/contacts/ContactForm.vue'
import { createWithContact } from '../services/contactSyncApi.js'

/**
 * ContactCreateDialog — create a contact person without leaving the form that
 * needs one.
 *
 * Mirrors ClientCreateDialog, including its persist path: the `contact` schema
 * marks `contactsUid` REQUIRED (the authoritative identity is the Nextcloud
 * addressbook entry, never minted locally), so a plain
 * objectStore.saveObject('contact', …) 400s with "The required property
 * (contactsUid) is missing". The raw form fields go to the backend, which
 * provisions the NC contact via ContactVcardService and saves the object with
 * the resolved contactsUid.
 *
 * This is also what finally mounts ContactForm. That component carried a
 * standing note that nothing imported it — no manifest page, no router entry,
 * no importer — leaving it unreachable on every instance and untestable by
 * Playwright. It is re-wired here rather than deleted.
 */
export default {
	name: 'ContactCreateDialog',

	components: {
		NcDialog,
		ContactForm,
	},

	props: {
		/** Client uuid the new contact belongs to; pre-selects the client field. */
		client: {
			type: String,
			default: null,
		},

		/** Name typed into the picker, carried into the form so it is not retyped. */
		name: {
			type: String,
			default: '',
		},
	},

	emits: ['created', 'close'],

	computed: {
		/**
		 * ContactForm reads its initial values from `contact`, so the typed
		 * term and the parent client are handed over that way.
		 *
		 * @return {object}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		seed() {
			const seed = {}
			if (this.name) seed.name = this.name
			if (this.client) seed.client = this.client
			return seed
		},
	},

	methods: {
		/**
		 * Persist through the contact-first endpoint and hand the created
		 * object back, so a picker can select it immediately.
		 *
		 * @param {object} formData The raw create-form fields.
		 * @return {Promise<void>}
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		async onSave(formData) {
			try {
				const created = await createWithContact('contact', formData)
				if (created && (created.id || created['@self']?.id)) {
					this.$emit('created', created)
					return
				}
				showError(t('pipelinq', 'Failed to create contact.'))
			} catch (error) {
				showError(
					error?.response?.data?.error
						|| t('pipelinq', 'Failed to create contact.'),
				)
			}
		},
	},
}
</script>

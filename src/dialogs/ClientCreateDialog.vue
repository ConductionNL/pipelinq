<template>
	<NcDialog
		:name="t('pipelinq', 'New Client')"
		:open="true"
		size="normal"
		data-testid="client-create-dialog"
		@closing="$emit('close')">
		<ClientForm
			ref="form"
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

	emits: ['created', 'close'],
	data() {
		return {
			valid: false,
			saving: false,
		}
	},

	methods: {
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
		 * @param formData
		 * @spec openspec/specs/unify-client-contact/spec.md
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-2
		 */
		async onSave(formData) {
			this.saving = true
			try {
				const created = await createWithContact('client', formData)
				const id = created?.id ?? created?.['@self']?.id
				if (id) {
					this.$emit('created', id)
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

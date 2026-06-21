<template>
	<div class="create-overlay" data-testid="client-create-dialog" @click.self="$emit('close')">
		<div class="create-dialog">
			<div class="create-dialog__header">
				<h3>{{ t('pipelinq', 'New Client') }}</h3>
				<NcButton type="tertiary" data-testid="client-create-close" @click="$emit('close')">
					✕
				</NcButton>
			</div>

			<div class="create-dialog__body">
				<ClientForm @save="onSave" @cancel="$emit('close')" />
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ClientForm from './ClientForm.vue'
import { createWithContact } from '../../services/contactSyncApi.js'

export default {
	name: 'ClientCreateDialog',
	components: {
		NcButton,
		ClientForm,
	},
	emits: ['created', 'close'],
	methods: {
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
		 * @spec openspec/changes/pipelinq-unify-client-contact/specs/unify-client-contact/spec.md#REQ-PUCC-003
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-2
		 */
		async onSave(formData) {
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
			}
		},
	},
}
</script>

<style scoped>
.create-overlay {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
	display: flex;
	align-items: center;
	justify-content: center;
	z-index: 10000;
}

.create-dialog {
	background: var(--color-main-background);
	border-radius: var(--border-radius-large);
	box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
	width: 640px;
	max-width: 90vw;
	max-height: 85vh;
	overflow-y: auto;
}

.create-dialog__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 16px 20px;
	border-bottom: 1px solid var(--color-border);
}

.create-dialog__header h3 {
	margin: 0;
}

.create-dialog__body {
	padding: 20px;
}
</style>

<template>
	<NcDialog
		:name="t('pipelinq', 'New Request')"
		:open="true"
		size="normal"
		data-testid="request-create-dialog"
		@closing="$emit('close')">
		<RequestForm
			ref="form"
			:show-actions="false"
			@save="onSave"
			@update:valid="v => (valid = v)" />
		<template #actions>
			<NcButton data-testid="request-create-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!valid || saving"
				data-testid="request-create-save"
				@click="submit">
				{{ saving ? t('pipelinq', 'Creating…') : t('pipelinq', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import RequestForm from './RequestForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'RequestCreateDialog',
	components: {
		NcButton,
		NcDialog,
		RequestForm,
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-1
		 */
		objectStore() {
			return useObjectStore()
		},
	},
	methods: {
		/**
		 * Trigger the form's own validate-then-emit flow; @save fires onSave.
		 */
		submit() {
			this.$refs.form.onSave()
		},
		/**
		 * @param formData
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-2
		 */
		async onSave(formData) {
			this.saving = true
			try {
				// A request is a `ticket` with ticketType 'request' (unify-ticket-supertype).
				const result = await this.objectStore.saveObject('ticket', {
					...formData,
					ticketType: 'request',
				})
				if (result) {
					this.$emit('created', result.id)
				} else {
					const error = this.objectStore.getError('ticket')
					showError(error?.message || t('pipelinq', 'Failed to create request.'))
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<template>
	<NcDialog
		:name="t('pipelinq', 'New Client')"
		:open="true"
		size="normal"
		data-testid="client-create-dialog"
		@closing="$emit('close')">
		<ClientForm
			ref="form"
			:show-actions="false"
			@save="onSave"
			@update:valid="v => (valid = v)" />
		<template #actions>
			<NcButton data-testid="client-create-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				type="primary"
				:disabled="!valid || saving"
				data-testid="client-form-save"
				@click="submit">
				{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { showError } from '@nextcloud/dialogs'
import ClientForm from './ClientForm.vue'
import { useObjectStore } from '../../store/modules/object.js'

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
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-1
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-clients-ui/tasks.md#task-2
		 */
		async onSave(formData) {
			this.saving = true
			try {
				const result = await this.objectStore.saveObject('client', formData)
				if (result) {
					this.$emit('created', result.id)
				} else {
					const error = this.objectStore.getError('client')
					showError(error?.message || t('pipelinq', 'Failed to create client.'))
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

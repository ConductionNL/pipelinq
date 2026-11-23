<template>
	<CnDialog
		width="form"
		:name="t('pipelinq', 'New Lead')"
		size="normal"
		data-testid="lead-create-dialog"
		@closing="$emit('close')">
		<LeadForm
			ref="form"
			:showActions="false"
			@save="onSave"
			@update:valid="(v) => (valid = v)" />
		<template #actions>
			<NcButton data-testid="lead-create-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid || saving"
				data-testid="lead-create-save"
				@click="submit">
				{{ saving ? t('pipelinq', 'Creating…') : t('pipelinq', 'Create') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import LeadForm from '../views/leads/LeadForm.vue'
import createDialogMixin from '../mixins/createDialogMixin.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'LeadCreateDialog',
	components: {
		NcButton,
		CnDialog,
		LeadForm,
	},

	mixins: [createDialogMixin],

	emits: ['created', 'close'],

	computed: {
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-leads-ui/tasks.md#task-23
		 */
		objectStore() {
			return useObjectStore()
		},
	},

	methods: {
		/**
		 * Trigger the form's own validate-then-emit flow; `@save` fires onSave.
		 */
		submit() {
			this.$refs.form.onSave()
		},

		/**
		 * @param {object} formData The raw create-form fields.
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-leads-ui/tasks.md#task-24
		 */
		async onSave(formData) {
			this.saving = true
			try {
				const result = await this.objectStore.saveObject('lead', formData)
				if (result) {
					this.$emit('created', result.id)
					this.goToDetail('LeadDetail', result.id)
				} else {
					const error = this.objectStore.getError('lead')
					showError(
						error?.message || t('pipelinq', 'Failed to create lead.'),
					)
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

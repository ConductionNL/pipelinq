<template>
	<NcDialog
		:name="t('pipelinq', 'New Lead')"
		:open="true"
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
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcDialog } from '@nextcloud/vue'
import LeadForm from '../views/leads/LeadForm.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'LeadCreateDialog',
	components: {
		NcButton,
		NcDialog,
		LeadForm,
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-23
		 */
		objectStore() {
			return useObjectStore()
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
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form
		 */
		goToDetail(route, id) {
			this.$emit('close')
			if (id && this.$router) {
				this.$router.push({ name: route, params: { id } }).catch(() => {})
			}
		},

		/**
		 * Trigger the form's own validate-then-emit flow; @save fires onSave.
		 */
		submit() {
			this.$refs.form.onSave()
		},

		/**
		 * @param formData
		 * @spec openspec/changes/reverse-2026-05-26-fe-leads-ui/tasks.md#task-24
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

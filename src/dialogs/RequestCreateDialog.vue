<template>
	<NcDialog
		:name="t('pipelinq', 'New Request')"
		:open="true"
		size="normal"
		data-testid="request-create-dialog"
		@closing="$emit('close')">
		<RequestForm
			ref="form"
			:showActions="false"
			@save="onSave"
			@update:valid="(v) => (valid = v)" />
		<template #actions>
			<NcButton data-testid="request-create-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid || saving"
				data-testid="request-create-save"
				@click="submit">
				{{ saving ? t('pipelinq', 'Creating…') : t('pipelinq', 'Create') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { showError } from '@nextcloud/dialogs'
import { NcButton, NcDialog } from '@nextcloud/vue'
import RequestForm from '../views/requests/RequestForm.vue'
import { useObjectStore } from '../store/modules/object.js'

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
		 * Navigate to the created record and close.
		 *
		 * A registry modal is mounted by CnAppRoot, which forwards only
		 * `close` — there is no parent to route on the dialog's behalf the way
		 * the old bespoke header-actions component did.
		 *
		 * @param {string} route The detail route name.
		 * @param {string} id The created object's id.
		 * @return {void}
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
					this.goToDetail('TicketDetail', result.id)
				} else {
					const error = this.objectStore.getError('ticket')
					showError(
						error?.message || t('pipelinq', 'Failed to create request.'),
					)
				}
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

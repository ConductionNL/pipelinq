<!--
SPDX-License-Identifier: EUPL-1.2
Copyright (C) 2026 Conduction B.V.

New request, laid out as board PqNieuwVerzoek: the kind of ticket, the client,
the subject and the question, channel and category, priority and handler, then
"Open after creating" and Create in the footer. Status, requested at, contact,
pipeline and stage stay one click away under More details.
-->
<template>
	<CnDialog
		:name="t('pipelinq', 'New request')"
		:eyebrow="t('pipelinq', 'Questions and reports')"
		:subtitle="
			t(
				'pipelinq',
				'A question, report or complaint from a resident or business',
			)
		"
		:open="true"
		size="normal"
		data-testid="request-create-dialog"
		@closing="$emit('close')">
		<RequestForm
			ref="form"
			:showActions="false"
			:preLinkedClient="clientId || null"
			boardLayout
			@save="onSave"
			@update:valid="(v) => (valid = v)" />
		<template #actions>
			<NcCheckboxRadioSwitch
				v-model="openAfter"
				class="request-create__open-after"
				data-testid="request-create-open-after">
				{{ t('pipelinq', 'Open after creating') }}
			</NcCheckboxRadioSwitch>
			<NcButton data-testid="request-create-cancel" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid || saving"
				data-testid="request-create-save"
				@click="submit">
				<template #icon>
					<Plus :size="20" />
				</template>
				{{
					saving
						? t('pipelinq', 'Creating…')
						: t('pipelinq', 'Create request')
				}}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcCheckboxRadioSwitch } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import RequestForm from '../views/requests/RequestForm.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'RequestCreateDialog',
	components: {
		CnDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		Plus,
		RequestForm,
	},

	props: {
		/**
		 * The client the request is for, picked already (the client page's
		 * New request header action); none leaves the client to choose.
		 */
		clientId: {
			type: String,
			default: '',
		},
	},

	emits: ['created', 'close'],

	data() {
		return {
			valid: false,
			saving: false,
			openAfter: true,
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
		 * @spec openspec/specs/lead-management/spec.md#requirement-linked-party-selection-on-the-create-form-mvp
		 */
		goToDetail(route, id) {
			this.$emit('close')
			if (id && this.$router) {
				this.$router.push({ name: route, params: { id } }).catch(() => {})
			}
		},

		/**
		 * Trigger the form's own validate-then-emit flow; `@save` fires onSave.
		 */
		submit() {
			this.$refs.form.onSave()
		},

		/**
		 * @param {object} formData The raw create-form fields.
		 * @spec openspec/changes/reverse-2026-05-26-fe-requests-ui/tasks.md#task-2
		 */
		async onSave(formData) {
			this.saving = true
			try {
				// A request, complaint or contact moment is a `ticket` with that
				// ticketType (unify-ticket-supertype); a request when none is picked.
				const result = await this.objectStore.saveObject('ticket', {
					...formData,
					ticketType: formData.ticketType || 'request',
				})
				if (result) {
					this.$emit('created', result.id)
					if (this.openAfter) {
						this.goToDetail('TicketDetail', result.id)
					} else {
						showSuccess(t('pipelinq', 'The ticket is created.'))
						this.$emit('close')
					}
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

<style scoped>
.request-create__open-after {
	margin-inline-end: auto;
}
</style>

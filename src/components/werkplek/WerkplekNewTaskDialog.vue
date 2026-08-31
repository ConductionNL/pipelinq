<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!-- @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3 -->
<template>
	<CnFormDialog
		:dialogTitle="t('pipelinq', 'New task')"
		:fields="fields"
		:confirmLabel="t('pipelinq', 'Create')"
		:cancelLabel="t('pipelinq', 'Cancel')"
		:successText="t('pipelinq', 'Task created.')"
		nameField="subject"
		:initialValues="initialValues"
		@confirm="onConfirm"
		@close="$emit('close')" />
</template>

<script>
import { CnFormDialog } from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'

/**
 * Inline "New task" dialog opened from WerkplekContactmomentPanel.
 *
 * Pre-fills `clientId` and `contactMomentSummary` from the parent's form
 * state so the agent can spin off a follow-up task without re-typing.
 * Lives in its own .vue file per hydra-gate-modal-isolation.
 *
 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
 */
export default {
	name: 'WerkplekNewTaskDialog',

	components: { CnFormDialog },

	props: {
		/**
		 * Client UUID to attach the task to. Empty when the agent has
		 * not picked a client yet (the parent disables this dialog then).
		 */
		clientId: {
			type: String,
			default: '',
		},

		/**
		 * Summary text carried over from the in-progress contactmoment;
		 * shows up as a non-required free-text field on the task.
		 */
		contactMomentSummary: {
			type: String,
			default: '',
		},
	},

	emits: ['close', 'saved'],

	computed: {
		/**
		 * CnFormDialog field definitions for the task schema.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
		 */
		fields() {
			return [
				{
					key: 'subject',
					label: this.t('pipelinq', 'Subject'),
					widget: 'text',
					required: true,
				},
				{
					key: 'type',
					label: this.t('pipelinq', 'Type'),
					widget: 'select',
					required: true,
					enum: [
						{
							value: 'callbackRequest',
							label: this.t('pipelinq', 'Callback'),
						},
						{
							value: 'followUpTask',
							label: this.t('pipelinq', 'Follow-up'),
						},
						{
							value: 'informationRequest',
							label: this.t('pipelinq', 'Information'),
						},
					],
				},
				{
					key: 'priority',
					label: this.t('pipelinq', 'Priority'),
					widget: 'select',
					required: false,
					enum: [
						{ value: 'low', label: this.t('pipelinq', 'Low') },
						{ value: 'normal', label: this.t('pipelinq', 'Normal') },
						{ value: 'high', label: this.t('pipelinq', 'High') },
					],
				},
				{
					key: 'deadline',
					label: this.t('pipelinq', 'Deadline'),
					widget: 'datetime',
					required: false,
				},
				{
					key: 'description',
					label: this.t('pipelinq', 'Description'),
					widget: 'textarea',
					required: false,
				},
			]
		},

		/**
		 * Initial values for the dialog (pre-filled context).
		 *
		 * @return {object}
		 * @spec openspec/changes/kcc-werkplek/tasks.md#task-3.3
		 */
		initialValues() {
			return {
				type: 'followUpTask',
				priority: 'normal',
				clientId: this.clientId,
				contactMomentSummary: this.contactMomentSummary || '',
			}
		},

		/**
		 * Pinia object store handle.
		 *
		 * @return {object}
		 */
		objectStore() {
			return useObjectStore()
		},
	},

	methods: {
		/**
		 * Persist the new task via the object store. On failure surface
		 * the error to the agent and keep the dialog open.
		 *
		 * @param {object} values Values from CnFormDialog.
		 *
		 * @return {Promise<void>}
		 */
		async onConfirm(values) {
			const payload = {
				...values,
				clientId: this.clientId,
				contactMomentSummary:
					this.contactMomentSummary || values.description || '',

				status: 'open',
			}
			try {
				const result = await this.objectStore.saveObject('task', payload)
				if (!result) {
					try {
						showError(
							this.t(
								'pipelinq',
								'Could not create task. Please try again.',
							),
						)
					} catch {
						/* no-op */
					}
					return
				}
				this.$emit('saved', result)
			} catch (e) {
				try {
					showError(
						this.t(
							'pipelinq',
							'Could not create task. Please try again.',
						),
					)
				} catch {
					/* no-op */
				}
				// eslint-disable-next-line no-console
				console.warn('[WerkplekNewTaskDialog] save failed', e)
			}
		},
	},
}
</script>

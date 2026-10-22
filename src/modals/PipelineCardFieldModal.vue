<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Set one field of a pipeline board card's record: its assignee or its
  - priority. Target of the Assign and Priority card actions on the Pipeline
  - board; the board hands over the card as `object`. The record is read
  - again before the write, so a field a colleague changed keeps its value,
  - and a ticket keeps its ticketType.
  -->
<template>
	<AssigneePickerDialog
		v-if="field === 'assignee'"
		:options="users"
		:assignee="object.assignee || null"
		@close="$emit('close')"
		@select="save" />
	<PriorityPickerDialog
		v-else
		:options="priorityOptions"
		:current="object.priority || null"
		@close="$emit('close')"
		@select="save" />
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import AssigneePickerDialog from '../dialogs/AssigneePickerDialog.vue'
import PriorityPickerDialog from '../dialogs/PriorityPickerDialog.vue'
import { listAssignableUserIds } from '../services/userDisplayName.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'PipelineCardFieldModal',
	components: { AssigneePickerDialog, PriorityPickerDialog },

	props: {
		/** The board card: the record plus `_objectType`. */
		object: {
			type: Object,
			required: true,
		},

		/** The field this modal sets: `assignee` or `priority`. */
		field: {
			type: String,
			default: 'assignee',
			validator: (value) => ['assignee', 'priority'].includes(value),
		},
	},

	emits: ['close'],

	data() {
		return {
			users: [],
		}
	},

	computed: {
		/**
		 * @return {Array<{value: string, label: string}>} The priorities.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		priorityOptions() {
			return [
				{ value: 'low', label: t('pipelinq', 'Low') },
				{ value: 'normal', label: t('pipelinq', 'Normal') },
				{ value: 'high', label: t('pipelinq', 'High') },
				{ value: 'urgent', label: t('pipelinq', 'Urgent') },
			]
		},
	},

	/**
	 * @spec openspec/specs/lead-management/spec.md
	 */
	async mounted() {
		if (this.field === 'assignee') {
			this.users = await listAssignableUserIds()
		}
	},

	methods: {
		/**
		 * Write the picked value to the card's record and ask the board to read again.
		 *
		 * @param {string|null} value The picked value.
		 * @return {Promise<void>}
		 * @spec openspec/specs/lead-management/spec.md
		 */
		async save(value) {
			const store = useObjectStore()
			const type = this.object._objectType || 'lead'
			const current = await store.fetchObject(type, this.object.id)
			const payload = current ? { ...current, id: this.object.id, [this.field]: value ?? null } : null
			if (payload && type === 'ticket' && !payload.ticketType && this.object.ticketType) {
				payload.ticketType = this.object.ticketType
			}
			const saved = payload ? await store.saveObject(type, payload) : null
			if (!saved) {
				showError(t('pipelinq', 'Could not save the change.'))
				return
			}
			showSuccess(this.field === 'assignee' ? t('pipelinq', 'Assignee updated') : t('pipelinq', 'Priority updated'))
			emit('cn:page:refresh', {})
			this.$emit('close')
		},
	},
}
</script>

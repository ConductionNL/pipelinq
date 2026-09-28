<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Ticket-detail in-body section (kind:'section') that makes the routing
  - suggestions reachable (pipelinq#2039). RoutingSuggestionPanel ranks
  - colleagues for a record by skill match, availability and workload, but it
  - only emits `assigned` with a user id and was mounted by nothing. This
  - section mounts it for the record the page shows and writes the chosen
  - colleague into the record's `assignee` through the object store.
  -
  - The store's saveObject() is a PUT, which replaces the whole object, so the
  - section re-reads the record first and sends it back with only `assignee`
  - changed. Sending `{ id, assignee }` alone would wipe every other field.
  -->
<template>
	<div class="routing-suggestion-section">
		<RoutingSuggestionPanel
			:requestId="objectId"
			:category="category"
			:entityType="entityType"
			@assigned="assign" />
		<NcNoteCard
			v-if="message"
			:type="messageType"
			class="routing-suggestion-section__notice">
			{{ message }}
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import RoutingSuggestionPanel from './RoutingSuggestionPanel.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'RoutingSuggestionSection',
	components: {
		NcNoteCard,
		RoutingSuggestionPanel,
	},

	props: {
		/** The record id, token-resolved from `@objectId`. */
		objectId: {
			type: String,
			required: true,
		},

		/** The record's category; the panel refreshes when it changes. */
		category: {
			type: String,
			default: '',
		},

		/** The routing entity type the suggestions API expects (`request` or `lead`). */
		entityType: {
			type: String,
			default: 'request',
		},

		/** The object-store type the record is saved under. */
		objectType: {
			type: String,
			default: 'ticket',
		},
	},

	data() {
		return {
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},
	},

	methods: {
		t,

		/**
		 * Write the chosen colleague into the record's assignee.
		 *
		 * @param {string} userId The Nextcloud user id the panel emitted.
		 * @spec openspec/changes/reverse-2026-05-26-fe-routing-ui/tasks.md#task-1
		 */
		async assign(userId) {
			this.message = ''
			const current = await this.objectStore.fetchObject(
				this.objectType,
				this.objectId,
			)
			if (!current) {
				this.showFailure()
				return
			}

			const saved = await this.objectStore.saveObject(this.objectType, {
				...current,
				id: this.objectId,
				assignee: userId,
			})
			if (!saved) {
				this.showFailure()
				return
			}

			this.message = t('pipelinq', 'Assigned to {user}.', { user: userId })
			this.messageType = 'success'
		},

		/**
		 * Tell the handler the assignment did not save.
		 */
		showFailure() {
			this.message = t('pipelinq', 'Could not save the assignee.')
			this.messageType = 'error'
		},
	},
}
</script>

<style scoped>
.routing-suggestion-section__notice {
	margin-top: 8px;
}
</style>

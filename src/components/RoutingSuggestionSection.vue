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
  - After the save the section marks the colleague as assigned and hands the
  - saved record to the detail page through `cnSectionContext.setObject`, so
  - the page's own widgets show the new assignee without waiting for a reload.
  - A library without `setObject` gets an "Assigned to" note instead.
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
			:assignee="currentAssignee"
			:assigning="assigning"
			@assigned="assign" />
		<NcNoteCard
			v-if="failed"
			type="error"
			class="routing-suggestion-section__notice">
			{{ t('pipelinq', 'Could not save the assignee.') }}
		</NcNoteCard>
		<NcNoteCard
			v-else-if="assignedTo"
			type="success"
			class="routing-suggestion-section__notice">
			{{ t('pipelinq', 'Assigned to {user}.', { user: assignedTo }) }}
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { NcNoteCard } from '@nextcloud/vue'
import { unref } from 'vue'
import RoutingSuggestionPanel from './RoutingSuggestionPanel.vue'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'RoutingSuggestionSection',
	components: {
		NcNoteCard,
		RoutingSuggestionPanel,
	},

	inject: {
		sectionContext: { from: 'cnSectionContext', default: null },
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

		/** The record's current assignee, token-resolved from `@object.assignee`. */
		assignee: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			currentAssignee: this.assignee,
			assigning: false,
			failed: false,
			// Set when the page cannot take the saved record, so its own
			// widgets still show the old assignee until a reload.
			assignedTo: '',
		}
	},

	computed: {
		/**
		 * The store the record is read from and saved through.
		 *
		 * @return {object}
		 * @spec openspec/changes/reverse-2026-05-26-fe-routing-ui/tasks.md#task-1
		 */
		objectStore() {
			return useObjectStore()
		},
	},

	watch: {
		/**
		 * Follow the record's assignee when the page re-reads it.
		 *
		 * @param {string} next The new assignee.
		 * @spec openspec/changes/reverse-2026-05-26-fe-routing-ui/tasks.md#task-1
		 */
		assignee(next) {
			this.currentAssignee = next
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
			this.failed = false
			this.assignedTo = ''
			this.assigning = true
			try {
				const current = await this.objectStore.fetchObject(
					this.objectType,
					this.objectId,
				)
				const saved = current
					? await this.objectStore.saveObject(this.objectType, {
							...current,
							id: this.objectId,
							assignee: userId,
						})
					: null
				if (!saved) {
					this.failed = true
					return
				}

				this.currentAssignee = userId
				// Vue unwraps an injected ref on `this`; unref covers both shapes.
				const setObject = unref(this.sectionContext)?.setObject
				if (typeof setObject === 'function') {
					setObject(saved)
				} else {
					this.assignedTo = userId
				}
			} finally {
				this.assigning = false
			}
		},
	},
}
</script>

<style scoped>
.routing-suggestion-section__notice {
	margin-top: 8px;
}
</style>

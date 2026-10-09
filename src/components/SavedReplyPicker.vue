<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - One saved reply picker for every place an agent answers a client
  - (messaging-saved-replies-and-resend, D3 and D4): the Send message dialog
  - (sms, whatsapp), the answer to the customer on a ticket (portal) and the
  - Write email dialog (email). It lists the team's active `savedReply`
  - records for the host's channel, the party's language first. Picking one
  - emits the text with its placeholders filled; the host puts it in its
  - composer. Picking never sends anything.
  -->
<template>
	<NcSelect
		v-model="selected"
		class="saved-reply-picker"
		:options="options"
		:inputLabel="t('pipelinq', 'Saved reply')"
		:placeholder="
			options.length
				? t('pipelinq', 'Pick a saved reply')
				: t('pipelinq', 'No saved replies for this channel yet')
		"
		:loading="loading"
		label="title"
		:filterBy="matches"
		data-testid="saved-reply-picker"
		@update:modelValue="pick" />
</template>

<script>
import { getCurrentUser } from '@nextcloud/auth'
import { translate as t } from '@nextcloud/l10n'
import { NcSelect } from '@nextcloud/vue'
import { fillPlaceholders, repliesForChannel } from '../services/savedReplies.js'
import { useObjectStore } from '../store/modules/object.js'

export default {
	name: 'SavedReplyPicker',
	components: { NcSelect },

	props: {
		/** The host's channel: `sms`, `whatsapp`, `email` or `portal`. */
		channel: {
			type: String,
			required: true,
		},

		/** The party's correspondence language; its replies sort first. */
		language: {
			type: String,
			default: '',
		},

		/** Placeholder values the host holds, e.g. `{'contact.name': 'Jan'}`. */
		values: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['pick'],

	data() {
		return {
			replies: [],
			selected: null,
			loading: false,
		}
	},

	computed: {
		/**
		 * The replies that fit this channel.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		options() {
			return repliesForChannel(this.replies, this.channel, this.language)
		},
	},

	/**
	 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
	 */
	async mounted() {
		this.loading = true
		try {
			this.replies =
				(await useObjectStore().fetchCollection('savedReply', {
					_limit: 200,
				})) || []
		} catch {
			this.replies = []
		} finally {
			this.loading = false
		}
	},

	methods: {
		t,

		/**
		 * Search on title and text.
		 *
		 * @param {object} option A reply.
		 * @param {string} label Its label.
		 * @param {string} search The typed text.
		 * @return {boolean}
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		matches(option, label, search) {
			const needle = String(search || '').toLowerCase()
			return (
				String(option.title || '')
					.toLowerCase()
					.includes(needle)
				|| String(option.body || '')
					.toLowerCase()
					.includes(needle)
			)
		},

		/**
		 * Hand the filled text to the host.
		 *
		 * @param {object|null} reply The picked reply.
		 * @spec openspec/changes/messaging-saved-replies-and-resend/specs/messaging-saved-replies/spec.md#requirement-an-agent-inserts-a-saved-reply-where-they-answer-req-msr-003
		 */
		pick(reply) {
			if (!reply) {
				return
			}
			const user = getCurrentUser()
			const values = {
				'agent.name': (user && (user.displayName || user.uid)) || '',
				...this.values,
			}
			this.$emit('pick', fillPlaceholders(reply.body, values))
		},
	},
}
</script>

<style scoped>
.saved-reply-picker {
	width: 100%;
}
</style>

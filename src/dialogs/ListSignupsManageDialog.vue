<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Manage a mailing list's sign-ups from the list (board PqMailinglijst,
  - "Aanmeldingen beheren"): take a member off the list, or send someone the
  - list's own confirmation link. Adding never skips the confirmation: the
  - person is pending until they open the link, as with the public form.
  -
  - @spec openspec/changes/mailing-list-page-as-board/specs/marketing-lists/spec.md#requirement-a-list-s-sign-ups-are-managed-from-the-list
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Manage sign-ups')"
		:subtitle="t('pipelinq', 'Send the list\'s confirmation link, or take a member off the list.')"
		width="form"
		data-testid="list-signups-dialog"
		@closing="$emit('close')">
		<div class="list-signups">
			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>

			<form class="list-signups__add" data-testid="list-signups-add" @submit.prevent="invite">
				<NcTextField
					v-model="email"
					type="email"
					:label="t('pipelinq', 'Email address')"
					:disabled="busy" />
				<NcButton type="submit" variant="primary" :disabled="busy || email.trim() === ''">
					{{ t('pipelinq', 'Send confirmation link') }}
				</NcButton>
			</form>
			<p class="list-signups__hint">
				{{ t('pipelinq', 'The person is added once they open the link.') }}
			</p>

			<ul class="list-signups__list">
				<li
					v-for="row in subscriptions"
					:key="row.id || row.email"
					class="list-signups__row">
					<span class="list-signups__who">{{ row.name || row.email }}</span>
					<span class="list-signups__state">{{ stateLabel(row.state) }}</span>
					<NcButton
						variant="tertiary"
						:disabled="busy || !canRemove(row)"
						:aria-label="t('pipelinq', 'Take {who} off this list', { who: row.name || row.email })"
						data-testid="list-signups-remove"
						@click="remove(row)">
						{{ t('pipelinq', 'Remove') }}
					</NcButton>
				</li>
			</ul>
		</div>
		<template #actions>
			<NcButton data-testid="list-signups-close" @click="$emit('close')">
				{{ t('pipelinq', 'Close') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { inviteToList, unsubscribeContact } from '../services/mailingListApi.js'

export default {
	name: 'ListSignupsManageDialog',

	components: { CnDialog, NcButton, NcNoteCard, NcTextField },

	props: {
		/** The mailing list. */
		listId: { type: String, required: true },
		/** The list's memberships, as the Subscribers card shows them. */
		subscriptions: { type: Array, default: () => [] },
	},

	emits: ['close', 'changed'],

	data() {
		return { email: '', busy: false, message: '', messageType: 'success' }
	},

	methods: {
		/**
		 * Whether a membership can be taken off the list here: it belongs to a
		 * known contact and still receives or waits for mail.
		 *
		 * @param {object} row The membership.
		 * @return {boolean}
		 */
		canRemove(row) {
			return Boolean(row && row.contactId) && ['confirmed', 'pending'].includes(row.state)
		},

		/**
		 * A membership state in words.
		 *
		 * @param {string} state The stored state.
		 * @return {string}
		 */
		stateLabel(state) {
			return {
				pending: this.t('pipelinq', 'Waiting for confirmation'),
				confirmed: this.t('pipelinq', 'Confirmed'),
				unsubscribed: this.t('pipelinq', 'Unsubscribed'),
				bounced: this.t('pipelinq', 'Undeliverable'),
			}[state] || state || '—'
		},

		/**
		 * Send the list's confirmation link to the address typed.
		 *
		 * @return {Promise<void>}
		 */
		async invite() {
			const email = this.email.trim()
			this.busy = true
			try {
				await inviteToList(this.listId, email)
				this.message = this.t('pipelinq', 'A confirmation link went to {email}.', { email })
				this.messageType = 'success'
				this.email = ''
				this.$emit('changed')
			} catch {
				this.message = this.t('pipelinq', 'No link was sent. Check the address, and that the list takes sign-ups.')
				this.messageType = 'error'
			} finally {
				this.busy = false
			}
		},

		/**
		 * Take one member off this list.
		 *
		 * @param {object} row The membership.
		 * @return {Promise<void>}
		 */
		async remove(row) {
			this.busy = true
			try {
				await unsubscribeContact(row.contactId, this.listId, 'Removed from the list by a colleague')
				this.message = this.t('pipelinq', '{who} is off this list.', { who: row.name || row.email })
				this.messageType = 'success'
				this.$emit('changed')
			} catch {
				this.message = this.t('pipelinq', 'The member could not be taken off the list.')
				this.messageType = 'error'
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.list-signups {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.list-signups__add {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.list-signups__hint {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.list-signups__list {
	display: flex;
	flex-direction: column;
	margin: 0;
	padding: 0;
	list-style: none;
}

.list-signups__row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) auto auto;
	align-items: center;
	gap: 12px;
	padding: 6px 0;
	border-block-end: 1px solid var(--color-border);
}

.list-signups__state {
	color: var(--color-text-maxcontrast);
}
</style>

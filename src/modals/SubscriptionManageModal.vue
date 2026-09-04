<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Manage one contact's mailing-list memberships from the CRM side: take them
  - off one list, take them off everything, or copy a preference link to send
  - them so they can choose for themselves.
  -
  - It lives in its own file because every modal does (ADR-004), and it is
  - deliberately thin: the only thing a marketer may do here is REMOVE. Adding
  - somebody to a double opt-in list from the CRM is exactly what double opt-in
  - exists to prevent, so the soft opt-in import is a separate, explicit action
  - that has to record its ground.
  -
  - @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
  -->
<template>
	<NcModal
		:name="t('pipelinq', 'Manage subscriptions')"
		size="normal"
		@close="$emit('close')">
		<div class="subscription-manage">
			<h2>{{ t('pipelinq', 'Manage subscriptions') }}</h2>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>

			<p v-if="subscriptions.length === 0">
				{{ t('pipelinq', 'This person is on no mailing list.') }}
			</p>

			<ul v-else class="subscription-manage__list">
				<li
					v-for="row in subscriptions"
					:key="row.listId"
					class="subscription-manage__row">
					<span>{{ row.listId }}</span>
					<span class="subscription-manage__state">{{
						stateLabel(row)
					}}</span>
					<NcButton
						variant="tertiary"
						:disabled="busy || !canRemove(row)"
						:aria-label="removeLabel(row)"
						@click="removeOne(row)">
						{{ t('pipelinq', 'Remove') }}
					</NcButton>
				</li>
			</ul>

			<div class="subscription-manage__actions">
				<NcButton
					variant="error"
					:disabled="busy || subscriptions.length === 0"
					data-testid="subscription-remove-all"
					@click="removeAll">
					<template #icon>
						<NcLoadingIcon v-if="busy" :size="16" />
					</template>
					{{ t('pipelinq', 'Remove from every list') }}
				</NcButton>
				<NcButton
					variant="secondary"
					:disabled="busy"
					data-testid="subscription-preference-link"
					@click="copyPreferenceLink">
					{{ t('pipelinq', 'Copy preference link') }}
				</NcButton>
			</div>

			<p v-if="preferenceUrl" class="subscription-manage__link">
				{{ preferenceUrl }}
			</p>
		</div>
	</NcModal>
</template>

<script>
import { NcButton, NcLoadingIcon, NcModal, NcNoteCard } from '@nextcloud/vue'
import {
	fetchPreferenceLink,
	unsubscribeContact,
} from '../services/mailingListApi.js'
import { chipForState } from '../services/subscriptionState.js'

export default {
	name: 'SubscriptionManageModal',

	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcNoteCard,
	},

	props: {
		/** The contact whose memberships are being managed. */
		contactId: {
			type: String,
			required: true,
		},

		/** The memberships, as the section already loaded them. */
		subscriptions: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['close', 'changed'],

	data() {
		return {
			busy: false,
			message: '',
			messageType: 'success',
			preferenceUrl: '',
		}
	},

	methods: {
		/**
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {string} The chip label for the row's state.
		 */
		stateLabel(row) {
			return chipForState(row && row.state).label
		},

		/**
		 * A membership already closed has nothing to remove.
		 *
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {boolean} Whether the remove button does anything.
		 */
		canRemove(row) {
			return Boolean(row) && row.state !== 'unsubscribed'
		},

		/**
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {string} An accessible name naming which list is removed.
		 */
		removeLabel(row) {
			return this.t('pipelinq', 'Remove from {list}', {
				list: (row && row.listId) || '',
			})
		},

		/**
		 * Close one membership.
		 *
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {Promise<void>} Resolves when the caller has been told.
		 */
		async removeOne(row) {
			await this.run(row.listId)
		},

		/**
		 * Close every membership this contact holds.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {Promise<void>} Resolves when the caller has been told.
		 */
		async removeAll() {
			await this.run('')
		},

		/**
		 * Post one unsubscribe and report what happened.
		 *
		 * @param {string} listId The list to leave, or an empty string for all.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {Promise<void>} Resolves when the caller has been told.
		 */
		async run(listId) {
			this.busy = true
			this.message = ''
			try {
				const result = await unsubscribeContact(
					this.contactId,
					listId,
					'crm-request',
				)
				this.messageType = 'success'
				this.message = this.t(
					'pipelinq',
					'{count} subscription(s) closed. The withdrawal is recorded.',
					{ count: result.count || 0 },
				)
				this.$emit('changed')
			} catch {
				this.messageType = 'error'
				this.message = this.t(
					'pipelinq',
					'That did not work. Nothing was changed.',
				)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Fetch a preference link to send the person.
		 *
		 * Shown rather than written to the clipboard, because a clipboard
		 * write needs a permission the browser may refuse and the marketer
		 * would then be looking at a success message with nothing copied.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-preference-centre-shows-and-saves-a-subscribers-lists
		 * @return {Promise<void>} Resolves when the link is on screen.
		 */
		async copyPreferenceLink() {
			this.busy = true
			this.message = ''
			try {
				this.preferenceUrl = await fetchPreferenceLink(this.contactId)
			} catch {
				this.messageType = 'error'
				this.message = this.t('pipelinq', 'The link could not be made.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.subscription-manage {
	padding: 1.5rem;
}

.subscription-manage__list {
	list-style: none;
	margin: 1rem 0;
	padding: 0;
}

.subscription-manage__row {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	padding-block: 0.35rem;
	border-block-end: 1px solid var(--color-border);
}

.subscription-manage__state {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
}

.subscription-manage__actions {
	display: flex;
	gap: 0.5rem;
	margin-block-start: 1rem;
}

.subscription-manage__link {
	margin-block-start: 1rem;
	word-break: break-all;
	color: var(--color-text-maxcontrast);
}
</style>

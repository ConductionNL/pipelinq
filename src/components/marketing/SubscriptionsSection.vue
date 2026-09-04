<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Mailing-list memberships as an in-body section (kind:'section'), bound
  - either to a list (`listId`, on the mailing list detail page) or to a
  - contact (`contactId`, on the contact detail page). One component for both
  - because the row is the same row read from two directions, and a state that
  - means "not reachable" must not look reachable in one of them.
  -
  - Why this is not a declarative object-list widget. A membership is read
  - through its STATE, so each row needs the chip vocabulary from
  - services/subscriptionState.js mapping pending / confirmed / unsubscribed /
  - bounced to a colour and a label; the declarative widget renders a value,
  - not a chip. And the list view leads with per-state counts, which
  - summaryAggregates cannot express: its filters are equality-only over one
  - schema and cannot break one field out into four counts on one strip.
  -
  - @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
  -->
<template>
	<div class="subscriptions-section">
		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<ul v-if="showCounts" class="subscriptions-section__counts">
				<li
					v-for="entry in countEntries"
					:key="entry.state"
					class="subscriptions-section__count">
					<span
						class="subscriptions-section__swatch"
						:style="{ backgroundColor: entry.color }"
						aria-hidden="true" />
					<strong>{{ entry.value }}</strong>
					<span>{{ entry.label }}</span>
				</li>
			</ul>

			<p v-if="rows.length === 0" class="subscriptions-section__empty">
				{{ emptyText }}
			</p>

			<table v-else class="subscriptions-section__table">
				<caption class="subscriptions-section__caption">
					{{
						tableCaption
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Subscriber') }}</th>
						<th scope="col">{{ t('pipelinq', 'State') }}</th>
						<th scope="col">{{ t('pipelinq', 'Source') }}</th>
						<th scope="col">{{ t('pipelinq', 'Ground') }}</th>
						<th scope="col">{{ t('pipelinq', 'Confirmed') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in rows" :key="rowKey(row)">
						<td>{{ subscriberLabel(row) }}</td>
						<td>
							<span
								class="subscriptions-section__chip"
								:style="{ borderColor: chip(row).color }">
								<span
									class="subscriptions-section__swatch"
									:style="{ backgroundColor: chip(row).color }"
									aria-hidden="true" />
								{{ chip(row).label }}
							</span>
						</td>
						<td>{{ row.source || '—' }}</td>
						<td>{{ row.lawfulBasis || '—' }}</td>
						<td>{{ formatDate(row.confirmedAt) }}</td>
					</tr>
				</tbody>
			</table>

			<div class="subscriptions-section__actions">
				<NcButton
					v-if="contactId"
					variant="secondary"
					data-testid="subscriptions-manage"
					@click="showManage = true">
					{{ t('pipelinq', 'Manage subscriptions') }}
				</NcButton>
				<NcButton variant="tertiary" @click="load">
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
			</div>
		</template>

		<SubscriptionManageModal
			v-if="showManage"
			:contactId="contactId"
			:subscriptions="rows"
			@close="showManage = false"
			@changed="onChanged" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import SubscriptionManageModal from '../../modals/SubscriptionManageModal.vue'
import {
	fetchContactSubscriptions,
	fetchListSubscriptions,
} from '../../services/mailingListApi.js'
import {
	chipForState,
	countByState,
	SUBSCRIPTION_STATES,
} from '../../services/subscriptionState.js'

export default {
	name: 'SubscriptionsSection',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		SubscriptionManageModal,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The mailing list id, on the list detail page. */
		listId: {
			type: String,
			default: '',
		},

		/** The contact id, on the contact detail page. */
		contactId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			counts: countByState([]),
			showManage: false,
		}
	},

	computed: {
		/**
		 * Counts are a property of a LIST. On a contact the same strip would
		 * read as "this person is one of four states at once".
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {boolean} Whether to render the counts strip.
		 */
		showCounts() {
			return Boolean(this.listId)
		},

		/**
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {Array<object>} One entry per declared state.
		 */
		countEntries() {
			return SUBSCRIPTION_STATES.map((state) => ({
				state,
				value: this.counts[state] || 0,
				label: chipForState(state).label,
				color: chipForState(state).color,
			}))
		},

		/**
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {string} What an empty result means here.
		 */
		emptyText() {
			if (this.listId) {
				return this.t('pipelinq', 'Nobody has joined this list yet.')
			}
			return this.t(
				'pipelinq',
				'This person is on no mailing list. Add them from a list, or send them a preference link.',
			)
		},

		/**
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {string} The table caption, for screen readers.
		 */
		tableCaption() {
			if (this.listId) {
				return this.t('pipelinq', 'Subscribers on this list')
			}
			return this.t('pipelinq', 'Mailing lists this person is on')
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Load the memberships for whichever side this section is bound to.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {Promise<void>} Resolves when the rows are in place.
		 */
		async load() {
			const listId = this.listId || this.contextId('listId')
			const contactId = this.contactId || this.contextId('contactId')
			if (!listId && !contactId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				if (listId) {
					const envelope = await fetchListSubscriptions(listId)
					this.rows = envelope.data || []
					this.counts = envelope.counts || countByState(this.rows)
				} else {
					this.rows = await fetchContactSubscriptions(contactId)
					this.counts = countByState(this.rows)
				}
			} catch {
				this.error = this.t(
					'pipelinq',
					'The subscriptions could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Reload after the modal changed something.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-unsubscribe-is-first-party-and-takes-one-click
		 * @return {Promise<void>} Resolves when the rows are refreshed.
		 */
		async onChanged() {
			this.showManage = false
			await this.load()
		},

		/**
		 * The object id the page host resolved, when no prop was bound.
		 *
		 * @param {string} key Which id to read.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {string} The id, or an empty string.
		 */
		contextId(key) {
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			if (!bag) {
				return ''
			}
			return (key === 'listId' ? bag.listId : bag.contactId) || ''
		},

		/**
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {object} The chip for the row's state.
		 */
		chip(row) {
			return chipForState(row && row.state)
		},

		/**
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {string} A stable key for the row.
		 */
		rowKey(row) {
			return String(
				(row && (row.id || row.uuid)) || `${row.listId}:${row.email}`,
			)
		},

		/**
		 * On a list the row names the person; on a contact it names the list.
		 *
		 * @param {object} row A subscription payload.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {string} What this row is about.
		 */
		subscriberLabel(row) {
			if (this.listId) {
				return row.email || row.contactId || '—'
			}
			return row.listId || '—'
		},

		/**
		 * @param {string} value An ISO 8601 timestamp, or nothing.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-list-carries-its-own-sender-identity-and-opt-in-mode
		 * @return {string} The date part, or an em space placeholder.
		 */
		formatDate(value) {
			if (!value) {
				return '—'
			}
			return String(value).slice(0, 10)
		},
	},
}
</script>

<style scoped>
.subscriptions-section__counts {
	display: flex;
	flex-wrap: wrap;
	gap: 1.5rem;
	list-style: none;
	margin: 0 0 1rem;
	padding: 0;
}

.subscriptions-section__count {
	display: flex;
	align-items: center;
	gap: 0.4rem;
}

.subscriptions-section__swatch {
	display: inline-block;
	width: 0.6rem;
	height: 0.6rem;
	border-radius: 50%;
}

.subscriptions-section__table {
	width: 100%;
	border-collapse: collapse;
}

.subscriptions-section__caption {
	text-align: start;
	padding-block-end: 0.5rem;
	color: var(--color-text-maxcontrast);
}

.subscriptions-section__table th,
.subscriptions-section__table td {
	text-align: start;
	padding: 0.4rem 0.6rem;
	border-block-end: 1px solid var(--color-border);
}

.subscriptions-section__chip {
	display: inline-flex;
	align-items: center;
	gap: 0.35rem;
	padding: 0.1rem 0.5rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-pill, 1rem);
}

.subscriptions-section__empty {
	color: var(--color-text-maxcontrast);
}

.subscriptions-section__actions {
	display: flex;
	gap: 0.5rem;
	margin-block-start: 1rem;
}
</style>

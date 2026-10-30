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
  - @spec openspec/specs/marketing-lists/spec.md#requirement-the-subscribers-table-fits-its-card
  -->
<template>
	<component
		:is="card ? 'CnDetailCard' : 'div'"
		v-bind="cardProps"
		class="subscriptions-section"
		:class="{ 'subscriptions-section--card': card }">
		<template v-if="card" #actions>
			<NcButton variant="secondary" data-testid="subscriptions-refresh" @click="load">
				<template #icon>
					<Refresh :size="18" />
				</template>
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
			<NcButton
				v-if="listId"
				variant="secondary"
				data-testid="subscriptions-manage-signups"
				@click="showSignups = true">
				<template #icon>
					<AccountGroup :size="18" />
				</template>
				{{ t('pipelinq', 'Manage sign-ups') }}
			</NcButton>
		</template>
		<header v-if="listId && !card" class="subscriptions-section__head">
			<p class="subscriptions-section__subtitle">
				{{ t('pipelinq', 'Subscribers on this list') }}
			</p>
			<NcButton variant="secondary" @click="load">
				<template #icon>
					<Refresh :size="18" />
				</template>
				{{ t('pipelinq', 'Refresh') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcNoteCard v-else-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<template v-else>
			<p v-if="rows.length === 0" class="subscriptions-section__empty">
				{{ emptyText }}
			</p>

			<table v-else class="subscriptions-section__table">
				<caption class="hidden-visually">
					{{
						tableCaption
					}}
				</caption>
				<thead>
					<tr>
						<th
							v-for="column in columns"
							:key="column.key"
							scope="col"
							:aria-sort="sortKey === column.key ? (sortDir === 'asc' ? 'ascending' : 'descending') : 'none'">
							<span class="subscriptions-section__th">
								<button
									type="button"
									class="subscriptions-section__sort"
									:data-testid="`subscriptions-sort-${column.key}`"
									@click="sortBy(column.key)">
									{{ column.label }}
									<MenuUp v-if="sortKey === column.key && sortDir === 'desc'" :size="16" />
									<MenuDown v-else :size="16" />
								</button>
								<NcActions
									:aria-label="t('pipelinq', 'Filter on {column}', { column: column.label })"
									:data-testid="`subscriptions-filter-${column.key}`">
									<template #icon>
										<FilterOutline :size="16" />
									</template>
									<NcActionCheckbox
										v-for="option in filterOptions(column)"
										:key="option"
										:modelValue="isFiltered(column.key, option)"
										@update:modelValue="toggleFilter(column.key, option, $event)">
										{{ option }}
									</NcActionCheckbox>
								</NcActions>
							</span>
						</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in shownRows" :key="rowKey(row)">
						<td>
							<strong class="subscriptions-section__who">{{ subscriberLabel(row) }}</strong>
							<span v-if="listId && row.name && row.email" class="subscriptions-section__email">{{ row.email }}</span>
						</td>
						<td>
							<CnStatusBadge
								:label="stateLabel(row.state)"
								:variant="stateVariant(row.state)"
								size="small" />
						</td>
						<td>{{ row.source || '—' }}</td>
						<td>{{ groundLabel(row.lawfulBasis) }}</td>
						<td :class="{ 'subscriptions-section__muted': !row.confirmedAt }">
							{{ row.confirmedAt ? formatDate(row.confirmedAt) : t('pipelinq', 'not yet') }}
						</td>
					</tr>
				</tbody>
			</table>

			<NcNoteCard v-if="listId" type="info">
				{{ t('pipelinq', 'An unconfirmed sign-up never gets a blast. The person is only added after opening the confirmation link.') }}
			</NcNoteCard>

			<div v-if="contactId" class="subscriptions-section__actions">
				<NcButton
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

		<ListSignupsManageDialog
			v-if="showSignups"
			:listId="listId"
			:subscriptions="rows"
			@close="showSignups = false"
			@changed="load" />

		<SubscriptionManageModal
			v-if="showManage"
			:contactId="contactId"
			:subscriptions="rows"
			@close="showManage = false"
			@changed="onChanged" />
	</component>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { getLanguage } from '@nextcloud/l10n'
import { NcActionCheckbox, NcActions, NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import MenuDown from 'vue-material-design-icons/MenuDown.vue'
import MenuUp from 'vue-material-design-icons/MenuUp.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import ListSignupsManageDialog from '../../dialogs/ListSignupsManageDialog.vue'
import SubscriptionManageModal from '../../modals/SubscriptionManageModal.vue'
import {
	fetchContactSubscriptions,
	fetchListSubscriptions,
} from '../../services/mailingListApi.js'
import {
	chipForState,
	countByState,
} from '../../services/subscriptionState.js'

export default {
	name: 'SubscriptionsSection',

	components: {
		AccountGroup,
		CnDetailCard,
		FilterOutline,
		ListSignupsManageDialog,
		MenuDown,
		MenuUp,
		NcActionCheckbox,
		NcActions,
		CnStatusBadge,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		Refresh,
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

		/**
		 * Draw the section as its own titled card, with the subtitle and
		 * Refresh in the card's header (the list page's Subscribers card).
		 */
		card: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			loading: false,
			error: '',
			rows: [],
			counts: countByState([]),
			showManage: false,
			showSignups: false,
			sortKey: '',
			sortDir: 'asc',
			filters: {},
		}
	},

	computed: {
		/**
		 * The table's columns: each a label, the value a filter offers and
		 * the value a sort compares.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-subscribers-can-be-sorted-and-filtered
		 * @return {Array<{key: string, label: string, text: (row: object) => string, sort: (row: object) => string}>}
		 */
		columns() {
			return [
				{ key: 'subscriber', label: this.t('pipelinq', 'Subscriber'), text: (row) => this.subscriberLabel(row), sort: (row) => this.subscriberLabel(row).toLowerCase() },
				{ key: 'state', label: this.t('pipelinq', 'Status'), text: (row) => this.stateLabel(row.state), sort: (row) => this.stateLabel(row.state) },
				{ key: 'source', label: this.t('pipelinq', 'Source'), text: (row) => row.source || '—', sort: (row) => row.source || '' },
				{ key: 'ground', label: this.t('pipelinq', 'Ground'), text: (row) => this.groundLabel(row.lawfulBasis), sort: (row) => this.groundLabel(row.lawfulBasis) },
				{ key: 'confirmed', label: this.t('pipelinq', 'Confirmed'), text: (row) => (row.confirmedAt ? this.formatDate(row.confirmedAt) : this.t('pipelinq', 'not yet')), sort: (row) => row.confirmedAt || '' },
			]
		},

		/**
		 * The rows the table shows: those every column filter lets through,
		 * in the chosen order.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-subscribers-can-be-sorted-and-filtered
		 * @return {Array<object>}
		 */
		shownRows() {
			const columns = this.columns
			let out = this.rows.filter((row) => columns.every((column) => {
				const chosen = this.filters[column.key]
				return !chosen || chosen.length === 0 || chosen.includes(column.text(row))
			}))
			const column = columns.find((c) => c.key === this.sortKey)
			if (column) {
				const dir = this.sortDir === 'desc' ? -1 : 1
				out = [...out].sort((a, b) => String(column.sort(a)).localeCompare(String(column.sort(b))) * dir)
			}
			return out
		},

		/**
		 * The card's title and subtitle, when the section is its own card.
		 *
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-mailing-lists-subscribers-read-by-name
		 * @return {object} Props for CnDetailCard, or none.
		 */
		cardProps() {
			if (!this.card) {
				return {}
			}
			return {
				title: this.t('pipelinq', 'Subscribers'),
				subtitle: this.listId ? this.t('pipelinq', 'Subscribers on this list') : '',
			}
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
		 * Sort on a column; the same column again turns the order around.
		 *
		 * @param {string} key The column.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-the-subscribers-can-be-sorted-and-filtered
		 */
		sortBy(key) {
			if (this.sortKey === key) {
				this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc'
				return
			}
			this.sortKey = key
			this.sortDir = 'asc'
		},

		/**
		 * The values a column's filter offers: those its rows hold.
		 *
		 * @param {object} column The column.
		 * @return {Array<string>}
		 */
		filterOptions(column) {
			return [...new Set(this.rows.map((row) => column.text(row)))].sort()
		},

		/**
		 * Whether a column's filter holds a value.
		 *
		 * @param {string} key The column.
		 * @param {string} option The value.
		 * @return {boolean}
		 */
		isFiltered(key, option) {
			return (this.filters[key] || []).includes(option)
		},

		/**
		 * Add a value to a column's filter, or take it out.
		 *
		 * @param {string} key The column.
		 * @param {string} option The value.
		 * @param {boolean} on Whether the value is chosen.
		 */
		toggleFilter(key, option, on) {
			const now = (this.filters[key] || []).filter((value) => value !== option)
			this.filters = { ...this.filters, [key]: on ? [...now, option] : now }
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
		/**
		 * A membership state in words, as the list board writes it.
		 *
		 * @param {string} state The stored state.
		 * @return {string} The label.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
		 */
		stateLabel(state) {
			const labels = {
				pending: this.t('pipelinq', 'Waiting for confirmation'),
				confirmed: this.t('pipelinq', 'Confirmed'),
				unsubscribed: this.t('pipelinq', 'Unsubscribed'),
				bounced: this.t('pipelinq', 'Undeliverable'),
			}
			return labels[state] || state || '—'
		},

		/**
		 * The tone of a membership state's pill.
		 *
		 * @param {string} state The stored state.
		 * @return {string} A CnStatusBadge variant.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-a-pending-subscription-never-receives-a-blast
		 */
		stateVariant(state) {
			return { pending: 'warning', confirmed: 'success', unsubscribed: 'default', bounced: 'error' }[state] || 'default'
		},

		/**
		 * The lawful ground in words.
		 *
		 * @param {string} ground The stored lawful basis.
		 * @return {string} The label.
		 * @spec openspec/specs/marketing-lists/spec.md#requirement-soft-opt-in-records-its-ground-and-the-objection-offered
		 */
		groundLabel(ground) {
			const labels = {
				consent: this.t('pipelinq', 'consent'),
				'soft-opt-in': this.t('pipelinq', 'soft opt-in'),
				'legitimate-interest': this.t('pipelinq', 'legitimate interest'),
				contract: this.t('pipelinq', 'contract'),
			}
			return labels[ground] || ground || '—'
		},

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
				return row.name || row.email || row.contactId || '—'
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
			const date = new Date(value)
			if (Number.isNaN(date.getTime())) {
				return String(value).slice(0, 10)
			}
			return date.toLocaleDateString(getLanguage(), { day: 'numeric', month: 'short', year: 'numeric' })
		},
	},
}
</script>

<style scoped lang="scss">
.subscriptions-section__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.subscriptions-section__subtitle {
	margin: -8px 0 0;
	color: var(--color-text-maxcontrast);
}

.subscriptions-section__who {
	display: block;
}

.subscriptions-section__email,
.subscriptions-section__muted {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

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

/* The filter funnel is a 22px button with a 4px radius, as the boards draw
   it, not Nextcloud's 34px clickable area. */
.subscriptions-section__th {
	--default-clickable-area: 22px;
	--border-radius-element: 4px;
	display: inline-flex;
	align-items: center;
	gap: 4px;
}

/* The sort button is the bare label: Nextcloud's `button` rule gives it a
   34px height, a width and a radius the boards do not draw. */
.subscriptions-section__sort {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	width: auto;
	min-height: 0;
	height: auto;
	padding: 0;
	border: 0;
	border-radius: 0;
	background: transparent;
	font: inherit;
	color: inherit;
	cursor: pointer;
}

/* In a card the table runs to the card's edges, as every Pq card table does
   (PqMailinglijst): the board look's card padding is taken back, and the
   first column starts 18px in. */
.subscriptions-section--card .subscriptions-section__table {
	width: calc(100% + 2 * var(--cn-board-card-inline-padding, 0px));
	margin-inline: calc(-1 * var(--cn-board-card-inline-padding, 0px));
	line-height: normal;
}

/* The head sits on the card's ground. The theme paints every `th` and the
   buttons in it through its table-header and button tokens, so those get
   the board's ground and weight too. */
.subscriptions-section--card .subscriptions-section__table th {
	--nldesign-component-table-header-background-color: var(--color-main-background);
	--nldesign-component-table-header-color: var(--color-text-maxcontrast);
	--nldesign-component-button-font-weight: 600;
	padding: 10px 12px;
	background: var(--color-main-background);
	font-size: 14px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.subscriptions-section--card .subscriptions-section__table td {
	padding: 12px;
	vertical-align: middle;
	white-space: normal;
}

.subscriptions-section--card .subscriptions-section__table th:first-child,
.subscriptions-section--card .subscriptions-section__table td:first-child {
	padding-inline-start: 18px;
}

.subscriptions-section--card .subscriptions-section__who {
	font-size: 15px;
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
	/* In the side column the two buttons are wider than the card: wrap
	   them instead of squeezing the first one until its label is cut. */
	flex-wrap: wrap;
	gap: 0.5rem;
	margin-block-start: 1rem;
}
</style>

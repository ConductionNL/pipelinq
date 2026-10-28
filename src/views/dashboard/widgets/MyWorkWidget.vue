<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
	MyWorkWidget — thin wrapper over the canonical worklist endpoint
	(ADR-049 Wave 4). The leads+requests union that used to be computed
	client-side now comes pre-merged + pre-sorted from
	GET /apps/pipelinq/api/worklist/mine (WorklistController). This widget
	is kept CUSTOM — and NOT dissolved into the built-in `object-table`
	widget — because each row navigates to a DIFFERENT route (LeadDetail
	vs TicketDetail via the row's `routeName` field) and object-table's
	`rowRoute` is a single static route name that cannot express a
	per-row destination (ConductionNL/nextcloud-vue#91 Wave 2 gap).
-->
<template>
	<div class="my-work">
		<p v-if="!loaded" class="my-work__note">
			{{ t('pipelinq', 'Loading…') }}
		</p>
		<p v-else-if="items.length === 0" class="my-work__note">
			{{ emptyText }}
		</p>
		<ul v-else class="my-work__list">
			<li v-for="item in items" :key="item.entityType + item.id">
				<router-link class="my-work__row" :to="itemRoute(item)">
					<span class="my-work__icon" :class="'my-work__icon--' + item.entityType" aria-hidden="true">
						<CashMultiple v-if="item.entityType === 'lead'" :size="18" />
						<FileOutline v-else :size="18" />
					</span>
					<span class="my-work__text">
						<strong class="my-work__title">{{ item.title }}</strong>
						<span class="my-work__meta">{{ metaLine(item) }}</span>
					</span>
					<CnStatusBadge
						v-if="duePill(item)"
						:label="duePill(item).label"
						:variant="duePill(item).variant" />
				</router-link>
			</li>
		</ul>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import FileOutline from 'vue-material-design-icons/FileOutline.vue'
import { formatDate } from '../../../services/localeUtils.js'
import { dueState } from '../../../utils/dueState.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

const WIDGET_LIMIT = 5

/**
 * Legacy detail-route names still emitted by the server-side worklist rows
 * (WorklistService), mapped onto the unified ticket detail page. The
 * request/complaint/contactmoment schemas collapsed into the `ticket`
 * supertype (unify-ticket-supertype) and their pages were retired, so any
 * legacy `routeName` must resolve to TicketDetail — the page reads the
 * object's own `ticketType` discriminator.
 */
const LEGACY_ROUTE_MAP = {
	RequestDetail: 'TicketDetail',
	ComplaintDetail: 'TicketDetail',
	ContactmomentDetail: 'TicketDetail',
}

export default {
	name: 'MyWorkWidget',
	components: {
		CashMultiple,
		CnStatusBadge,
		FileOutline,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			items: [],
			total: 0,
		}
	},

	computed: {
		/**
		 * The table's empty text.
		 *
		 * CnDataTable has no error state, so an empty `rows` after a failed
		 * fetch would render "No items assigned to you" — a sentence asserting
		 * that the user has nothing to do, when in fact nothing was read. The
		 * wording is the only place that distinction can live here.
		 *
		 * @return {string} Empty-state text.
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		emptyText() {
			if (this.error) {
				return this.t('pipelinq', 'Could not load your items')
			}

			return this.t('pipelinq', 'No items assigned to you')
		},
	},

	methods: {
		/**
		 * The line under the title: the kind, then how the due date stands
		 * ("Request · 1 day late", "Lead · closes 30 Oct").
		 *
		 * @param {object} item - Work item row.
		 * @return {string} The line.
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		metaLine(item) {
			const kind = item.entityType === 'lead' ? this.t('pipelinq', 'Lead') : this.t('pipelinq', 'Request')
			const due = dueState(item.dueDate)
			if (due.state === null) {
				return item.stageOrStatus ? kind + ' · ' + item.stageOrStatus : kind
			}
			if (due.state === 'overdue') {
				return kind + ' · ' + this.n('pipelinq', '%n day late', '%n days late', due.daysLate)
			}
			if (due.state === 'today') {
				return kind + ' · ' + this.t('pipelinq', 'today')
			}
			const date = formatDate(item.dueDate)
			return kind + ' · ' + (item.entityType === 'lead'
				? this.t('pipelinq', 'closes {date}', { date })
				: this.t('pipelinq', 'due {date}', { date }))
		},

		/**
		 * The due pill: Late, Today or This week; none for later or no date.
		 *
		 * @param {object} item - Work item row.
		 * @return {{label: string, variant: string}|null} The pill.
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		duePill(item) {
			const state = item.isOverdue ? 'overdue' : dueState(item.dueDate).state
			if (state === 'overdue') {
				return { label: this.t('pipelinq', 'Late'), variant: 'error' }
			}
			if (state === 'today') {
				return { label: this.t('pipelinq', 'Today'), variant: 'primary' }
			}
			if (state === 'week') {
				return { label: this.t('pipelinq', 'This week'), variant: 'warning' }
			}
			return null
		},

		/**
		 * Fetch the current user's top-N worklist from the canonical
		 * server-side union endpoint (leads + requests, pre-sorted by
		 * overdue → priority → due date). The union + sort that this widget
		 * used to compute client-side now lives in WorklistService.
		 *
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		async load() {
			try {
				const response = await fetch(
					generateUrl(
						'/apps/pipelinq/api/worklist/mine?limit=' + WIDGET_LIMIT,
					),
					{
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				if (!response.ok) throw new Error('Failed to fetch worklist')
				const data = await response.json()
				this.items = Array.isArray(data.items) ? data.items : []
				this.total = Number(data.total) || this.items.length
			} finally {
				this.loaded = true
			}
		},

		/**
		 * The row's detail page, which the table renders as the row's link.
		 * The route differs per row (LeadDetail vs TicketDetail); the
		 * server-side worklist row carries the destination route name in
		 * `routeName`. Rows emitted before the ticket-supertype migration
		 * still carry a legacy detail-route name, so map those onto
		 * TicketDetail rather than routing into a page that no longer exists.
		 *
		 * @param {object} item - Work item row (lead or ticket).
		 * @return {object} The route location.
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		itemRoute(item) {
			const raw =
				item.routeName
				|| (item.entityType === 'lead' ? 'LeadDetail' : 'TicketDetail')
			const name = LEGACY_ROUTE_MAP[raw] || raw
			return { name, params: { id: item.id } }
		},
	},
}
</script>

<style scoped>
.my-work__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.my-work__row {
	display: flex;
	flex-wrap: wrap;
	gap: 8px 12px;
	align-items: center;
	padding: 12px 0;
	border-top: 1px solid var(--color-border);
	color: var(--color-main-text);
	text-decoration: none;
}

.my-work__icon {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	width: 34px;
	height: 34px;
	border-radius: var(--border-radius-large, 8px);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.my-work__icon--lead {
	background: var(--color-warning-light, var(--color-background-dark));
	color: var(--color-warning-text);
}

.my-work__text {
	display: flex;
	flex: 1 1 220px;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.my-work__title {
	font-size: 15px;
	font-weight: 600;
}

.my-work__meta,
.my-work__note {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.my-work__note {
	margin: 0;
}
</style>

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
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="!loaded"
		:loadingText="t('pipelinq', 'Loading…')"
		hideHeader
		borderless
		:emptyText="emptyText"
		:rowClass="rowClass"
		@rowClick="openItem">
		<template #column-entityType="{ row }">
			<span class="entity-badge" :class="'badge--' + row.entityType">
				{{ row.entityType === 'lead' ? 'LEAD' : 'REQ' }}
			</span>
		</template>
		<template #column-dueDate="{ row }">
			<span
				v-if="row.dueDate"
				class="my-work-due"
				:class="{ overdue: row.isOverdue }">
				{{ formatDate(row.dueDate) }}
			</span>
		</template>
		<template #footer>
			<NcButton
				v-if="total > items.length"
				variant="tertiary"
				class="view-all-link"
				@click="$router.push({ name: 'MyWork' })">
				{{ t('pipelinq', 'View all ({count})', { count: total }) }}
			</NcButton>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { formatDate } from '../../../services/localeUtils.js'
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
		CnDataTable,
		NcButton,
	},

	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			items: [],
			total: 0,
			columns: [
				{ key: 'entityType' },
				{ key: 'title', cellClass: 'cn-cell--strong' },
				{ key: 'stageOrStatus', cellClass: 'cn-cell--muted' },
				{ key: 'dueDate', cellClass: 'cn-cell--end' },
			],
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
		 */
		emptyText() {
			if (this.error) {
				return this.t('pipelinq', 'Could not load your items')
			}

			return this.t('pipelinq', 'No items assigned to you')
		},
	},

	methods: {
		formatDate,
		/**
		 * Row emphasis for overdue items (CnDataTable rowClass hook).
		 *
		 * @param {object} row - Work item row.
		 * @return {string} CSS class for the row.
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		rowClass(row) {
			return row.isOverdue ? 'my-work-row--overdue' : ''
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
		 * Navigate to the row's detail page. The route differs per row
		 * (LeadDetail vs TicketDetail); the server-side worklist row carries
		 * the destination route name in `routeName`. Rows emitted before the
		 * ticket-supertype migration still carry a legacy detail-route name,
		 * so map those onto TicketDetail rather than routing into a page that
		 * no longer exists.
		 *
		 * @param {object} item - Work item row (lead or ticket).
		 * @spec openspec/specs/dashboard/spec.md#requirement-my-work-widget
		 */
		openItem(item) {
			const raw =
				item.routeName
				|| (item.entityType === 'lead' ? 'LeadDetail' : 'TicketDetail')
			const name = LEGACY_ROUTE_MAP[raw] || raw
			this.$router.push({ name, params: { id: item.id } })
		},
	},
}
</script>

<style scoped>
:deep(.my-work-row--overdue) {
	background: rgba(233, 50, 45, 0.04);
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.badge--lead {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.my-work-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.my-work-due.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.view-all-link {
	margin-top: 4px;
}
</style>

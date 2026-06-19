<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Resource catalogue list — appointment-booking member 11.
  Columns: name, type, status, bookable.
  @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Resources')"
			:description="t('pipelinq', 'Staff, rooms and equipment that perform bookable services')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No resources yet')"
			:empty-action-label="t('pipelinq', 'New resource')"
			:add-label="t('pipelinq', 'New resource')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openResource"
			@page-changed="onPageChange">
			<template #cell-type="{ row }">
				{{ typeLabel(row.type) }}
			</template>
			<template #cell-status="{ row }">
				<span class="status-badge" :class="`status-badge--${row.status}`">
					{{ statusLabel(row.status) }}
				</span>
			</template>
			<template #cell-bookable="{ row }">
				<span v-if="row.bookable" aria-label="bookable">&#10004;</span>
				<span v-else aria-hidden="true">&nbsp;</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

const STATUS_LABELS = {
	active: 'Active',
	inactive: 'Inactive',
	archived: 'Archived',
}

const TYPE_LABELS = {
	staff: 'Staff',
	room: 'Room',
	equipment: 'Equipment',
}

export default {
	name: 'ResourceList',
	components: { CnIndexPage },
	/**
	 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
	 */
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('resource', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		visibleColumns() {
			return ['name', 'type', 'bookable', 'status']
		},
	},
	methods: {
		/**
		 * Refresh handler for the Actions-menu Refresh item. Drives the
		 * CnIndexPage `:refreshing` spinner around the underlying fetch.
		 *
		 * @spec exclude presentational refresh-button spinner wiring — no business logic
		 */
		async onRefresh() {
			this.refreshing = true
			try {
				await this.refresh()
			} finally {
				this.refreshing = false
			}
		},
		openResource(row) {
			this.$router.push({ name: 'ResourceDetail', params: { id: row.id } })
		},
		createNew() {
			this.$router.push({ name: 'ResourceDetail', params: { id: 'new' } })
		},
		typeLabel(type) {
			return t('pipelinq', TYPE_LABELS[type] || type || '-')
		},
		statusLabel(status) {
			return t('pipelinq', STATUS_LABELS[status] || status || '-')
		},
	},
}
</script>

<style scoped>
.status-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 10px;
	font-size: 12px;
	font-weight: 600;
}
.status-badge--active {
	background: var(--color-success);
	color: white;
}
.status-badge--inactive {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}
.status-badge--archived {
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}
</style>

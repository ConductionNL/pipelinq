<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Billing category management list — REQ-BCT-001.

  Schema-driven CnIndexPage over the billingCategory schema. CRUD is
  fully delegated to the shared @conduction/nextcloud-vue platform
  (CnIndexPage + useListView + objectStore). Categories are sorted
  client-side by type (billable -> non-billable -> internal) then name.
  A per-row color badge surfaces the category color from the schema's
  color property. Inactive categories render an "Inactief" badge.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Billing categories')"
			:description="t('pipelinq', 'Manage structured billing classifications (Declarabel, Niet-declarabel, Intern, WBSO, DBA) for time entries.')"
			:schema="schema"
			:objects="sortedObjects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No billing categories yet')"
			:empty-action-label="t('pipelinq', 'New category')"
			:add-label="t('pipelinq', 'New category')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openCategory"
			@page-changed="onPageChange">
			<template #cell-name="{ row }">
				<span class="billing-cat-name-cell">
					<span
						class="billing-cat-swatch"
						:style="{ backgroundColor: row.color || '#6c757d' }"
						aria-hidden="true" />
					<span>{{ row.name }}</span>
					<span v-if="row.isDba"
						class="billing-cat-badge billing-cat-badge-dba"
						:style="{ backgroundColor: row.color || '#fd7e14' }">
						{{ t('pipelinq', 'DBA') }}
					</span>
				</span>
			</template>
			<template #cell-type="{ row }">
				{{ typeLabel(row.type) }}
			</template>
			<template #cell-isDefault="{ row }">
				<span v-if="row.isDefault" aria-label="default">
					&#10004;
				</span>
				<span v-else aria-hidden="true">&nbsp;</span>
			</template>
			<template #cell-isActive="{ row }">
				<span class="billing-cat-badge"
					:class="row.isActive ? 'billing-cat-badge-active' : 'billing-cat-badge-inactive'">
					{{ row.isActive ? t('pipelinq', 'Active') : t('pipelinq', 'Inactive') }}
				</span>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'

const TYPE_ORDER = { billable: 0, 'non-billable': 1, internal: 2 }

export default {
	name: 'BillingCategoryList',
	components: {
		CnIndexPage,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('billingCategory', { sidebarState, objectStore })
	},
	data() {
		return {
			refreshing: false,
		}
	},
	computed: {
		/**
		 * Columns shown on the list, in order. REQ-BCT-001.
		 *
		 * @return {Array<string>} Column keys.
		 */
		visibleColumns() {
			return ['name', 'code', 'type', 'isDefault', 'isActive']
		},
		/**
		 * Sort client-side: billable first, then non-billable, then internal;
		 * alphabetical by name within each group. REQ-BCT-001 scenario:
		 * "Category list is sorted by type then name".
		 *
		 * @return {Array<object>} Sorted objects.
		 */
		sortedObjects() {
			const list = Array.isArray(this.objects) ? [...this.objects] : []
			return list.sort((a, b) => {
				const ta = TYPE_ORDER[a.type] ?? 99
				const tb = TYPE_ORDER[b.type] ?? 99
				if (ta !== tb) return ta - tb
				return String(a.name || '').localeCompare(String(b.name || ''), 'nl')
			})
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
		/**
		 * Localised label for the type enum.
		 *
		 * @param {string} type The category type.
		 * @return {string} The Dutch / English label.
		 */
		typeLabel(type) {
			if (type === 'billable') {
				return t('pipelinq', 'Billable')
			}
			if (type === 'non-billable') {
				return t('pipelinq', 'Non-billable')
			}
			if (type === 'internal') {
				return t('pipelinq', 'Internal')
			}
			return type || ''
		},
		/**
		 * Open the detail/edit view for a row. CnDetailPage drives the
		 * schema-generated edit form via the same useListView wiring.
		 *
		 * @param {object} row The clicked row.
		 */
		openCategory(row) {
			this.$router.push({ name: 'BillingCategoryDetail', params: { id: row.id } })
		},
		/**
		 * Start a new category — opens the detail "new" route which
		 * mounts CnDetailPage in create mode.
		 */
		createNew() {
			this.$router.push({ name: 'BillingCategoryNew' })
		},
	},
}
</script>

<style scoped>
.billing-cat-name-cell {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.billing-cat-swatch {
	display: inline-block;
	width: 12px;
	height: 12px;
	border-radius: 2px;
	border: 1px solid var(--color-border, #ddd);
	flex-shrink: 0;
}

.billing-cat-badge {
	display: inline-block;
	padding: 1px 8px;
	border-radius: 12px;
	font-size: 0.78em;
	font-weight: 600;
	white-space: nowrap;
}

.billing-cat-badge-active {
	background-color: #28a745;
	color: #ffffff;
}

.billing-cat-badge-inactive {
	background-color: #6c757d;
	color: #ffffff;
}

.billing-cat-badge-dba {
	color: #ffffff;
}
</style>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Kassakoppeling Audit List — append-only register of every POS-register
  - action (sale / void / refund / no-sale) signed with HMAC-SHA256 and linked
  - into a per-register hash chain. The list streams from the bespoke
  - /api/kassakoppeling/audit endpoint (NOT the OR object store) because the
  - controller is the single authority for write / verify / export. The export
  - button opens BelastingdienstExportDialog (admin-only).
  -
  - @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#5.1
  -->
<template>
	<div class="kassakoppeling-audit-list">
		<div class="kassakoppeling-audit-list__header">
			<CnPageHeader
				:title="t('pipelinq', 'Cash register audit log')"
				:description="t('pipelinq', 'Immutable, cryptographically signed record of every register action for Belastingdienst audits.')"
				icon="ShieldCheckOutline" />
			<div class="kassakoppeling-audit-list__actions">
				<NcButton :disabled="loading" @click="refresh">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<Refresh v-else :size="20" />
					</template>
					{{ t('pipelinq', 'Refresh') }}
				</NcButton>
				<NcButton
					v-if="isAdmin"
					variant="primary"
					data-testid="kassakoppeling-audit-export"
					@click="showExport = true">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('pipelinq', 'Export to Belastingdienst') }}
				</NcButton>
			</div>
		</div>

		<form
			class="kassakoppeling-audit-list__filters"
			data-testid="kassakoppeling-audit-filters"
			@submit.prevent="applyFilters">
			<NcDateTimePickerNative
				id="kk-filter-from"
				class="kassakoppeling-audit-list__filter"
				type="date"
				:label="t('pipelinq', 'From')"
				:modelValue="fromDate"
				:aria-label="t('pipelinq', 'Filter from date')"
				@update:modelValue="fromDate = $event" />
			<NcDateTimePickerNative
				id="kk-filter-to"
				class="kassakoppeling-audit-list__filter"
				type="date"
				:label="t('pipelinq', 'Up to and including')"
				:modelValue="toDate"
				:aria-label="t('pipelinq', 'Filter to date')"
				@update:modelValue="toDate = $event" />
			<NcTextField
				id="kk-filter-register"
				v-model="filters.registerNumber"
				class="kassakoppeling-audit-list__filter"
				:label="t('pipelinq', 'Register')"
				:placeholder="t('pipelinq', 'e.g. REG-001')"
				:aria-label="t('pipelinq', 'Filter by register number')" />
			<NcTextField
				id="kk-filter-operator"
				v-model="filters.operatorId"
				class="kassakoppeling-audit-list__filter"
				:label="t('pipelinq', 'Operator')"
				:placeholder="t('pipelinq', 'e.g. user_john')"
				:aria-label="t('pipelinq', 'Filter by operator')" />
			<NcSelect
				v-model="actionOption"
				inputId="kk-filter-action"
				class="kassakoppeling-audit-list__filter"
				:inputLabel="t('pipelinq', 'Action')"
				:options="actionOptions"
				:clearable="false"
				:aria-label-combobox="t('pipelinq', 'Filter by action')" />
			<div class="kassakoppeling-audit-list__filter-buttons">
				<NcButton type="submit" variant="secondary">
					<template #icon>
						<FilterOutline :size="20" />
					</template>
					{{ t('pipelinq', 'Apply filter') }}
				</NcButton>
				<NcButton variant="tertiary" :disabled="!canClear" @click="clearFilters">
					{{ t('pipelinq', 'Clear') }}
				</NcButton>
			</div>
		</form>

		<CnDataTable
			class="kassakoppeling-audit-list__table"
			data-testid="kassakoppeling-audit-table"
			:columns="columns"
			:rows="pageEntries"
			:loading="loading"
			:loadingText="t('pipelinq', 'Load audit log')"
			:emptyText="t('pipelinq', 'No audit entries found for the selected filters.')"
			rowKey="_rowKey"
			@rowClick="openDetail">
			<template #column-timestamp="{ row }">
				{{ formatTimestamp(row.timestamp) }}
			</template>
			<template #column-operatorId="{ row }">
				{{ row.operatorId || '—' }}
			</template>
			<template #column-registerNumber="{ row }">
				{{ row.registerNumber || '—' }}
			</template>
			<template #column-action="{ row }">
				<CnStatusBadge
					:label="actionLabel(row.action)"
					:variant="actionVariant(row.action)"
					size="small" />
			</template>
			<template #column-amount="{ row }">
				{{ formatEur(row.amount) }}
			</template>
			<template #column-verified="{ row }">
				<CnStatusBadge
					:label="verifyLabel(row.verified)"
					:variant="verifyVariant(row.verified)"
					size="small" />
			</template>
			<template #column-chevron>
				<ChevronRight :size="20" class="kassakoppeling-audit-list__chevron" />
			</template>
		</CnDataTable>

		<CnPagination
			:currentPage="page"
			:totalPages="totalPages"
			:totalItems="sortedEntries.length"
			:currentPageSize="pageSize"
			@pageChanged="page = $event"
			@pageSizeChanged="onPageSizeChange" />

		<BelastingdienstExportDialog
			v-if="showExport"
			:submitting="exporting"
			@close="showExport = false"
			@confirm="downloadExport" />
	</div>
</template>

<script>
import { CnDataTable, CnPageHeader, CnPagination, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDateTimePickerNative, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import Download from 'vue-material-design-icons/Download.vue'
import FilterOutline from 'vue-material-design-icons/FilterOutline.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import BelastingdienstExportDialog from '../../dialogs/BelastingdienstExportDialog.vue'
import { toDateInputString, toDateObject } from '../../services/localeUtils.js'

const PAGE_SIZE = 25

/**
 * A filter set with nothing chosen.
 *
 * @return {object} The empty filters.
 */
function emptyFilters() {
	return { from: '', to: '', registerNumber: '', operatorId: '', action: '' }
}

/**
 * Whether a filter set has any value chosen.
 *
 * @param {object} filters The filters.
 * @return {boolean} True when at least one filter is set.
 */
function hasAnyFilter(filters) {
	return Object.values(filters).some((value) => value !== '')
}

const ACTION_LABELS = {
	sale: 'Sale',
	void: 'Void',
	refund: 'Refund',
	'no-sale': 'No sale',
}

// Badge colour per action: a sale is the normal case, a void undoes one,
// a refund gives money back and a no-sale only opens the drawer.
const ACTION_VARIANTS = {
	sale: 'success',
	void: 'error',
	refund: 'warning',
	'no-sale': 'default',
}

export default {
	name: 'KassakoppelingAuditList',
	components: {
		BelastingdienstExportDialog,
		ChevronRight,
		CnDataTable,
		CnPageHeader,
		CnPagination,
		CnStatusBadge,
		Download,
		FilterOutline,
		NcButton,
		NcDateTimePickerNative,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		Refresh,
	},

	data() {
		return {
			entries: [],
			loading: false,
			showExport: false,
			exporting: false,
			page: 1,
			pageSize: PAGE_SIZE,
			filters: emptyFilters(),
			// The filters the list was last fetched with. Clear only fetches
			// again when these narrowed the list; edits that were never
			// applied are just emptied.
			appliedFilters: emptyFilters(),
		}
	},

	computed: {
		/** @return {boolean} Whether Clear has anything to clear. */
		canClear() {
			return hasAnyFilter(this.filters) || hasAnyFilter(this.appliedFilters)
		},

		/**
		 * The table columns. The last one is the row's chevron affordance,
		 * presentational only, so it has no label.
		 *
		 * @return {Array<object>} CnDataTable column definitions.
		 */
		columns() {
			return [
				{ key: 'timestamp', label: t('pipelinq', 'Time') },
				{ key: 'operatorId', label: t('pipelinq', 'Operator') },
				{ key: 'registerNumber', label: t('pipelinq', 'Register') },
				{ key: 'action', label: t('pipelinq', 'Action') },
				{ key: 'amount', label: t('pipelinq', 'Amount'), class: 'num', cellClass: 'num' },
				{ key: 'verified', label: t('pipelinq', 'Verification') },
				{ key: 'chevron', label: '', class: 'chevron-col', cellClass: 'chevron-col' },
			]
		},

		/** @return {Array<{id: string, label: string}>} The action filter choices. */
		actionOptions() {
			return [
				{ id: '', label: t('pipelinq', 'All actions') },
				{ id: 'sale', label: t('pipelinq', 'Sale') },
				{ id: 'void', label: t('pipelinq', 'Cancellation') },
				{ id: 'refund', label: t('pipelinq', 'Refund') },
				{ id: 'no-sale', label: t('pipelinq', 'No sale') },
			]
		},

		actionOption: {
			get() {
				return this.actionOptions.find((o) => o.id === this.filters.action) || this.actionOptions[0]
			},

			set(option) {
				this.filters.action = option?.id || ''
			},
		},

		fromDate: {
			get() {
				return toDateObject(this.filters.from)
			},

			set(date) {
				this.filters.from = toDateInputString(date) || ''
			},
		},

		toDate: {
			get() {
				return toDateObject(this.filters.to)
			},

			set(date) {
				this.filters.to = toDateInputString(date) || ''
			},
		},

		/**
		 * Whether the acting user is a Nextcloud admin (controls export button).
		 *
		 * @return {boolean} Whether the user is admin.
		 */
		isAdmin() {
			return typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
		},

		/**
		 * Entries displayed in descending chronological order.
		 *
		 * @return {Array<object>} The entries reversed (newest first).
		 */
		sortedEntries() {
			const copy = this.entries.slice()
			copy.sort((left, right) =>
				String(right.timestamp || '').localeCompare(
					String(left.timestamp || ''),
				),
			)
			return copy
		},

		/**
		 * Total number of pages at the configured page size.
		 *
		 * @return {number} The total page count.
		 */
		totalPages() {
			return Math.max(1, Math.ceil(this.sortedEntries.length / this.pageSize))
		},

		/**
		 * Entries to show on the current page.
		 *
		 * @return {Array<object>} The page slice.
		 */
		pageEntries() {
			const start = (this.page - 1) * this.pageSize
			// The table keys rows by one field; an entry may carry an id, a
			// uuid or only its timestamp.
			return this.sortedEntries
				.slice(start, start + this.pageSize)
				.map((entry) => ({ ...entry, _rowKey: entry.id || entry.uuid || entry.timestamp }))
		},
	},

	async mounted() {
		await this.refresh()
	},

	methods: {
		/**
		 * Load entries from the bespoke audit endpoint.
		 *
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#5.1
		 */
		async refresh() {
			this.loading = true
			try {
				const params = new URLSearchParams()
				if (this.filters.from) {
					params.set('from', this.filters.from)
				}
				if (this.filters.to) {
					params.set('to', this.filters.to)
				}
				if (this.filters.registerNumber) {
					params.set('registerNumber', this.filters.registerNumber)
				}
				if (this.filters.operatorId) {
					params.set('operatorId', this.filters.operatorId)
				}
				if (this.filters.action) {
					params.set('action', this.filters.action)
				}
				const url = generateUrl(
					`/apps/pipelinq/api/kassakoppeling/audit?${params.toString()}`,
				)
				const response = await fetch(url, {
					method: 'GET',
					headers: {
						Accept: 'application/json',
						requesttoken: OC.requestToken,
					},
				})
				if (!response.ok) {
					showError(t('pipelinq', 'Could not load audit log.'))
					this.entries = []
					return
				}
				const data = await response.json()
				this.entries = Array.isArray(data.entries) ? data.entries : []
				this.appliedFilters = { ...this.filters }
				this.page = 1
			} catch {
				showError(t('pipelinq', 'Could not load audit log.'))
				this.entries = []
			} finally {
				this.loading = false
			}
		},

		/**
		 * Apply the current filter inputs by reloading.
		 */
		applyFilters() {
			this.refresh()
		},

		/**
		 * Clear all filters, and reload only when the list was filtered.
		 */
		clearFilters() {
			const wasFiltered = hasAnyFilter(this.appliedFilters)
			this.filters = emptyFilters()
			if (wasFiltered) {
				this.refresh()
			}
		},

		/**
		 * Change the page size and go back to the first page.
		 *
		 * @param {number} size The new page size.
		 */
		onPageSizeChange(size) {
			this.pageSize = Number(size) || PAGE_SIZE
			this.page = 1
		},

		/**
		 * Open the detail view for an audit entry.
		 *
		 * @param {object} entry The entry to open.
		 */
		openDetail(entry) {
			const id = entry.id || entry.uuid
			if (!id) {
				return
			}
			this.$router.push({ name: 'KassakoppelingAuditDetail', params: { id } })
		},

		/**
		 * Format an ISO timestamp using the nl-NL locale.
		 *
		 * @param {string} value The ISO timestamp.
		 * @return {string} The formatted value.
		 * @spec exclude display formatter: ISO timestamp to an nl-NL date
		 */
		formatTimestamp(value) {
			if (!value) {
				return '—'
			}
			try {
				return new Date(value).toLocaleString('nl-NL', {
					year: 'numeric',
					month: 'short',
					day: '2-digit',
					hour: '2-digit',
					minute: '2-digit',
					second: '2-digit',
				})
			} catch {
				return value
			}
		},

		/**
		 * Format an integer amount in cents as a localised EUR string.
		 *
		 * @param {number|string} cents The amount in cents.
		 * @return {string} The formatted EUR value.
		 * @spec exclude display formatter: integer cents to a localised EUR string
		 */
		formatEur(cents) {
			const value = Number.isFinite(Number(cents)) ? Number(cents) / 100 : 0
			try {
				return new Intl.NumberFormat('nl-NL', {
					style: 'currency',
					currency: 'EUR',
				}).format(value)
			} catch {
				return `€ ${value.toFixed(2)}`
			}
		},

		/**
		 * Get the localised label for an action.
		 *
		 * @param {string} action The action enum value.
		 * @return {string} The localised label.
		 */
		actionLabel(action) {
			return t('pipelinq', ACTION_LABELS[action] || action || '—')
		},

		/**
		 * Get the badge variant for an action.
		 *
		 * @param {string} action The action enum value.
		 * @return {string} A CnStatusBadge variant.
		 */
		actionVariant(action) {
			return ACTION_VARIANTS[action] || 'default'
		},

		/**
		 * Get the localised label for a verification flag.
		 *
		 * @param {boolean|null} verified The flag.
		 * @return {string} The localised label.
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#5.1
		 */
		verifyLabel(verified) {
			if (verified === true) {
				return t('pipelinq', 'Verified')
			}
			if (verified === false) {
				return t('pipelinq', 'Tampering detected')
			}
			return t('pipelinq', 'Yet to verify')
		},

		/**
		 * Get the badge variant for a verification flag.
		 *
		 * @param {boolean|null} verified The flag.
		 * @return {string} A CnStatusBadge variant.
		 */
		verifyVariant(verified) {
			if (verified === true) {
				return 'success'
			}
			if (verified === false) {
				return 'error'
			}
			return 'default'
		},

		/**
		 * Download the Belastingdienst export pack and stream it to disk.
		 *
		 * @param {object} payload The selected from / to / format.
		 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#5.1
		 */
		async downloadExport(payload) {
			this.exporting = true
			try {
				const params = new URLSearchParams({
					from: payload.from,
					to: payload.to,
					format: payload.format,
				})
				const url = generateUrl(
					`/apps/pipelinq/api/kassakoppeling/audit/export?${params.toString()}`,
				)
				const response = await fetch(url, {
					method: 'GET',
					headers: { requesttoken: OC.requestToken },
				})
				if (!response.ok) {
					if (response.status === 403) {
						showError(
							t(
								'pipelinq',
								'Only administrators may export to the Belastingdienst.',
							),
						)
					} else {
						showError(t('pipelinq', 'Belastingdienst export failed.'))
					}
					return
				}
				const blob = await response.blob()
				const disposition = response.headers.get('Content-Disposition') || ''
				const matched = disposition.match(/filename="?([^";]+)"?/)
				const filename = matched
					? matched[1]
					: `kassakoppeling-export-${payload.from}-to-${payload.to}.${payload.format}`
				const objectUrl = window.URL.createObjectURL(blob)
				const link = document.createElement('a')
				link.href = objectUrl
				link.download = filename
				document.body.appendChild(link)
				link.click()
				link.remove()
				window.URL.revokeObjectURL(objectUrl)
				showSuccess(t('pipelinq', 'Belastingdienst export downloaded.'))
				this.showExport = false
				await this.refresh()
			} catch {
				showError(t('pipelinq', 'Belastingdienst export failed.'))
			} finally {
				this.exporting = false
			}
		},
	},
}
</script>

<style scoped>
.kassakoppeling-audit-list {
	padding: 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.kassakoppeling-audit-list__header {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.kassakoppeling-audit-list__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.kassakoppeling-audit-list__filters {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.kassakoppeling-audit-list__filter {
	flex: 1 1 160px;
	min-width: 160px;
	margin: 0;
}

.kassakoppeling-audit-list__filter-buttons {
	display: flex;
	gap: 8px;
	margin-inline-start: auto;
}

.kassakoppeling-audit-list__table :deep(.num) {
	text-align: end;
}

.kassakoppeling-audit-list__table :deep(.chevron-col) {
	width: 1%;
	padding-inline: 4px;
}

.kassakoppeling-audit-list__chevron {
	display: block;
	color: var(--color-text-maxcontrast);
}
</style>

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
			<div>
				<h2>{{ t('pipelinq', 'Cash register audit log') }}</h2>
				<p class="kassakoppeling-audit-list__subtitle">
					{{ t('pipelinq', 'Immutable, cryptographically signed record of every register action for Belastingdienst audits.') }}
				</p>
			</div>
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

		<div class="kassakoppeling-audit-list__filters" data-testid="kassakoppeling-audit-filters">
			<div class="filter-cell">
				<label for="kk-filter-from">{{ t('pipelinq', 'From') }}</label>
				<input
					id="kk-filter-from"
					v-model="filters.from"
					type="date"
					:aria-label="t('pipelinq', 'Filter from date')">
			</div>
			<div class="filter-cell">
				<label for="kk-filter-to">{{ t('pipelinq', 'Up to and including') }}</label>
				<input
					id="kk-filter-to"
					v-model="filters.to"
					type="date"
					:aria-label="t('pipelinq', 'Filter to date')">
			</div>
			<div class="filter-cell">
				<label for="kk-filter-register">{{ t('pipelinq', 'Register') }}</label>
				<input
					id="kk-filter-register"
					v-model="filters.registerNumber"
					type="text"
					:placeholder="t('pipelinq', 'e.g. REG-001')"
					:aria-label="t('pipelinq', 'Filter by register number')">
			</div>
			<div class="filter-cell">
				<label for="kk-filter-operator">{{ t('pipelinq', 'Operator') }}</label>
				<input
					id="kk-filter-operator"
					v-model="filters.operatorId"
					type="text"
					:placeholder="t('pipelinq', 'e.g. user_john')"
					:aria-label="t('pipelinq', 'Filter by operator')">
			</div>
			<div class="filter-cell">
				<label for="kk-filter-action">{{ t('pipelinq', 'Action') }}</label>
				<select
					id="kk-filter-action"
					v-model="filters.action"
					:aria-label="t('pipelinq', 'Filter by action')">
					<option value="">
						{{ t('pipelinq', 'All actions') }}
					</option>
					<option value="sale">
						{{ t('pipelinq', 'Sale') }}
					</option>
					<option value="void">
						{{ t('pipelinq', 'Cancellation') }}
					</option>
					<option value="refund">
						{{ t('pipelinq', 'Refund') }}
					</option>
					<option value="no-sale">
						{{ t('pipelinq', 'No sale') }}
					</option>
				</select>
			</div>
			<div class="filter-cell filter-cell--actions">
				<NcButton @click="applyFilters">
					{{ t('pipelinq', 'Apply filter') }}
				</NcButton>
				<NcButton @click="clearFilters">
					{{ t('pipelinq', 'Clear') }}
				</NcButton>
			</div>
		</div>

		<div v-if="loading" class="kassakoppeling-audit-list__loading">
			<NcLoadingIcon :size="32" :title="t('pipelinq', 'Load audit log')" />
		</div>

		<div v-else-if="entries.length === 0" class="kassakoppeling-audit-list__empty">
			<p>{{ t('pipelinq', 'No audit entries found for the selected filters.') }}</p>
		</div>

		<table v-else class="kassakoppeling-audit-list__table" data-testid="kassakoppeling-audit-table">
			<thead>
				<tr>
					<th>{{ t('pipelinq', 'Time') }}</th>
					<th>{{ t('pipelinq', 'Operator') }}</th>
					<th>{{ t('pipelinq', 'Register') }}</th>
					<th>{{ t('pipelinq', 'Action') }}</th>
					<th class="num">
						{{ t('pipelinq', 'Amount') }}
					</th>
					<th>{{ t('pipelinq', 'Verification') }}</th>
					<th class="chevron-col" />
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="entry in pageEntries"
					:key="entry.id || entry.uuid || entry.timestamp"
					class="kassakoppeling-audit-list__row"
					data-testid="kassakoppeling-audit-row"
					@click="openDetail(entry)">
					<td>{{ formatTimestamp(entry.timestamp) }}</td>
					<td>{{ entry.operatorId || '—' }}</td>
					<td>{{ entry.registerNumber || '—' }}</td>
					<td>
						<span :class="['action-badge', `action-badge--${actionClass(entry.action)}`]">
							{{ actionLabel(entry.action) }}
						</span>
					</td>
					<td class="num">
						{{ formatEur(entry.amount) }}
					</td>
					<td>
						<span :class="['verify-badge', `verify-badge--${verifyClass(entry.verified)}`]">
							{{ verifyLabel(entry.verified) }}
						</span>
					</td>
					<td class="chevron-col">
						<ChevronRight :size="20" class="kassakoppeling-audit-list__chevron" />
					</td>
				</tr>
			</tbody>
		</table>

		<div v-if="totalPages > 1" class="kassakoppeling-audit-list__pagination">
			<NcButton :disabled="page === 1" @click="page = Math.max(1, page - 1)">
				{{ t('pipelinq', 'Previous') }}
			</NcButton>
			<span class="page-info">
				{{ t('pipelinq', 'Page {current} of {total}', { current: page, total: totalPages }) }}
			</span>
			<NcButton :disabled="page === totalPages" @click="page = Math.min(totalPages, page + 1)">
				{{ t('pipelinq', 'Next') }}
			</NcButton>
		</div>

		<BelastingdienstExportDialog
			v-if="showExport"
			:submitting="exporting"
			@close="showExport = false"
			@confirm="downloadExport" />
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import Download from 'vue-material-design-icons/Download.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import BelastingdienstExportDialog from '../../dialogs/BelastingdienstExportDialog.vue'

const PAGE_SIZE = 25

const ACTION_LABELS = {
	sale: 'Sale',
	void: 'Void',
	refund: 'Refund',
	'no-sale': 'No sale',
}

const ACTION_CLASSES = {
	sale: 'sale',
	void: 'void',
	refund: 'refund',
	'no-sale': 'no-sale',
}

export default {
	name: 'KassakoppelingAuditList',
	components: {
		NcButton,
		NcLoadingIcon,
		Refresh,
		Download,
		ChevronRight,
		BelastingdienstExportDialog,
	},
	data() {
		return {
			entries: [],
			loading: false,
			showExport: false,
			exporting: false,
			page: 1,
			filters: {
				from: '',
				to: '',
				registerNumber: '',
				operatorId: '',
				action: '',
			},
		}
	},
	computed: {
		/**
		 * Whether the acting user is a Nextcloud admin (controls export button).
		 *
		 * @return {boolean} Whether the user is admin.
		 */
		isAdmin() {
			return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
		},
		/**
		 * Entries displayed in descending chronological order.
		 *
		 * @return {Array<object>} The entries reversed (newest first).
		 */
		sortedEntries() {
			const copy = this.entries.slice()
			copy.sort((left, right) => String(right.timestamp || '').localeCompare(String(left.timestamp || '')))
			return copy
		},
		/**
		 * Total number of pages at the configured page size.
		 *
		 * @return {number} The total page count.
		 */
		totalPages() {
			return Math.max(1, Math.ceil(this.sortedEntries.length / PAGE_SIZE))
		},
		/**
		 * Entries to show on the current page.
		 *
		 * @return {Array<object>} The page slice.
		 */
		pageEntries() {
			const start = (this.page - 1) * PAGE_SIZE
			return this.sortedEntries.slice(start, start + PAGE_SIZE)
		},
	},
	async mounted() {
		await this.refresh()
	},
	methods: {
		/**
		 * Load entries from the bespoke audit endpoint.
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
				const url = generateUrl(`/apps/pipelinq/api/kassakoppeling/audit?${params.toString()}`)
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
				this.page = 1
			} catch (e) {
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
		 * Clear all filters and reload.
		 */
		clearFilters() {
			this.filters = {
				from: '',
				to: '',
				registerNumber: '',
				operatorId: '',
				action: '',
			}
			this.refresh()
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
			} catch (e) {
				return value
			}
		},
		/**
		 * Format an integer amount in cents as a localised EUR string.
		 *
		 * @param {number|string} cents The amount in cents.
		 * @return {string} The formatted EUR value.
		 */
		formatEur(cents) {
			const value = Number.isFinite(Number(cents)) ? Number(cents) / 100 : 0
			try {
				return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(value)
			} catch (e) {
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
		 * Get the CSS modifier suffix for an action badge.
		 *
		 * @param {string} action The action enum value.
		 * @return {string} The css modifier suffix.
		 */
		actionClass(action) {
			return ACTION_CLASSES[action] || 'unknown'
		},
		/**
		 * Get the localised label for a verification flag.
		 *
		 * @param {boolean|null} verified The flag.
		 * @return {string} The localised label.
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
		 * Get the CSS modifier suffix for a verification badge.
		 *
		 * @param {boolean|null} verified The flag.
		 * @return {string} The css modifier suffix.
		 */
		verifyClass(verified) {
			if (verified === true) {
				return 'ok'
			}
			if (verified === false) {
				return 'fail'
			}
			return 'pending'
		},
		/**
		 * Download the Belastingdienst export pack and stream it to disk.
		 *
		 * @param {object} payload The selected from / to / format.
		 */
		async downloadExport(payload) {
			this.exporting = true
			try {
				const params = new URLSearchParams({
					from: payload.from,
					to: payload.to,
					format: payload.format,
				})
				const url = generateUrl(`/apps/pipelinq/api/kassakoppeling/audit/export?${params.toString()}`)
				const response = await fetch(url, {
					method: 'GET',
					headers: { requesttoken: OC.requestToken },
				})
				if (!response.ok) {
					if (response.status === 403) {
						showError(t('pipelinq', 'Only administrators may export to the Belastingdienst.'))
					} else {
						showError(t('pipelinq', 'Belastingdienst export failed.'))
					}
					return
				}
				const blob = await response.blob()
				const disposition = response.headers.get('Content-Disposition') || ''
				const matched = disposition.match(/filename="?([^";]+)"?/)
				const filename = matched ? matched[1] : `kassakoppeling-export-${payload.from}-to-${payload.to}.${payload.format}`
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
			} catch (e) {
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
	justify-content: space-between;
	align-items: flex-start;
	gap: 16px;
}

.kassakoppeling-audit-list__subtitle {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0 0;
}

.kassakoppeling-audit-list__actions {
	display: flex;
	gap: 8px;
}

.kassakoppeling-audit-list__filters {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px;
	padding: 12px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.filter-cell {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.filter-cell--actions {
	flex-direction: row;
	align-items: flex-end;
	gap: 8px;
}

.filter-cell label {
	font-size: 12px;
	font-weight: bold;
	color: var(--color-text-maxcontrast);
}

.filter-cell input,
.filter-cell select {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
}

.kassakoppeling-audit-list__loading,
.kassakoppeling-audit-list__empty {
	padding: 32px;
	text-align: center;
	color: var(--color-text-maxcontrast);
}

.kassakoppeling-audit-list__table {
	width: 100%;
	border-collapse: collapse;
}

.kassakoppeling-audit-list__table th {
	text-align: left;
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.kassakoppeling-audit-list__row,
.kassakoppeling-audit-list__row td {
	cursor: pointer;
}

.kassakoppeling-audit-list__row:hover {
	background: var(--color-background-hover);
}

.kassakoppeling-audit-list__table td {
	padding: 8px 12px;
	border-bottom: 1px solid var(--color-border);
}

.num {
	text-align: right;
}

.kassakoppeling-audit-list__table .chevron-col {
	width: 1%;
	padding-inline: 4px;
}

.kassakoppeling-audit-list__chevron {
	display: block;
	color: var(--color-text-maxcontrast);
}

.action-badge,
.verify-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: 999px;
	font-size: 12px;
	font-weight: 500;
}

.action-badge--sale {
	background: var(--color-success);
	color: var(--color-success-text);
}

.action-badge--void {
	background: var(--color-error);
	color: var(--color-error-text);
}

.action-badge--refund {
	background: var(--color-warning);
	color: var(--color-warning-text);
}

.action-badge--no-sale,
.action-badge--unknown {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.verify-badge--ok {
	background: var(--color-success);
	color: var(--color-success-text);
}

.verify-badge--fail {
	background: var(--color-error);
	color: var(--color-error-text);
}

.verify-badge--pending {
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
}

.kassakoppeling-audit-list__pagination {
	display: flex;
	justify-content: center;
	align-items: center;
	gap: 12px;
	padding: 8px;
}

.page-info {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}
</style>

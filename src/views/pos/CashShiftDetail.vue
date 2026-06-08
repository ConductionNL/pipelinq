<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<CnDetailPage
		:title="shift.reference || t('pipelinq', 'Shift')"
		:subtitle="t('pipelinq', 'Kassalade')"
		:back-route="{ name: 'CashShifts' }"
		:back-label="t('pipelinq', 'Terug naar lijst')"
		:loading="loading"
		:sidebar="!loading"
		object-type="pipelinq_cashShift"
		:object-id="shiftId"
		:sidebar-props="sidebarProps">
		<template #actions>
			<NcButton v-if="canDrop"
				:disabled="busy"
				@click="showDrop = true">
				{{ t('pipelinq', 'Geld verwijderen') }}
			</NcButton>
			<NcButton v-if="canCount"
				type="primary"
				:disabled="busy"
				@click="showCount = true">
				{{ t('pipelinq', 'Shift afsluiten en tellen') }}
			</NcButton>
		</template>

		<!-- 1. Float declaration (read-only). -->
		<CnDetailCard :title="t('pipelinq', 'Openingsfloat')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Openingsbedrag') }}</label>
					<span>{{ formatEur(shift.floatAmount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Geopend op') }}</label>
					<span>{{ formatDate(shift.floatAt) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Kassamedewerker') }}</label>
					<span>{{ shift.operator || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Beheerd door') }}</label>
					<span>{{ shift.managedBy || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Lade') }}</label>
					<span>{{ shift.drawer || '-' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge :status="status" :label="statusLabel" />
				</div>
				<div v-if="shift.closedAt" class="info-field">
					<label>{{ t('pipelinq', 'Afgesloten op') }}</label>
					<span>{{ formatDate(shift.closedAt) }}</span>
				</div>
			</div>
		</CnDetailCard>

		<!-- 2. Drops panel. -->
		<CnDetailCard :title="t('pipelinq', 'Drops')">
			<table class="cash-shift-detail__table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Reden') }}</th>
						<th class="num">
							{{ t('pipelinq', 'Bedrag') }}
						</th>
						<th>{{ t('pipelinq', 'Op') }}</th>
						<th>{{ t('pipelinq', 'Door') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="drop in drops" :key="drop.id">
						<td>{{ reasonLabel(drop.reason) }}</td>
						<td class="num">
							{{ formatEur(drop.amount) }}
						</td>
						<td>{{ formatDate(drop.droppedAt) }}</td>
						<td>{{ drop.droppedBy || '-' }}</td>
					</tr>
					<tr v-if="drops.length === 0">
						<td colspan="4" class="empty">
							{{ t('pipelinq', 'Nog geen drops vastgelegd.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>

		<!-- 4. Variance / diff panel (visible once closed). -->
		<CnDetailCard v-if="diff" :title="t('pipelinq', 'Kasverschil')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Verwacht bedrag') }}</label>
					<span>{{ formatEur(diff.expectedAmount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Geteld bedrag') }}</label>
					<span>{{ formatEur(diff.actualAmount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Verschil') }}</label>
					<span>{{ formatEur(diff.diffAmount) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Percentage') }}</label>
					<span>{{ percentageLabel }}</span>
				</div>
				<div class="info-field info-field--wide">
					<span :class="['cash-shift-detail__tolerance', toleranceClass]">
						{{ toleranceLabel }}
					</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Reconciliatie') }}</label>
					<CnStatusBadge :status="diff.status" :label="diffStatusLabel" />
				</div>
				<div v-if="diff.approvedBy" class="info-field">
					<label>{{ t('pipelinq', 'Beoordeeld door') }}</label>
					<span>{{ diff.approvedBy }}</span>
				</div>
				<div v-if="diff.rejectionReason" class="info-field info-field--wide">
					<label>{{ t('pipelinq', 'Reden afwijzing') }}</label>
					<span>{{ diff.rejectionReason }}</span>
				</div>
			</div>
			<div v-if="canReconcile" class="cash-shift-detail__actions">
				<NcButton type="primary" :disabled="busy" @click="approve">
					{{ t('pipelinq', 'Goedkeuren') }}
				</NcButton>
				<NcButton type="error" :disabled="busy" @click="showReject = true">
					{{ t('pipelinq', 'Afwijzen') }}
				</NcButton>
			</div>
		</CnDetailCard>

		<!-- 5. Notes. -->
		<CnDetailCard v-if="shift.notes" :title="t('pipelinq', 'Notities')">
			<p>{{ shift.notes }}</p>
		</CnDetailCard>

		<CashShiftDropDialog
			v-if="showDrop"
			:submitting="busy"
			@close="showDrop = false"
			@confirm="recordDrop" />

		<CashShiftCountDialog
			v-if="showCount"
			:submitting="busy"
			@close="showCount = false"
			@confirm="recordCount" />

		<CashShiftRejectDialog
			v-if="showReject"
			:submitting="busy"
			@close="showReject = false"
			@confirm="reject" />
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'
import CashShiftDropDialog from '../../modals/CashShiftDropDialog.vue'
import CashShiftCountDialog from '../../modals/CashShiftCountDialog.vue'
import CashShiftRejectDialog from '../../modals/CashShiftRejectDialog.vue'

const STATUS_LABELS = {
	open: 'Open',
	closed: 'Afgesloten',
	reconciled: 'Gereconcilieerd',
}

const DIFF_STATUS_LABELS = {
	pending: 'In behandeling',
	approved: 'Goedgekeurd',
	rejected: 'Afgewezen',
}

const DROP_REASON_LABELS = {
	'manager-deposit': 'Afstorting bij manager',
	'bank-run': 'Bankrit',
	'security-removal': 'Veiligheidsafvoer',
	other: 'Overig',
}

export default {
	name: 'CashShiftDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		CashShiftDropDialog,
		CashShiftCountDialog,
		CashShiftRejectDialog,
	},
	props: {
		cashShiftId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			shift: {},
			drops: [],
			diff: null,
			loading: false,
			busy: false,
			showDrop: false,
			showCount: false,
			showReject: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The shift id from the prop or route.
		 *
		 * @return {string|null} The id.
		 */
		shiftId() {
			return this.cashShiftId || this.$route.params.id || null
		},
		/**
		 * The current shift status.
		 *
		 * @return {string} The status.
		 */
		status() {
			return this.shift.status || 'open'
		},
		/**
		 * Translated shift status label.
		 *
		 * @return {string} The label.
		 */
		statusLabel() {
			return t('pipelinq', STATUS_LABELS[this.status] || this.status)
		},
		/**
		 * Translated diff status label.
		 *
		 * @return {string} The label.
		 */
		diffStatusLabel() {
			const key = this.diff?.status || 'pending'
			return t('pipelinq', DIFF_STATUS_LABELS[key] || key)
		},
		/**
		 * Whether the current user is treated as a manager in the UI. Server-side
		 * authorization is authoritative; this only hides the buttons for clearly
		 * non-privileged users. NC admins are always managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
		},
		canDrop() {
			return this.status === 'open'
		},
		canCount() {
			return this.status === 'open'
		},
		canReconcile() {
			return this.diff?.status === 'pending' && this.status === 'closed' && this.isManager
		},
		/**
		 * Human label for the diff percentage (N/A when undefined).
		 *
		 * @return {string} The percentage label.
		 */
		percentageLabel() {
			if (this.diff?.diffPercentage === null || this.diff?.diffPercentage === undefined) {
				return t('pipelinq', 'N/A (verwacht bedrag is €0)')
			}
			return `${this.diff.diffPercentage}%`
		},
		toleranceClass() {
			return this.diff?.withinTolerance
				? 'cash-shift-detail__tolerance--ok'
				: 'cash-shift-detail__tolerance--warn'
		},
		toleranceLabel() {
			return this.diff?.withinTolerance
				? t('pipelinq', 'Binnen tolerantie')
				: t('pipelinq', 'Buiten tolerantie')
		},
		/**
		 * Sidebar props (files / notes / audit trail).
		 *
		 * @return {object} The props.
		 */
		sidebarProps() {
			const config = this.objectStore.objectTypeRegistry?.cashShift || {}
			return {
				title: t('pipelinq', 'Kassashift'),
				register: config.register || '',
				schema: config.schema || '',
			}
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		formatEur,
		/**
		 * Format an ISO timestamp for display.
		 *
		 * @param {string} value The ISO date string.
		 * @return {string} The formatted date.
		 */
		formatDate(value) {
			if (!value) {
				return '-'
			}
			return new Date(value).toLocaleString('nl-NL')
		},
		/**
		 * Resolve a drop reason code to its label.
		 *
		 * @param {string} code The reason code.
		 * @return {string} The label.
		 */
		reasonLabel(code) {
			return DROP_REASON_LABELS[code] ? t('pipelinq', DROP_REASON_LABELS[code]) : (code || '-')
		},
		/**
		 * Load the shift, its drops and the most recent variance.
		 */
		async load() {
			this.loading = true
			try {
				this.shift = await this.objectStore.fetchObject('cashShift', this.shiftId) || {}

				await this.objectStore.fetchCollection('cashDrop', { shift: this.shiftId, _limit: 500 })
				this.drops = (this.objectStore.getCollection('cashDrop')?.results || [])
					.filter(d => d.shift === this.shiftId)

				await this.objectStore.fetchCollection('cashDiff', { shift: this.shiftId, _limit: 100 })
				const diffs = (this.objectStore.getCollection('cashDiff')?.results || [])
					.filter(d => d.shift === this.shiftId)
				this.diff = this.latestDiff(diffs)
			} finally {
				this.loading = false
			}
		},
		/**
		 * Pick the diff to display: prefer the pending one, else the most recent.
		 *
		 * @param {Array<object>} diffs The candidate diffs.
		 * @return {object|null} The diff to show.
		 */
		latestDiff(diffs) {
			if (diffs.length === 0) {
				return null
			}
			const pending = diffs.find(d => d.status === 'pending')
			if (pending) {
				return pending
			}
			return diffs[diffs.length - 1]
		},
		/**
		 * POST to a shift lifecycle endpoint and reload on success.
		 *
		 * @param {string} path The path under /api/pos-shifts/{id}.
		 * @param {object} body The request body.
		 * @param {string} successMessage The success toast.
		 * @return {boolean} Whether the action succeeded.
		 */
		async lifecycle(path, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-shifts/${this.shiftId}/${path}`),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify(body || {}),
					},
				)
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					showError(data.error || t('pipelinq', 'Actie mislukt.'))
					return false
				}
				showSuccess(successMessage)
				await this.load()
				return true
			} catch (e) {
				showError(t('pipelinq', 'Actie mislukt.'))
				return false
			} finally {
				this.busy = false
			}
		},
		/**
		 * Record a mid-shift drop.
		 *
		 * @param {object} payload The drop payload (amount, reason).
		 */
		async recordDrop(payload) {
			const ok = await this.lifecycle('drop', payload, t('pipelinq', 'Drop vastgelegd.'))
			if (ok) {
				this.showDrop = false
			}
		},
		/**
		 * Close the shift and record a blind count.
		 *
		 * @param {object} payload The count payload (amount, notes).
		 */
		async recordCount(payload) {
			const ok = await this.lifecycle('count', payload, t('pipelinq', 'Telling vastgelegd.'))
			if (ok) {
				this.showCount = false
			}
		},
		/**
		 * Approve the pending variance (manager only).
		 */
		approve() {
			this.lifecycle('diff/approve', { diffId: this.diff?.id }, t('pipelinq', 'Kasverschil goedgekeurd.'))
		},
		/**
		 * Reject the pending variance with a reason (manager only).
		 *
		 * @param {string} reason The rejection reason.
		 */
		async reject(reason) {
			const ok = await this.lifecycle('diff/reject', { diffId: this.diff?.id, reason }, t('pipelinq', 'Kasverschil afgewezen.'))
			if (ok) {
				this.showReject = false
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
}

.info-field--wide {
	grid-column: 1 / -1;
}

.info-field label {
	display: block;
	font-weight: bold;
	margin-bottom: 2px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.cash-shift-detail__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.cash-shift-detail__table th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.cash-shift-detail__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.cash-shift-detail__tolerance {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-weight: bold;
}

.cash-shift-detail__tolerance--ok {
	background-color: var(--color-success);
	color: var(--color-primary-text);
}

.cash-shift-detail__tolerance--warn {
	background-color: var(--color-warning);
	color: var(--color-primary-text);
}

.cash-shift-detail__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}

.num {
	text-align: right;
}

.empty {
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}
</style>

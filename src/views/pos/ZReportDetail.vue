<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Per-Z-report detail view: summary card, tax breakdown, payment-method
  - breakdown and the shillinq bookkeeping-status projection with the
  - manager-gated "Re-raise journal entry" action. The GL journal itself is
  - owned by shillinq (pipelinq-bookkeeping-to-shillinq); pipelinq only mirrors
  - the bookkeepingStatus + shillinqJournalEntryId outcome here.
  -
  - @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
  -->
<template>
	<CnDetailPage
		:title="zReport.reference || t('pipelinq', 'Z-report')"
		:subtitle="t('pipelinq', 'POS end-of-day')"
		:back-route="{ name: 'ZReports' }"
		:back-label="t('pipelinq', 'Terug naar lijst')"
		:loading="loading"
		:sidebar="{ enabled: !loading }"
		object-type="pipelinq_posZReport"
		:object-id="zReportId">
		<template #actions>
			<NcButton
				v-if="canRetry"
				type="primary"
				:disabled="busy"
				data-testid="pos-eod-retry"
				@click="confirmAndRetry">
				{{ t('pipelinq', 'Opnieuw raisen bij shillinq') }}
			</NcButton>
		</template>

		<CnDetailCard :title="t('pipelinq', 'Samenvatting')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Datum') }}</label>
					<span>{{ zReport.reportDate || '—' }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Terminal') }}</label>
					<span>{{ zReport.terminalId || t('pipelinq', 'Alle terminals') }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Aantal transacties') }}</label>
					<span>{{ zReport.transactionCount || 0 }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Status') }}</label>
					<CnStatusBadge :value="zReport.status || 'draft'" :label="statusLabel" />
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Subtotaal') }}</label>
					<span>{{ formatEur(zReport.subtotal) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'BTW totaal') }}</label>
					<span>{{ formatEur(zReport.totalTax) }}</span>
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Totaal') }}</label>
					<strong>{{ formatEur(zReport.total) }}</strong>
				</div>
			</div>
			<p v-if="zReport.notes" class="z-report__notes">
				{{ zReport.notes }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Boekhoudkundige status')">
			<div class="info-grid">
				<div class="info-field">
					<label>{{ t('pipelinq', 'Inboekstatus shillinq') }}</label>
					<CnStatusBadge
						data-testid="pos-eod-bookkeeping-status"
						:value="zReport.bookkeepingStatus || 'pending'"
						:label="bookkeepingStatusLabel" />
				</div>
				<div class="info-field">
					<label>{{ t('pipelinq', 'Shillinq journaalpost-id') }}</label>
					<code v-if="zReport.shillinqJournalEntryId" data-testid="pos-eod-journal-id">{{ zReport.shillinqJournalEntryId }}</code>
					<span v-else>—</span>
				</div>
			</div>
			<p class="z-report__hint">
				{{ t('pipelinq', 'Het grootboek, de BTW-boeking en de journaalpost worden beheerd in shillinq. Pipelinq raise alleen de bedrijfsfeiten van deze POS-dag via de integratie-registry.') }}
			</p>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'BTW uitsplitsing')">
			<table class="z-report__table" data-testid="z-report-tax-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Tarief') }}</th>
						<th>{{ t('pipelinq', 'Basis') }}</th>
						<th>{{ t('pipelinq', 'BTW') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in (zReport.taxBreakdown || [])" :key="row.rate">
						<td>{{ row.rate }}%</td>
						<td>{{ formatEur(row.base) }}</td>
						<td>{{ formatEur(row.tax) }}</td>
					</tr>
					<tr v-if="!(zReport.taxBreakdown || []).length">
						<td colspan="3">
							{{ t('pipelinq', 'Geen BTW uitsplitsing — leeg report.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>

		<CnDetailCard :title="t('pipelinq', 'Betaalmethoden')">
			<table class="z-report__table" data-testid="z-report-payment-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Methode') }}</th>
						<th>{{ t('pipelinq', 'Bedrag') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in (zReport.paymentMethodBreakdown || [])" :key="row.method">
						<td>{{ row.method }}</td>
						<td>{{ formatEur(row.amount) }}</td>
					</tr>
					<tr v-if="!(zReport.paymentMethodBreakdown || []).length">
						<td colspan="2">
							{{ t('pipelinq', 'Geen betaalmethode data.') }}
						</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'
import { raiseJournalEntry } from '../../services/posBookkeepingApi.js'

const STATUS_LABELS = {
	draft: 'Concept',
	ready: 'Klaar voor inboeking',
	submitted: 'Ingediend',
	posted: 'Geboekt',
	failed: 'Gefaald',
	reconciled: 'Gereconcilieerd',
}

const BOOKKEEPING_STATUS_LABELS = {
	pending: 'In wachtrij',
	raised: 'Geraised in shillinq',
	failed: 'Raise gefaald',
}

export default {
	name: 'ZReportDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
	},
	props: {
		posZReportId: {
			type: String,
			default: null,
		},
	},
	data() {
		return {
			zReport: {},
			loading: false,
			busy: false,
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		/**
		 * The Z-report id from the prop or the route.
		 *
		 * @return {string|null} The id.
		 */
		zReportId() {
			return this.posZReportId || this.$route.params.id || null
		},
		/**
		 * Translated lifecycle status label.
		 *
		 * @return {string} The label.
		 */
		statusLabel() {
			const key = this.zReport.status || 'draft'
			return t('pipelinq', STATUS_LABELS[key] || key)
		},
		/**
		 * Translated shillinq bookkeeping-status label.
		 *
		 * @return {string} The label.
		 */
		bookkeepingStatusLabel() {
			const key = this.zReport.bookkeepingStatus || 'pending'
			return t('pipelinq', BOOKKEEPING_STATUS_LABELS[key] || key)
		},
		/**
		 * Whether the manager-gated re-raise button is shown. The server-side
		 * gate is authoritative; this only hides the button for non-managers and
		 * for already-raised days.
		 *
		 * @return {boolean} Whether re-raise is allowed.
		 */
		canRetry() {
			const status = this.zReport.bookkeepingStatus || 'pending'
			const isCandidate = ['pending', 'failed'].includes(status)
			const hasTakings = Number(this.zReport.transactionCount || 0) > 0
			const isManager = typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
			return isCandidate && hasTakings && isManager
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		formatEur,
		/**
		 * Load the Z-report.
		 */
		async load() {
			this.loading = true
			try {
				this.zReport = await this.objectStore.fetchObject('posZReport', this.zReportId) || {}
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Z-report niet kunnen laden.'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Confirm + trigger a manager-gated re-raise of the shillinq journal entry.
		 */
		async confirmAndRetry() {
			if (!this.zReportId) {
				return
			}
			if (!window.confirm(t('pipelinq', 'Journaalpost opnieuw raisen bij shillinq? Dit gebruikt dezelfde idempotency key, dus shillinq voorkomt dubbele boekingen.'))) {
				return
			}

			this.busy = true
			try {
				const updated = await raiseJournalEntry(this.zReportId)
				if (updated) {
					this.zReport = updated
				}
				showSuccess(t('pipelinq', 'Journaalpost geraised bij shillinq.'))
				await this.load()
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Raise mislukt.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.info-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px 24px;
}

.info-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.info-field label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.z-report__notes {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
}

.z-report__hint {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.z-report__table {
	width: 100%;
	border-collapse: collapse;
}

.z-report__table th,
.z-report__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.z-report__table th {
	font-weight: 600;
}
</style>

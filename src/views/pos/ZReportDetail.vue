<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Per-Z-report detail view: summary card, tax breakdown, payment-method
  - breakdown, GL line items (read-only), submission timeline and the
  - manager-gated "Retry Submission" action.
  -
  - @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.2
  -->
<template>
	<CnDetailPage
		:title="zReport.reference || t('pipelinq', 'Z-report')"
		:subtitle="t('pipelinq', 'Boekhoudkundige Afhandeling')"
		:back-route="{ name: 'ZReports' }"
		:back-label="t('pipelinq', 'Terug naar lijst')"
		:loading="loading"
		:sidebar="!loading"
		object-type="pipelinq_posZReport"
		:object-id="zReportId">
		<template #actions>
			<NcButton
				v-if="canRetry"
				type="primary"
				:disabled="busy"
				data-testid="pos-eod-retry"
				@click="confirmAndRetry">
				{{ t('pipelinq', 'Opnieuw indienen bij Shillinq') }}
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
						<td colspan="3">{{ t('pipelinq', 'Geen BTW uitsplitsing — leeg report.') }}</td>
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
						<td colspan="2">{{ t('pipelinq', 'Geen betaalmethode data.') }}</td>
					</tr>
				</tbody>
			</table>
		</CnDetailCard>

		<CnDetailCard v-if="outbound" :title="t('pipelinq', 'GL grootboek regels')">
			<table class="z-report__table" data-testid="z-report-ledger-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Rekening') }}</th>
						<th>{{ t('pipelinq', 'Debet') }}</th>
						<th>{{ t('pipelinq', 'Credit') }}</th>
						<th>{{ t('pipelinq', 'Omschrijving') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(line, idx) in (outbound.ledgerLineItems || [])" :key="idx">
						<td><code>{{ line.account }}</code></td>
						<td>{{ formatEur(line.debit) }}</td>
						<td>{{ formatEur(line.credit) }}</td>
						<td>{{ line.description }}</td>
					</tr>
				</tbody>
			</table>
			<p class="z-report__idem">
				{{ t('pipelinq', 'Idempotency key:') }}
				<code data-testid="pos-eod-idempotency-key">{{ outbound.idempotencyKey }}</code>
			</p>
		</CnDetailCard>

		<CnDetailCard v-if="outbound" :title="t('pipelinq', 'Submission geschiedenis')">
			<SubmissionTimeline
				:attempts="outbound.submissionAttempts || []"
				:next-retry-at="outbound.nextRetryAt || ''" />
		</CnDetailCard>
	</CnDetailPage>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnDetailPage, CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'
import { postJournalEntry } from '../../services/posBookkeepingApi.js'
import SubmissionTimeline from '../../components/SubmissionTimeline.vue'

const STATUS_LABELS = {
	draft: 'Concept',
	ready: 'Klaar voor inboeking',
	submitted: 'Ingediend',
	posted: 'Geboekt',
	failed: 'Gefaald',
	reconciled: 'Gereconcilieerd',
}

export default {
	name: 'ZReportDetail',
	components: {
		NcButton,
		CnDetailPage,
		CnDetailCard,
		CnStatusBadge,
		SubmissionTimeline,
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
			outbound: null,
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
		 * Translated status label.
		 *
		 * @return {string} The label.
		 */
		statusLabel() {
			const key = this.zReport.status || 'draft'
			return t('pipelinq', STATUS_LABELS[key] || key)
		},
		/**
		 * Whether the manager-gated retry button is shown. Server-side gate is
		 * authoritative; this only hides the button for non-admin users.
		 *
		 * @return {boolean} Whether retry is allowed.
		 */
		canRetry() {
			if (!this.outbound) {
				return false
			}
			const status = this.outbound.status || 'draft'
			const isCandidate = ['draft', 'failed', 'pending'].includes(status)
			const isManager = typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
			return isCandidate && isManager
		},
	},
	async mounted() {
		await this.load()
	},
	methods: {
		formatEur,
		/**
		 * Load the Z-report + linked outbound message (if any).
		 */
		async load() {
			this.loading = true
			try {
				this.zReport = await this.objectStore.fetchObject('posZReport', this.zReportId) || {}
				const outbounds = await this.objectStore.fetchObjects('posJournalEntryOutbound', {
					filters: { zReport: this.zReportId },
				}) || []
				this.outbound = Array.isArray(outbounds) && outbounds.length ? outbounds[0] : null
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Z-report niet kunnen laden.'))
			} finally {
				this.loading = false
			}
		},
		/**
		 * Confirm + trigger a manager-gated retry submission.
		 */
		async confirmAndRetry() {
			if (!this.outbound?.id) {
				return
			}
			if (!window.confirm(t('pipelinq', 'Opnieuw indienen bij Shillinq? Dit triggert een POST met dezelfde idempotency key.'))) {
				return
			}

			this.busy = true
			try {
				const updated = await postJournalEntry(this.outbound.id)
				if (updated) {
					this.outbound = updated
				}
				showSuccess(t('pipelinq', 'Submission gestart.'))
				await this.load()
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Submission mislukt.'))
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

.z-report__idem {
	margin-top: 8px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}
</style>

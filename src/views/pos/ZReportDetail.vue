<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->

<!--
  Z-report detail view showing summary, tax breakdown, payment method breakdown,
  GL account mapping table, submission timeline, and action buttons.

  Displays a "Retry Submission" button for failed reports (accounting role required)
  and a "Submit to Shillinq" button for ready/draft reports with journal entries.

  @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.2
-->
<template>
	<CnDetailPage
		:title="zReport.reference || t('pipelinq', 'Z-Rapport')"
		:subtitle="t('pipelinq', 'Boekhoudkundige Afhandeling')"
		:back-route="{ name: 'ZReports' }"
		:back-label="t('pipelinq', 'Terug naar lijst')"
		:loading="loading">
		<!-- Action buttons -->
		<template #actions>
			<NcButton
				v-if="canSubmit"
				type="primary"
				:disabled="submitting"
				@click="submitToShillinq">
				{{ t('pipelinq', 'Indienen bij Shillinq') }}
			</NcButton>
			<NcButton
				v-if="canRetry"
				type="warning"
				:disabled="submitting"
				@click="confirmRetry">
				{{ t('pipelinq', 'Herpoging indienen') }}
			</NcButton>
			<NcButton
				type="secondary"
				@click="viewTransactions">
				{{ t('pipelinq', 'Bekijk transacties') }}
			</NcButton>
		</template>

		<!-- Summary card -->
		<div class="z-report-detail">
			<section class="z-report-section">
				<h2>{{ t('pipelinq', 'Samenvatting') }}</h2>
				<dl class="z-report-summary">
					<div class="z-report-summary__item">
						<dt>{{ t('pipelinq', 'Referentie') }}</dt>
						<dd>{{ zReport.reference || '—' }}</dd>
					</div>
					<div class="z-report-summary__item">
						<dt>{{ t('pipelinq', 'Rapportdatum') }}</dt>
						<dd>{{ zReport.reportDate || '—' }}</dd>
					</div>
					<div class="z-report-summary__item">
						<dt>{{ t('pipelinq', 'Terminal') }}</dt>
						<dd>{{ zReport.terminalId || t('pipelinq', 'Alle terminals') }}</dd>
					</div>
					<div class="z-report-summary__item">
						<dt>{{ t('pipelinq', 'Aantal transacties') }}</dt>
						<dd>{{ zReport.transactionCount ?? 0 }}</dd>
					</div>
					<div class="z-report-summary__item">
						<dt>{{ t('pipelinq', 'Totaal') }}</dt>
						<dd>{{ formatCurrency(zReport.total) }}</dd>
					</div>
					<div class="z-report-summary__item">
						<dt>{{ t('pipelinq', 'Status') }}</dt>
						<dd>
							<span :class="['z-report-status-badge', statusClass(zReport.status)]">
								{{ statusLabel(zReport.status) }}
							</span>
						</dd>
					</div>
				</dl>
			</section>

			<!-- BTW breakdown -->
			<section v-if="taxBreakdown.length" class="z-report-section">
				<h2>{{ t('pipelinq', 'BTW-uitsplitsing') }}</h2>
				<table class="z-report-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Tarief') }}</th>
							<th>{{ t('pipelinq', 'Grondslag') }}</th>
							<th>{{ t('pipelinq', 'BTW') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(entry, index) in taxBreakdown" :key="index">
							<td>{{ entry.rate }}%</td>
							<td>{{ formatCurrency(entry.base) }}</td>
							<td>{{ formatCurrency(entry.tax) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Payment method breakdown -->
			<section v-if="paymentMethodBreakdown.length" class="z-report-section">
				<h2>{{ t('pipelinq', 'Betaalmethoden') }}</h2>
				<table class="z-report-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Methode') }}</th>
							<th>{{ t('pipelinq', 'Bedrag') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(entry, index) in paymentMethodBreakdown" :key="index">
							<td>{{ paymentMethodLabel(entry.method) }}</td>
							<td>{{ formatCurrency(entry.amount) }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- GL ledger line items (read-only) -->
			<section v-if="outboundMessage && ledgerLineItems.length" class="z-report-section">
				<h2>{{ t('pipelinq', 'GB-rekeningkoppeling') }}</h2>
				<table class="z-report-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Rekening') }}</th>
							<th>{{ t('pipelinq', 'Debet') }}</th>
							<th>{{ t('pipelinq', 'Credit') }}</th>
							<th>{{ t('pipelinq', 'Omschrijving') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="(line, index) in ledgerLineItems" :key="index">
							<td><code>{{ line.account }}</code></td>
							<td>{{ line.debit > 0 ? formatCurrency(line.debit) : '—' }}</td>
							<td>{{ line.credit > 0 ? formatCurrency(line.credit) : '—' }}</td>
							<td>{{ line.description }}</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Submission timeline -->
			<section class="z-report-section">
				<SubmissionTimeline
					:attempts="outboundAttempts"
					:next-retry-at="outboundNextRetryAt" />
			</section>
		</div>

		<!-- Retry confirmation dialog -->
		<NcDialog
			v-if="showRetryDialog"
			:name="t('pipelinq', 'Herpoging bevestigen')"
			@closing="showRetryDialog = false">
			<template #default>
				<p>{{ t('pipelinq', 'Weet u zeker dat u de Shillinq-boeking opnieuw wilt indienen?') }}</p>
				<p v-if="outboundMessage">
					{{ t('pipelinq', 'Poging') }} {{ (outboundMessage.attemptCount ?? 0) + 1 }}
					{{ t('pipelinq', 'van') }} {{ maxAttempts }}
				</p>
			</template>
			<template #actions>
				<NcButton type="secondary" @click="showRetryDialog = false">
					{{ t('pipelinq', 'Annuleren') }}
				</NcButton>
				<NcButton type="error" :disabled="submitting" @click="doRetry">
					{{ t('pipelinq', 'Bevestig herpoging') }}
				</NcButton>
			</template>
		</NcDialog>
	</CnDetailPage>
</template>

<script>
/**
 * @spec openspec/changes/pos-end-of-day-bookkeeping-post/tasks.md#5.2
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showSuccess, showError } from '@nextcloud/dialogs'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { CnDetailPage } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import SubmissionTimeline from '../../components/SubmissionTimeline.vue'

export default {
	name: 'ZReportDetail',

	components: {
		CnDetailPage,
		NcButton,
		NcDialog,
		SubmissionTimeline,
	},

	props: {
		/** @type {string} Z-report UUID from route params. */
		id: {
			type: String,
			required: true,
		},
	},

	setup() {
		const objectStore = useObjectStore()
		return { objectStore }
	},

	data() {
		return {
			/** @type {object} The posZReport object. */
			zReport: {},
			/** @type {object|null} The associated posJournalEntryOutbound. */
			outboundMessage: null,
			/** @type {boolean} Loading state. */
			loading: true,
			/** @type {boolean} Submission in progress. */
			submitting: false,
			/** @type {boolean} Whether to show the retry confirmation dialog. */
			showRetryDialog: false,
			/** @type {number} Maximum allowed submission attempts. */
			maxAttempts: 5,
		}
	},

	computed: {
		/** @return {Array} Tax breakdown entries. */
		taxBreakdown() {
			return Array.isArray(this.zReport.taxBreakdown) ? this.zReport.taxBreakdown : []
		},

		/** @return {Array} Payment method breakdown entries. */
		paymentMethodBreakdown() {
			return Array.isArray(this.zReport.paymentMethodBreakdown) ? this.zReport.paymentMethodBreakdown : []
		},

		/** @return {Array} Ledger line items from the outbound message. */
		ledgerLineItems() {
			return Array.isArray(this.outboundMessage?.ledgerLineItems) ? this.outboundMessage.ledgerLineItems : []
		},

		/** @return {Array} Submission attempts from the outbound message. */
		outboundAttempts() {
			return Array.isArray(this.outboundMessage?.submissionAttempts) ? this.outboundMessage.submissionAttempts : []
		},

		/** @return {string|null} Next retry timestamp. */
		outboundNextRetryAt() {
			return this.outboundMessage?.nextRetryAt ?? null
		},

		/** @return {boolean} Whether submit button should be shown. */
		canSubmit() {
			const outboundStatus = this.outboundMessage?.status
			return this.zReport.status === 'ready' && outboundStatus === 'draft'
		},

		/** @return {boolean} Whether retry button should be shown (requires failed + accounting role). */
		canRetry() {
			return this.outboundMessage?.status === 'failed' && !this.submitting
		},
	},

	async created() {
		await this.loadData()
	},

	methods: {
		/**
		 * Load the Z-report and its associated outbound message.
		 */
		async loadData() {
			this.loading = true
			try {
				const store = this.objectStore
				this.zReport = await store.fetchObject('pipelinq', 'posZReport', this.id)

				// Try to find the associated outbound message.
				const outbounds = await store.fetchObjects('pipelinq', 'posJournalEntryOutbound', {
					filters: [{ field: 'zReport', operator: 'eq', value: this.id }],
					limit: 1,
				})
				this.outboundMessage = outbounds?.[0] ?? null
			} catch (e) {
				showError(this.t('pipelinq', 'Fout bij laden van Z-rapport'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Trigger Shillinq submission for the associated outbound message.
		 */
		async submitToShillinq() {
			if (!this.outboundMessage?.id) {
				showError(this.t('pipelinq', 'Geen uitstuurrecord gevonden voor dit Z-rapport'))
				return
			}
			await this.doPost(this.outboundMessage.id)
		},

		/**
		 * Show the retry confirmation dialog.
		 */
		confirmRetry() {
			this.showRetryDialog = true
		},

		/**
		 * Execute the retry after confirmation.
		 */
		async doRetry() {
			this.showRetryDialog = false
			if (!this.outboundMessage?.id) return
			await this.doPost(this.outboundMessage.id)
		},

		/**
		 * POST to the bookkeeping submission endpoint.
		 *
		 * @param {string} outboundMessageId The outbound message UUID.
		 */
		async doPost(outboundMessageId) {
			this.submitting = true
			try {
				await axios.post(
					generateUrl('/apps/pipelinq/api/pos-bookkeeping/post'),
					{ outboundMessageId }
				)
				showSuccess(this.t('pipelinq', 'Boeking ingediend bij Shillinq'))
				await this.loadData()
			} catch (e) {
				const msg = e.response?.data?.error ?? this.t('pipelinq', 'Indienen mislukt')
				showError(msg)
			} finally {
				this.submitting = false
			}
		},

		/**
		 * Navigate to the POS transactions list filtered by this Z-report's transaction IDs.
		 */
		viewTransactions() {
			this.$router.push({ name: 'PosTransactions' })
		},

		/**
		 * Format a monetary amount as EUR.
		 *
		 * @param {number} amount
		 * @return {string}
		 */
		formatCurrency(amount) {
			return new Intl.NumberFormat('nl-NL', { style: 'currency', currency: 'EUR' }).format(amount ?? 0)
		},

		/**
		 * Return a CSS class for the status badge.
		 *
		 * @param {string} status
		 * @return {string}
		 */
		statusClass(status) {
			const map = {
				draft: 'status--draft',
				ready: 'status--ready',
				submitted: 'status--submitted',
				posted: 'status--posted',
				failed: 'status--failed',
				reconciled: 'status--reconciled',
			}
			return map[status] ?? ''
		},

		/**
		 * Return a human-readable Dutch label for a status value.
		 *
		 * @param {string} status
		 * @return {string}
		 */
		statusLabel(status) {
			const labels = {
				draft: this.t('pipelinq', 'Concept'),
				ready: this.t('pipelinq', 'Gereed'),
				submitted: this.t('pipelinq', 'Ingediend'),
				posted: this.t('pipelinq', 'Geboekt'),
				failed: this.t('pipelinq', 'Mislukt'),
				reconciled: this.t('pipelinq', 'Verrekend'),
			}
			return labels[status] ?? status ?? '—'
		},

		/**
		 * Return a human-readable Dutch label for a payment method.
		 *
		 * @param {string} method
		 * @return {string}
		 */
		paymentMethodLabel(method) {
			const labels = {
				cash: this.t('pipelinq', 'Contant'),
				card: this.t('pipelinq', 'Pin/Kaart'),
				voucher: this.t('pipelinq', 'Voucher'),
			}
			return labels[method] ?? method ?? '—'
		},
	},
}
</script>

<style scoped>
.z-report-detail {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline, 4px) * 6);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
}

.z-report-section h2 {
	font-weight: 600;
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
	font-size: 1em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	color: var(--color-text-lighter, #555);
}

.z-report-summary {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
	gap: calc(var(--default-grid-baseline, 4px) * 2);
}

.z-report-summary__item dt {
	font-size: 0.85em;
	color: var(--color-text-lighter, #888);
	margin-bottom: 2px;
}

.z-report-summary__item dd {
	font-weight: 600;
	margin: 0;
}

.z-report-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 0.9em;
}

.z-report-table th,
.z-report-table td {
	padding: calc(var(--default-grid-baseline, 4px) * 1.5) calc(var(--default-grid-baseline, 4px) * 2);
	text-align: left;
	border-bottom: 1px solid var(--color-border, #eee);
}

.z-report-table th {
	font-weight: 600;
	background: var(--color-background-dark, #f5f5f5);
}

.z-report-status-badge {
	padding: 2px 10px;
	border-radius: 12px;
	font-size: 0.85em;
	font-weight: 600;
}

.status--draft      { background: #f0f0f0; color: #555; }
.status--ready      { background: #d0e8f8; color: #0055a5; }
.status--submitted  { background: #fff3cd; color: #856404; }
.status--posted     { background: #d4edda; color: #155724; }
.status--failed     { background: #f8d7da; color: #721c24; }
.status--reconciled { background: #d1ecf1; color: #0c5460; }
</style>

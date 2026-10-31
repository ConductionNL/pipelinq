<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for ZReportDetail (board PqZRapport, the Boekhouding
  - card): a note that shillinq keeps the books, the bookkeeping status and
  - the shillinq journal entry id, the manager-gated button that passes the
  - day on again (POST /api/pos-bookkeeping/post, same idempotency key), and
  - the VAT and payment-method breakdowns. Both breakdowns are arrays on the
  - Z-report, not child records, so no object-list can draw them.
  -
  - The page hands the Z-report over as `objectData` and re-reads it on
  - `cn:page:refresh`; after a raise the card shows the record the endpoint
  - returned until then.
  -
  - @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
  -->
<template>
	<CnWidgetWrapper
		class="z-bookkeeping"
		:title="title || t('pipelinq', 'Bookkeeping')"
		:showActions="false"
		:showRefresh="false"
		:showRequestFeature="false">
		<NcNoteCard type="info">
			{{ t('pipelinq', 'The general ledger, the VAT posting and the journal entry are managed in shillinq. Pipelinq only passes on the facts of this POS day through the integration registry.') }}
		</NcNoteCard>

		<dl class="z-bookkeeping__facts">
			<div class="z-bookkeeping__fact" data-testid="z-bookkeeping-fact">
				<dt>{{ t('pipelinq', 'Bookkeeping status') }}</dt>
				<dd data-testid="pos-eod-bookkeeping-status">
					{{ bookkeepingStatusLabel }}
				</dd>
			</div>
			<div class="z-bookkeeping__fact" data-testid="z-bookkeeping-fact">
				<dt>{{ t('pipelinq', 'Journal entry id in shillinq') }}</dt>
				<dd>
					<code v-if="zReport.shillinqJournalEntryId" data-testid="pos-eod-journal-id">{{ zReport.shillinqJournalEntryId }}</code>
					<template v-else>
						{{ t('pipelinq', 'none yet') }}
					</template>
				</dd>
			</div>
		</dl>

		<div v-if="canRetry" class="z-bookkeeping__action">
			<NcButton
				class="cn-button--tonal"
				variant="secondary"
				:disabled="busy"
				data-testid="pos-eod-retry"
				@click="showRetryConfirm = true">
				<template #icon>
					<CnIcon name="Refresh" :size="16" />
				</template>
				{{ t('pipelinq', 'Pass on to shillinq again') }}
			</NcButton>
		</div>

		<h4 class="z-bookkeeping__subtitle">
			{{ t('pipelinq', 'VAT breakdown') }}
		</h4>
		<table class="z-bookkeeping__table" data-testid="z-report-tax-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('pipelinq', 'Rate') }}
					</th>
					<th scope="col" class="z-bookkeeping__amount">
						{{ t('pipelinq', 'Base') }}
					</th>
					<th scope="col" class="z-bookkeeping__amount">
						{{ t('pipelinq', 'VAT') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in taxBreakdown" :key="row.rate">
					<td>{{ row.rate }}%</td>
					<td class="z-bookkeeping__amount">
						{{ formatEur(row.base) }}
					</td>
					<td class="z-bookkeeping__amount">
						{{ formatEur(row.tax) }}
					</td>
				</tr>
				<tr v-if="!taxBreakdown.length">
					<td colspan="3">
						{{ t('pipelinq', 'No VAT breakdown: the report is empty.') }}
					</td>
				</tr>
			</tbody>
		</table>

		<h4 class="z-bookkeeping__subtitle">
			{{ t('pipelinq', 'Payment methods') }}
		</h4>
		<table class="z-bookkeeping__table" data-testid="z-report-payment-table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('pipelinq', 'Method') }}
					</th>
					<th scope="col" class="z-bookkeeping__amount">
						{{ t('pipelinq', 'Amount') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="row in paymentBreakdown" :key="row.method">
					<td>{{ row.method }}</td>
					<td class="z-bookkeeping__amount">
						{{ formatEur(row.amount) }}
					</td>
				</tr>
				<tr v-if="!paymentBreakdown.length">
					<td colspan="2">
						{{ t('pipelinq', 'No payment method data.') }}
					</td>
				</tr>
			</tbody>
		</table>

		<ConfirmDialog
			v-if="showRetryConfirm"
			:name="t('pipelinq', 'Re-raise journal entry')"
			:message="t('pipelinq', 'Re-raise journal entry at shillinq? This uses the same idempotency key, so shillinq prevents duplicate postings.')"
			:confirmLabel="t('pipelinq', 'Re-raise')"
			variant="primary"
			@confirm="performRetry"
			@cancel="showRetryConfirm = false" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'
import { getUserLocale } from '../../services/localeUtils.js'
import { raiseJournalEntry } from '../../services/posBookkeepingApi.js'
import { formatEur } from '../../services/posTotals.js'

const BOOKKEEPING_STATUS_LABELS = {
	pending: 'Waiting for shillinq',
	raised: 'Passed on to shillinq',
	failed: 'Failed',
}

const FAILURE_REASON_LABELS = {
	unreachable: 'shillinq did not respond',
	not_configured: 'no shillinq connection is set up',
}

export default {
	name: 'ZReportBookkeepingWidget',

	components: {
		CnIcon,
		CnWidgetWrapper,
		ConfirmDialog,
		NcButton,
		NcNoteCard,
	},

	// The host also hands over register / schema / store and the widget
	// content; none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/**
		 * The Z-report, from the detail page.
		 *
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		objectData: {
			type: Object,
			default: null,
		},

		/** The Z-report id, from the detail page. */
		objectId: {
			type: String,
			default: '',
		},

		/** The manifest widget title. */
		title: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			/** The record the raise endpoint returned, until the page re-reads it. */
			raised: null,
			busy: false,
			showRetryConfirm: false,
		}
	},

	computed: {
		/**
		 * The Z-report: the record the raise returned, else the page's.
		 *
		 * @return {object} The Z-report.
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		zReport() {
			return this.raised || this.objectData || {}
		},

		/**
		 * The VAT rows: rate, base and VAT.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		taxBreakdown() {
			return Array.isArray(this.zReport.taxBreakdown) ? this.zReport.taxBreakdown : []
		},

		/**
		 * The payment rows: method and amount.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		paymentBreakdown() {
			return Array.isArray(this.zReport.paymentMethodBreakdown) ? this.zReport.paymentMethodBreakdown : []
		},

		/**
		 * The bookkeeping status in words, with why and when the last pass-on
		 * failed when the report stores it.
		 *
		 * @return {string} The label.
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		bookkeepingStatusLabel() {
			const key = this.zReport.bookkeepingStatus || 'pending'
			const status = t('pipelinq', BOOKKEEPING_STATUS_LABELS[key] || key)
			const reason = FAILURE_REASON_LABELS[this.zReport.bookkeepingFailureReason]
			if (key === 'raised' || !reason || !this.zReport.bookkeepingFailedAt) {
				return status
			}
			return t('pipelinq', '{status}: {reason} at {time}', {
				status,
				reason: t('pipelinq', reason),
				time: new Date(this.zReport.bookkeepingFailedAt).toLocaleTimeString(getUserLocale(), { hour: '2-digit', minute: '2-digit' }),
			})
		},

		/**
		 * Whether the manager-gated button is shown. The server-side gate is
		 * authoritative; this only hides the button for non-managers and for
		 * days already passed on.
		 *
		 * @return {boolean} Whether passing on again is offered.
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		canRetry() {
			const status = this.zReport.bookkeepingStatus || 'pending'
			const hasTakings = Number(this.zReport.transactionCount || 0) > 0
			const isManager = typeof window.OC?.isUserAdmin === 'function' ? window.OC.isUserAdmin() : false
			return ['pending', 'failed'].includes(status) && hasTakings && isManager
		},
	},

	watch: {
		/**
		 * Drop the raise's record once the page has re-read the Z-report.
		 *
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		objectData() {
			this.raised = null
		},
	},

	methods: {
		formatEur,

		/**
		 * Pass the day on to shillinq again once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
		 */
		async performRetry() {
			this.showRetryConfirm = false
			const id = this.objectId || this.zReport.id
			if (!id) {
				return
			}
			this.busy = true
			try {
				const updated = await raiseJournalEntry(id)
				if (updated) {
					this.raised = updated
				}
				showSuccess(t('pipelinq', 'Journal entry raised at shillinq.'))
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Raise failed.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.z-bookkeeping__facts {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin: 16px 0 12px;
}

.z-bookkeeping__fact {
	display: grid;
	grid-template-columns: 180px minmax(0, 1fr);
	gap: 0 12px;
}

.z-bookkeeping__fact dt {
	color: var(--color-text-maxcontrast);
}

.z-bookkeeping__fact dd {
	margin: 0;
}

.z-bookkeeping__action {
	margin-bottom: 8px;
}

.z-bookkeeping__subtitle {
	margin: 16px 0 8px;
	font-size: 15px;
	font-weight: 700;
}

.z-bookkeeping__table {
	width: 100%;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
	border-collapse: separate;
	border-spacing: 0;
	overflow: hidden;
}

.z-bookkeeping__table th,
.z-bookkeeping__table td {
	padding: 10px 18px;
	text-align: start;
}

.z-bookkeeping__table th {
	background: var(--cn-board-table-head-bg, color-mix(in srgb, var(--color-background-hover) 40%, var(--color-main-background)));
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: 600;
	line-height: 1.2;
}

.z-bookkeeping__table tbody tr + tr td {
	border-top: 1px solid var(--color-border);
}

.z-bookkeeping__table .z-bookkeeping__amount {
	text-align: end;
	font-variant-numeric: tabular-nums;
}
</style>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Z-report in-body section (kind:'section') for the declarative type:"detail"
  - ZReportDetail page (pipelinq-detail-pages-declarative-r3). The Z-report's flat
  - fields (reference / reportDate / status / subtotal / totalTax / total / …)
  - auto-render in the detail-page body via CnObjectDataWidget; this section adds
  - the three things the auto-body cannot express:
  -   1. the BTW (tax) breakdown table — an ARRAY field on the object, not an FK
  -      child, so it is not a relatedCollection;
  -   2. the payment-method breakdown table — same array-on-object shape;
  -   3. the shillinq bookkeeping-status projection + the manager-gated, idempotent
  -      "Re-raise journal entry" action (POST /api/pos-bookkeeping/post). The GL
  -      journal itself is owned by shillinq; pipelinq only mirrors the outcome.
  -
  - Self-fetches the Z-report by id (passed as `zReportId` via @objectId, with a
  - cnSectionContext inject fallback) so it stays in sync after a re-raise.
  -
  - @spec openspec/changes/pipelinq-bookkeeping-to-shillinq/specs/pipelinq-bookkeeping-to-shillinq/spec.md#REQ-PBTS-002
  -->
<template>
	<div class="z-report-section">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<section class="z-report-section__block">
				<h4>{{ t('pipelinq', 'Boekhoudkundige status') }}</h4>
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
				<p class="z-report-section__hint">
					{{ t('pipelinq', 'Het grootboek, de BTW-boeking en de journaalpost worden beheerd in shillinq. Pipelinq raise alleen de bedrijfsfeiten van deze POS-dag via de integratie-registry.') }}
				</p>
				<NcButton
					v-if="canRetry"
					type="primary"
					:disabled="busy"
					data-testid="pos-eod-retry"
					@click="confirmAndRetry">
					{{ t('pipelinq', 'Opnieuw raisen bij shillinq') }}
				</NcButton>
			</section>

			<section class="z-report-section__block">
				<h4>{{ t('pipelinq', 'BTW uitsplitsing') }}</h4>
				<table class="z-report-section__table" data-testid="z-report-tax-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Tarief') }}</th>
							<th>{{ t('pipelinq', 'Basis') }}</th>
							<th>{{ t('pipelinq', 'BTW') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in taxBreakdown" :key="row.rate">
							<td>{{ row.rate }}%</td>
							<td>{{ formatEur(row.base) }}</td>
							<td>{{ formatEur(row.tax) }}</td>
						</tr>
						<tr v-if="!taxBreakdown.length">
							<td colspan="3">
								{{ t('pipelinq', 'Geen BTW uitsplitsing — leeg report.') }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<section class="z-report-section__block">
				<h4>{{ t('pipelinq', 'Betaalmethoden') }}</h4>
				<table class="z-report-section__table" data-testid="z-report-payment-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Methode') }}</th>
							<th>{{ t('pipelinq', 'Bedrag') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in paymentBreakdown" :key="row.method">
							<td>{{ row.method }}</td>
							<td>{{ formatEur(row.amount) }}</td>
						</tr>
						<tr v-if="!paymentBreakdown.length">
							<td colspan="2">
								{{ t('pipelinq', 'Geen betaalmethode data.') }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { formatEur } from '../../services/posTotals.js'
import { raiseJournalEntry } from '../../services/posBookkeepingApi.js'

const BOOKKEEPING_STATUS_LABELS = {
	pending: 'In wachtrij',
	raised: 'Geraised in shillinq',
	failed: 'Raise gefaald',
}

export default {
	name: 'ZReportBookkeepingSection',
	components: {
		NcButton,
		NcLoadingIcon,
		CnStatusBadge,
	},
	inject: {
		cnSectionContext: { default: null },
	},
	props: {
		/** The Z-report id (token-resolved from @objectId by CnBodySections). */
		zReportId: {
			type: String,
			default: '',
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
		/** The resolved Z-report id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.zReportId) {
				return this.zReportId
			}
			const ctx = this.cnSectionContext
			const bag = (ctx && typeof ctx === 'object' && 'value' in ctx) ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},
		taxBreakdown() {
			return Array.isArray(this.zReport.taxBreakdown) ? this.zReport.taxBreakdown : []
		},
		paymentBreakdown() {
			return Array.isArray(this.zReport.paymentMethodBreakdown) ? this.zReport.paymentMethodBreakdown : []
		},
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
	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},
	methods: {
		formatEur,
		/**
		 * Load the Z-report so the breakdown tables + bookkeeping projection render.
		 */
		async load() {
			if (!this.resolvedId) {
				return
			}
			this.loading = true
			try {
				this.zReport = await this.objectStore.fetchObject('posZReport', this.resolvedId) || {}
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
			if (!this.resolvedId) {
				return
			}
			if (!window.confirm(t('pipelinq', 'Journaalpost opnieuw raisen bij shillinq? Dit gebruikt dezelfde idempotency key, dus shillinq voorkomt dubbele boekingen.'))) {
				return
			}
			this.busy = true
			try {
				const updated = await raiseJournalEntry(this.resolvedId)
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
.z-report-section {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.z-report-section__block h4 {
	margin: 0 0 8px;
	font-weight: 600;
}

.info-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 8px 24px;
	margin-bottom: 8px;
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

.z-report-section__hint {
	margin: 0 0 12px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.z-report-section__table {
	width: 100%;
	border-collapse: collapse;
}

.z-report-section__table th,
.z-report-section__table td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
	text-align: left;
}

.z-report-section__table th {
	font-weight: 600;
}
</style>

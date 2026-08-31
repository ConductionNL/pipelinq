<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Client-detail in-body section (kind:'section') for the manager-facing
  - "Send to billing" action (time-billing-handoff-emit) — the real emit side
  - of the shillinq time-approval-workflow handoff. Self-fetches
  - GET /api/billing/handoff/{clientId}/availability on mount so the button
  - renders ONLY when shillinq's time-intake integration is enabled AND the
  - acting user is a billing-handoff manager; the existing Shillinq deep-link
  - (shillinq_app_url) stays offered as the fallback otherwise — the deep-link
  - handoff itself is unchanged (same pattern as ContractInvoicingSection's
  - hidden-without-implementer rule from semantic-handoff-emit).
  -
  - POST /api/billing/handoff/{clientId} batches the client's approved,
  - un-billed time entries for the chosen period (default: current month) into
  - one idempotent shillinq draft invoice. A re-send under the same period
  - selection recomputes the identical deterministic batchId, so retrying a
  - failed batch is just clicking the button again.
  -
  - @spec openspec/specs/time-approval-workflow/spec.md
  -->
<template>
	<div v-if="loading" class="client-billing-handoff-section">
		<NcLoadingIcon :size="24" />
	</div>
	<div v-else-if="hasContent" class="client-billing-handoff-section">
		<template v-if="showSendControls">
			<div class="client-billing-handoff-section__period">
				<div class="client-billing-handoff-section__field">
					<label for="billing-handoff-period-start">{{
						t('pipelinq', 'From')
					}}</label>
					<input
						id="billing-handoff-period-start"
						v-model="periodStart"
						type="date"
						:aria-label="t('pipelinq', 'Billing period start date')" />
				</div>
				<div class="client-billing-handoff-section__field">
					<label for="billing-handoff-period-end">{{
						t('pipelinq', 'To')
					}}</label>
					<input
						id="billing-handoff-period-end"
						v-model="periodEnd"
						type="date"
						:aria-label="t('pipelinq', 'Billing period end date')" />
				</div>
			</div>
			<NcButton
				variant="primary"
				:disabled="busy || !periodStart || !periodEnd"
				data-testid="billing-handoff-send"
				@click="send">
				<template #icon>
					<NcLoadingIcon v-if="busy" :size="16" />
				</template>
				{{ t('pipelinq', 'Send to billing') }}
			</NcButton>
		</template>
		<NcButton
			v-else-if="deepLinkUrl"
			variant="secondary"
			:href="deepLinkUrl"
			target="_blank"
			rel="noopener">
			{{ t('pipelinq', 'Continue in Shillinq') }}
		</NcButton>

		<NcNoteCard
			v-if="lastResult"
			:type="lastResultType"
			class="client-billing-handoff-section__result">
			{{ lastResultMessage }}
		</NcNoteCard>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import {
	getBillingHandoffAvailability,
	sendToBilling,
} from '../../services/billingHandoffApi.js'

const DEFAULT_AVAILABILITY = {
	available: false,
	deepLinkUrl: '',
	isManager: false,
}

/**
 * The first and last ISO 8601 date of the current month.
 *
 * @return {{start: string, end: string}} The default period.
 */
function currentMonthPeriod() {
	const now = new Date()
	const start = new Date(now.getFullYear(), now.getMonth(), 1)
	const end = new Date(now.getFullYear(), now.getMonth() + 1, 0)
	const iso = (d) => d.toISOString().slice(0, 10)
	return { start: iso(start), end: iso(end) }
}

export default {
	name: 'ClientBillingHandoffSection',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The client id (token-resolved from `@objectId` by CnBodySections). */
		clientId: {
			type: String,
			default: '',
		},
	},

	data() {
		const period = currentMonthPeriod()
		return {
			loading: false,
			busy: false,
			availability: { ...DEFAULT_AVAILABILITY },
			periodStart: period.start,
			periodEnd: period.end,
			lastResult: null,
		}
	},

	computed: {
		/** The resolved client id — prop wins, else the injected section context. */
		/**
		 * @spec openspec/specs/time-approval-workflow/spec.md
		 */
		resolvedId() {
			if (this.clientId) {
				return this.clientId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		/**
		 * @spec openspec/specs/time-approval-workflow/spec.md
		 */
		deepLinkUrl() {
			return this.availability.deepLinkUrl
		},

		/** Shown only when the real emit is enabled AND the user is a manager. */
		showSendControls() {
			return (
				this.availability.available === true
				&& this.availability.isManager === true
			)
		},

		/** Whether this section has anything to render at all (else it stays hidden). */
		hasContent() {
			return this.showSendControls || !!this.deepLinkUrl
		},

		/**
		 * @spec openspec/specs/time-approval-workflow/spec.md
		 */
		lastResultType() {
			if (!this.lastResult) {
				return 'success'
			}
			if (
				this.lastResult.status === 'synced'
				|| this.lastResult.status === 'empty'
			) {
				return 'success'
			}
			if (this.lastResult.status === 'conflict') {
				return 'warning'
			}
			return 'error'
		},

		/**
		 * @spec openspec/specs/time-approval-workflow/spec.md
		 */
		lastResultMessage() {
			if (!this.lastResult) {
				return ''
			}
			switch (this.lastResult.status) {
				case 'synced':
					return this.lastResult.duplicated
						? t(
								'pipelinq',
								'Already sent — Shillinq returned the existing draft invoice {number}.',
								{ number: this.lastResult.invoiceNumber },
							)
						: t(
								'pipelinq',
								'Sent to billing — draft invoice {number} created ({count} entries).',
								{
									number: this.lastResult.invoiceNumber,
									count: this.lastResult.entryCount,
								},
							)
				case 'empty':
					return t(
						'pipelinq',
						'No approved, un-billed hours in this period.',
					)
				case 'conflict':
					return t(
						'pipelinq',
						'This batch is already being processed by Shillinq — try again shortly.',
					)
				case 'unmapped':
					return (
						this.lastResult.message
						|| t(
							'pipelinq',
							'Shillinq could not resolve this client or a rate. Check the Shillinq organisation reference on this client.',
						)
					)
				default:
					return (
						this.lastResult.message
						|| t('pipelinq', 'Could not send this batch to billing.')
					)
			}
		},
	},

	watch: {
		resolvedId: {
			immediate: true,
			/**
			 * @spec openspec/specs/time-approval-workflow/spec.md
			 */
			handler() {
				this.lastResult = null
				this.loadAvailability()
			},
		},
	},

	methods: {
		/**
		 * Fetch the current "Send to billing" availability for this client.
		 */
		async loadAvailability() {
			if (!this.resolvedId) {
				this.availability = { ...DEFAULT_AVAILABILITY }
				return
			}
			this.loading = true
			try {
				this.availability = await getBillingHandoffAvailability(
					this.resolvedId,
				)
			} catch (e) {
				this.availability = { ...DEFAULT_AVAILABILITY }
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send the selected period's approved, un-billed entries to billing.
		 *
		 * @spec openspec/specs/time-approval-workflow/spec.md
		 */
		async send() {
			if (
				!this.resolvedId
				|| this.busy
				|| !this.periodStart
				|| !this.periodEnd
			) {
				return
			}
			this.busy = true
			this.lastResult = null
			try {
				this.lastResult = await sendToBilling(
					this.resolvedId,
					this.periodStart,
					this.periodEnd,
				)
			} catch (err) {
				this.lastResult = (err && err.response && err.response.data) || {
					status: 'failed',
				}
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.client-billing-handoff-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.client-billing-handoff-section__period {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.client-billing-handoff-section__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.client-billing-handoff-section__field label {
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.client-billing-handoff-section__result {
	max-width: 480px;
}
</style>

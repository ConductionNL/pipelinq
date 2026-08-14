<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Contract-detail in-body section (kind:'section') for the ADR-051 emit-side
  - "Send to invoicing" handoff (semantic-handoff-emit). Self-fetches
  - GET /api/handoff/contract/{id}/availability on mount so the action renders
  - ONLY when an installed app implements https://openregister.app/ns#Invoice
  - AND the contract is `active` (canSend) — hidden otherwise, never
  - disabled-with-tooltip, per the ADR-051 hidden-without-implementer rule.
  -
  - Unlike the request→case conversion, a successful send does NOT change the
  - contract's own status (contract-renewal-tracking keeps it `active` so a
  - recurring contract can be billed again next interval) — so the button
  - stays available after a send; this section only shows the last
  - invoiceReference as a success confirmation, not a permanent converted
  - state. The target app is kind-addressed and unknown to the frontend, so
  - the reference is shown as a labeled, copyable value rather than a
  - guessed/broken cross-app link.
  -
  - @spec openspec/specs/contract-renewal-tracking/spec.md#requirement-contract-to-invoicing-handoff-emit
  -->
<template>
	<div v-if="loading" class="contract-invoicing-section">
		<NcLoadingIcon :size="24" />
	</div>
	<div v-else-if="hasContent" class="contract-invoicing-section">
		<NcButton
			v-if="showSendButton"
			variant="primary"
			:disabled="busy"
			@click="sendToInvoicing">
			{{ t('pipelinq', 'Send to invoicing') }}
		</NcButton>

		<template v-if="lastInvoiceReference">
			<NcNoteCard type="success" class="contract-invoicing-section__notice">
				{{ t('pipelinq', 'Contract sent to invoicing.') }}
			</NcNoteCard>
			<div class="contract-invoicing-section__reference">
				<span class="contract-invoicing-section__reference-label">
					{{ t('pipelinq', 'Invoice reference') }}
				</span>
				<code class="contract-invoicing-section__reference-value">{{
					lastInvoiceReference
				}}</code>
				<NcButton variant="tertiary" @click="copyReference">
					{{ t('pipelinq', 'Copy') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'

const DEFAULT_AVAILABILITY = {
	available: false,
	status: '',
	canSend: false,
}

export default {
	name: 'ContractInvoicingSection',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The contract id (token-resolved from @objectId by CnBodySections). */
		contractId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: false,
			busy: false,
			availability: { ...DEFAULT_AVAILABILITY },
			lastInvoiceReference: '',
		}
	},

	computed: {
		/** The resolved contract id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.contractId) {
				return this.contractId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		/** Shown only when an ns#Invoice implementer exists AND the contract is active. */
		showSendButton() {
			return this.availability.canSend === true
		},

		/** Whether this section has anything to render at all (else it stays hidden). */
		hasContent() {
			return this.showSendButton || !!this.lastInvoiceReference
		},
	},

	watch: {
		resolvedId: {
			immediate: true,
			handler() {
				this.lastInvoiceReference = ''
				this.loadAvailability()
			},
		},
	},

	methods: {
		/**
		 * Fetch the current send-to-invoicing availability for this contract.
		 */
		async loadAvailability() {
			if (!this.resolvedId) {
				this.availability = { ...DEFAULT_AVAILABILITY }
				return
			}
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl(
						'/apps/pipelinq/api/handoff/contract/{id}/availability',
						{ id: this.resolvedId },
					),
				)
				this.availability = {
					available: !!data.available,
					status: data.status || '',
					canSend: !!data.canSend,
				}
			} catch (e) {
				this.availability = { ...DEFAULT_AVAILABILITY }
			} finally {
				this.loading = false
			}
		},

		/**
		 * Send the active contract to the invoicing handoff endpoint.
		 */
		async sendToInvoicing() {
			if (!this.resolvedId || this.busy) {
				return
			}
			this.busy = true
			try {
				const { data } = await axios.post(
					generateUrl(
						'/apps/pipelinq/api/handoff/contract/{id}/send-to-invoicing',
						{ id: this.resolvedId },
					),
					{},
				)
				this.lastInvoiceReference = data.invoiceReference || ''
				showSuccess(t('pipelinq', 'Contract sent to invoicing.'))
				this.$emit('sent', { invoiceReference: this.lastInvoiceReference })
			} catch (err) {
				const body = (err && err.response && err.response.data) || {}
				if (
					body.status === 'invalid-status'
					|| body.status === 'not-available'
				) {
					showError(
						t(
							'pipelinq',
							'Sending to invoicing is no longer available for this contract.',
						),
					)
					await this.loadAvailability()
				} else if (body.status === 'handoff-failed') {
					showError(
						t(
							'pipelinq',
							'Could not send this contract to invoicing: {reason}',
							{
								reason:
									body.reason || t('pipelinq', 'unknown error'),
							},
						),
					)
				} else {
					showError(
						t('pipelinq', 'Could not send this contract to invoicing.'),
					)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the invoice reference UUID to the clipboard.
		 */
		async copyReference() {
			try {
				await navigator.clipboard.writeText(this.lastInvoiceReference)
				showSuccess(t('pipelinq', 'Invoice reference copied.'))
			} catch (e) {
				showError(t('pipelinq', 'Could not copy the invoice reference.'))
			}
		},
	},
}
</script>

<style scoped>
.contract-invoicing-section {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.contract-invoicing-section__reference {
	display: flex;
	align-items: center;
	gap: 8px;
	flex-wrap: wrap;
}

.contract-invoicing-section__reference-label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.contract-invoicing-section__reference-value {
	padding: 2px 8px;
	border-radius: var(--border-radius, 4px);
	background-color: var(--color-background-hover);
	font-size: 13px;
}
</style>

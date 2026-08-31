<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS refund in-body section (kind:'section') for the declarative type:"detail"
  - PosRefundDetail page (pipelinq-pos-mdm-detail-declarative). The refund's flat
  - fields auto-render via CnObjectDataWidget; this section carries the parts no
  - declarative primitive can express:
  -   1. the manager-gated Bevestigen / Afwijzen actions, which POST to bespoke
  -      /api/pos-refunds/{id}/{action} endpoints — posRefund has no
  -      x-openregister-lifecycle, so CnLifecycleActions cannot drive them;
  -   2. the "Returned items" table, which is a CROSS-SCHEMA JOIN: each
  -      posRefundLine row is enriched with its original posTransactionLine
  -      (description + original quantity, via the line's `originalLine` pointer) —
  -      relatedCollections renders ONE schema and cannot join the refund line to
  -      its original-transaction line;
  -   3. the refund totals panel (computed over the joined line rows).
  -
  - Self-fetches the refund, its lines and the original-transaction lines by id
  - (passed as `refundId` via @objectId, with a cnSectionContext inject fallback).
  -->
<template>
	<div class="pos-refund-section">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<section v-if="hasActions" class="pos-refund-section__actions">
				<NcButton
					v-if="canConfirm"
					variant="primary"
					:disabled="busy"
					@click="confirm">
					{{ t('pipelinq', 'Confirm') }}
				</NcButton>
				<NcButton
					v-if="canReject"
					variant="error"
					:disabled="busy"
					@click="showReject = true">
					{{ t('pipelinq', 'Reject') }}
				</NcButton>
			</section>

			<CnDetailCard :title="t('pipelinq', 'Returned items')">
				<table class="pos-refund-section__lines">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Description') }}</th>
							<th scope="col" class="num">
								{{ t('pipelinq', 'Original qty') }}
							</th>
							<th scope="col" class="num">
								{{ t('pipelinq', 'Returned qty') }}
							</th>
							<th scope="col">{{ t('pipelinq', 'Reason') }}</th>
							<th scope="col">{{ t('pipelinq', 'Restock') }}</th>
							<th scope="col" class="num">
								{{ t('pipelinq', 'Refund total') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in lineRows" :key="row.id">
							<td>{{ row.description }}</td>
							<td class="num">
								{{ row.originalQuantity }}
							</td>
							<td class="num">
								{{ row.returnedQuantity }}
							</td>
							<td>{{ reasonLabel(row.returnReason) }}</td>
							<td>
								{{
									row.restock
										? t('pipelinq', 'Yes')
										: t('pipelinq', 'No')
								}}
							</td>
							<td class="num">
								{{ formatEur(row.lineTotal) }}
							</td>
						</tr>
						<tr v-if="lineRows.length === 0">
							<td colspan="6" class="empty">
								{{ t('pipelinq', 'No returned items.') }}
							</td>
						</tr>
					</tbody>
				</table>
			</CnDetailCard>

			<CnDetailCard :title="t('pipelinq', 'Refund totals')">
				<PosRefundTotalsPanel :lines="lineRows" />
			</CnDetailCard>

			<PosRefundRejectDialog
				v-if="showReject"
				:submitting="busy"
				@close="showReject = false"
				@confirm="reject" />
		</template>
	</div>
</template>

<script>
import { CnDetailCard } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import PosRefundRejectDialog from '../../modals/PosRefundRejectDialog.vue'
import PosRefundTotalsPanel from './PosRefundTotalsPanel.vue'
import { formatEur } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRefundActionsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		CnDetailCard,
		PosRefundTotalsPanel,
		PosRefundRejectDialog,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The refund id (token-resolved from `@objectId` by CnBodySections). */
		refundId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			refund: {},
			lines: [],
			originalLines: [],
			reasons: [],
			loading: false,
			busy: false,
			showReject: false,
		}
	},

	computed: {
		objectStore() {
			return useObjectStore()
		},

		/** The resolved refund id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.refundId) {
				return this.refundId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		status() {
			return this.refund.status || 'pending'
		},

		/**
		 * Whether the current user is treated as a manager in the UI. Server-side
		 * authorization is authoritative; this only hides the buttons for clearly
		 * non-privileged users. NC admins are always managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
		},

		canConfirm() {
			return this.status === 'pending' && this.isManager
		},

		canReject() {
			return this.status === 'pending' && this.isManager
		},

		hasActions() {
			return this.canConfirm || this.canReject
		},

		/**
		 * Display rows joining each refund line with its original transaction line.
		 *
		 * @return {Array<object>} The rows.
		 */
		lineRows() {
			return this.lines.map((line) => {
				const original =
					this.originalLines.find((o) => o.id === line.originalLine) || {}
				return {
					id: line.id,
					description: original.description || '-',
					originalQuantity: original.quantity ?? '-',
					returnedQuantity: line.returnedQuantity,
					returnReason: line.returnReason,
					restock: line.restock ?? true,
					taxAmount: line.taxAmount || 0,
					lineTotal: line.lineTotal || 0,
				}
			})
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
		 * Resolve a refundReason id to its label.
		 *
		 * @param {string} id The reason id.
		 * @return {string} The label.
		 */
		reasonLabel(id) {
			const reason = this.reasons.find((r) => r.id === id)
			return reason ? reason.label || reason.code : id || '-'
		},

		/**
		 * Load the refund, its lines, the original transaction lines and reasons.
		 */
		async load() {
			if (!this.resolvedId) {
				return
			}
			this.loading = true
			try {
				this.refund =
					(await this.objectStore.fetchObject(
						'posRefund',
						this.resolvedId,
					)) || {}

				await this.objectStore.fetchCollection('posRefundLine', {
					refund: this.resolvedId,
					_limit: 500,
				})
				this.lines = (
					this.objectStore.getCollection('posRefundLine')?.results || []
				).filter((l) => l.refund === this.resolvedId)

				await this.objectStore.fetchCollection('refundReason', {
					_limit: 100,
				})
				this.reasons =
					this.objectStore.getCollection('refundReason')?.results || []

				if (this.refund.originalTransaction) {
					await this.objectStore.fetchCollection('posTransactionLine', {
						transaction: this.refund.originalTransaction,
						_limit: 500,
					})
					this.originalLines = (
						this.objectStore.getCollection('posTransactionLine')?.results
						|| []
					).filter(
						(l) => l.transaction === this.refund.originalTransaction,
					)
				} else {
					this.originalLines = []
				}
			} catch (err) {
				showError(
					err?.response?.data?.error
						|| t('pipelinq', 'Could not load refund.'),
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * Call a lifecycle action endpoint and reload.
		 *
		 * @param {string} action The action path segment.
		 * @param {object} body The optional request body.
		 * @param {string} successMessage The success toast.
		 * @return {Promise<boolean>} Whether the action succeeded.
		 * @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
		 */
		async lifecycle(action, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/pos-refunds/${this.resolvedId}/${action}`,
					),
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
					showError(data.error || t('pipelinq', 'Action failed.'))
					return false
				}
				showSuccess(successMessage)
				await this.load()
				return true
			} catch {
				showError(t('pipelinq', 'Action failed.'))
				return false
			} finally {
				this.busy = false
			}
		},

		confirm() {
			this.lifecycle('confirm', {}, t('pipelinq', 'Refund completed.'))
		},

		/**
		 * Reject the refund with a reason.
		 *
		 * @param {string} reason The rejection reason.
		 */
		async reject(reason) {
			const ok = await this.lifecycle(
				'reject',
				{ reason },
				t('pipelinq', 'Refund rejected.'),
			)
			if (ok) {
				this.showReject = false
			}
		},
	},
}
</script>

<style scoped>
.pos-refund-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.pos-refund-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.pos-refund-section__lines {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 12px;
}

.pos-refund-section__lines th {
	text-align: left;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
}

.pos-refund-section__lines td {
	padding: 6px 8px;
	border-bottom: 1px solid var(--color-border);
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

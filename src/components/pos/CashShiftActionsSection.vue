<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Cash-shift in-body section (kind:'section') for the declarative type:"detail"
  - CashShiftDetail page (pipelinq-pos-mdm-detail-declarative). The shift's flat
  - float fields auto-render via CnObjectDataWidget and the drops render as a
  - relatedCollections table (cashDrop, FK shift); this section carries the parts
  - no declarative primitive can express:
  -   1. the Geld verwijderen (drop) / Shift afsluiten en tellen (count) actions,
  -      which POST to bespoke /api/pos-shifts/{id}/{drop|count} endpoints and
  -      recompute the variance server-side — cashShift has no
  -      x-openregister-lifecycle, so CnLifecycleActions cannot drive them;
  -   2. the cash-reconciliation VARIANCE panel: it projects the latest/pending
  -      cashDiff for the shift (relatedCollections lists ALL children — it cannot
  -      pick + lay out the single most-relevant cashDiff with its within/outside-
  -      tolerance verdict) and offers the manager-gated Goedkeuren / Afwijzen
  -      reconcile actions (POST /api/pos-shifts/{id}/diff/{approve|reject}).
  -
  - Self-fetches the shift + its drops + diffs by id (passed as `shiftId` via
  - @objectId, with a cnSectionContext inject fallback) so the variance and the
  - action gating stay in sync after a drop / count / reconcile.
  -->
<template>
	<div class="cash-shift-section">
		<NcLoadingIcon v-if="loading" :size="24" />
		<template v-else>
			<section v-if="canDrop || canCount" class="cash-shift-section__actions">
				<NcButton v-if="canDrop" :disabled="busy" @click="showDrop = true">
					{{ t('pipelinq', 'Remove cash') }}
				</NcButton>
				<NcButton
					v-if="canCount"
					variant="primary"
					:disabled="busy"
					@click="showCount = true">
					{{ t('pipelinq', 'Close and count shift') }}
				</NcButton>
			</section>

			<CnDetailCard v-if="diff" :title="t('pipelinq', 'Cash difference')">
				<div class="info-grid">
					<div class="info-field">
						<label>{{ t('pipelinq', 'Expected amount') }}</label>
						<span>{{ formatEur(diff.expectedAmount) }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Counted amount') }}</label>
						<span>{{ formatEur(diff.actualAmount) }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Difference') }}</label>
						<span>{{ formatEur(diff.diffAmount) }}</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Percentage') }}</label>
						<span>{{ percentageLabel }}</span>
					</div>
					<div class="info-field info-field--wide">
						<span
							class="cash-shift-section__tolerance"
							:class="[toleranceClass]">
							{{ toleranceLabel }}
						</span>
					</div>
					<div class="info-field">
						<label>{{ t('pipelinq', 'Reconciliation') }}</label>
						<CnStatusBadge
							:status="diff.status"
							:label="diffStatusLabel" />
					</div>
					<div v-if="diff.approvedBy" class="info-field">
						<label>{{ t('pipelinq', 'Reviewed by') }}</label>
						<span>{{ diff.approvedBy }}</span>
					</div>
					<div
						v-if="diff.rejectionReason"
						class="info-field info-field--wide">
						<label>{{ t('pipelinq', 'Rejection reason') }}</label>
						<span>{{ diff.rejectionReason }}</span>
					</div>
				</div>
				<div v-if="canReconcile" class="cash-shift-section__diff-actions">
					<NcButton variant="primary" :disabled="busy" @click="approve">
						{{ t('pipelinq', 'Approve') }}
					</NcButton>
					<NcButton
						variant="error"
						:disabled="busy"
						@click="showReject = true">
						{{ t('pipelinq', 'Reject') }}
					</NcButton>
				</div>
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
		</template>
	</div>
</template>

<script>
import { CnDetailCard, CnStatusBadge } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CashShiftCountDialog from '../../modals/CashShiftCountDialog.vue'
import CashShiftDropDialog from '../../modals/CashShiftDropDialog.vue'
import CashShiftRejectDialog from '../../modals/CashShiftRejectDialog.vue'
import { formatEur } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'

const DIFF_STATUS_LABELS = {
	pending: 'Pending',
	approved: 'Approved',
	rejected: 'Rejected',
}

export default {
	name: 'CashShiftActionsSection',
	components: {
		NcButton,
		NcLoadingIcon,
		CnDetailCard,
		CnStatusBadge,
		CashShiftDropDialog,
		CashShiftCountDialog,
		CashShiftRejectDialog,
	},

	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The shift id (token-resolved from `@objectId` by CnBodySections). */
		shiftId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			shift: {},
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

		/** The resolved shift id — prop wins, else the injected section context. */
		resolvedId() {
			if (this.shiftId) {
				return this.shiftId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		status() {
			return this.shift.status || 'open'
		},

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
			return typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
		},

		canDrop() {
			return this.status === 'open'
		},

		canCount() {
			return this.status === 'open'
		},

		canReconcile() {
			return (
				this.diff?.status === 'pending'
				&& this.status === 'closed'
				&& this.isManager
			)
		},

		/**
		 * Human label for the diff percentage (N/A when undefined).
		 *
		 * @return {string} The percentage label.
		 */
		percentageLabel() {
			if (
				this.diff?.diffPercentage === null
				|| this.diff?.diffPercentage === undefined
			) {
				return t('pipelinq', 'N/A (expected amount is €0)')
			}
			return `${this.diff.diffPercentage}%`
		},

		toleranceClass() {
			return this.diff?.withinTolerance
				? 'cash-shift-section__tolerance--ok'
				: 'cash-shift-section__tolerance--warn'
		},

		toleranceLabel() {
			return this.diff?.withinTolerance
				? t('pipelinq', 'Within tolerance')
				: t('pipelinq', 'Outside tolerance')
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
		 * Load the shift and the most recent variance.
		 */
		async load() {
			if (!this.resolvedId) {
				return
			}
			this.loading = true
			try {
				this.shift =
					(await this.objectStore.fetchObject(
						'cashShift',
						this.resolvedId,
					)) || {}

				await this.objectStore.fetchCollection('cashDiff', {
					shift: this.resolvedId,
					_limit: 100,
				})
				const diffs = (
					this.objectStore.getCollection('cashDiff')?.results || []
				).filter((d) => d.shift === this.resolvedId)
				this.diff = this.latestDiff(diffs)
			} catch (err) {
				showError(
					err?.response?.data?.error
						|| t('pipelinq', 'Could not load cash shift.'),
				)
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
			const pending = diffs.find((d) => d.status === 'pending')
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
		 * @return {Promise<boolean>} Whether the action succeeded.
		 */
		async lifecycle(path, body, successMessage) {
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(
						`/apps/pipelinq/api/pos-shifts/${this.resolvedId}/${path}`,
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

		/**
		 * Record a mid-shift drop.
		 *
		 * @param {object} payload The drop payload (amount, reason).
		 */
		async recordDrop(payload) {
			const ok = await this.lifecycle(
				'drop',
				payload,
				t('pipelinq', 'Drop recorded.'),
			)
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
			const ok = await this.lifecycle(
				'count',
				payload,
				t('pipelinq', 'Count recorded.'),
			)
			if (ok) {
				this.showCount = false
			}
		},

		/**
		 * Approve the pending variance (manager only).
		 */
		approve() {
			this.lifecycle(
				'diff/approve',
				{ diffId: this.diff?.id },
				t('pipelinq', 'Cash difference approved.'),
			)
		},

		/**
		 * Reject the pending variance with a reason (manager only).
		 *
		 * @param {string} reason The rejection reason.
		 */
		async reject(reason) {
			const ok = await this.lifecycle(
				'diff/reject',
				{ diffId: this.diff?.id, reason },
				t('pipelinq', 'Cash difference rejected.'),
			)
			if (ok) {
				this.showReject = false
			}
		},
	},
}
</script>

<style scoped>
.cash-shift-section {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.cash-shift-section__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

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

.cash-shift-section__tolerance {
	display: inline-block;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill);
	font-weight: bold;
}

.cash-shift-section__tolerance--ok {
	background-color: var(--color-success);
	color: var(--color-primary-text);
}

.cash-shift-section__tolerance--warn {
	background-color: var(--color-warning);
	color: var(--color-primary-text);
}

.cash-shift-section__diff-actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>

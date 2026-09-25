<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Detail-grid widget for CashShiftDetail: the cash-reconciliation VARIANCE.
  -
  - It projects the single most relevant cashDiff for the shift (the pending
  - one, else the latest) with its within/outside-tolerance verdict, and offers
  - the manager-gated Approve / Reject reconcile actions
  - (POST /api/pos-shifts/{id}/diff/{approve|reject}). An object-list lists ALL
  - children and cannot pick and lay out that one diff.
  -
  - Before the shift is counted there is no diff; the widget then says so
  - instead of disappearing, since a grid cell cannot collapse. Re-reads on
  - `cn:page:refresh`.
  -->
<template>
	<CnWidgetWrapper
		class="cash-shift-variance"
		:title="title || t('pipelinq', 'Cash difference')"
		titleIconPosition="left"
		:showActions="false"
		:showRefresh="false"
		:showRequestFeature="false">
		<template #title-icon>
			<CnIcon name="ScaleBalance" :size="20" />
		</template>

		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="!diff" class="cash-shift-variance__empty">
			{{ t('pipelinq', 'Not counted yet. The difference appears once the shift is closed and counted.') }}
		</p>
		<template v-else>
			<dl class="cash-shift-variance__grid">
				<div>
					<dt>{{ t('pipelinq', 'Expected amount') }}</dt>
					<dd>{{ formatEur(diff.expectedAmount) }}</dd>
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Counted amount') }}</dt>
					<dd>{{ formatEur(diff.actualAmount) }}</dd>
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Difference') }}</dt>
					<dd>{{ formatEur(diff.diffAmount) }}</dd>
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Percentage') }}</dt>
					<dd>{{ percentageLabel }}</dd>
				</div>
				<div class="cash-shift-variance__wide">
					<CnStatusBadge
						:label="toleranceLabel"
						:variant="diff.withinTolerance ? 'success' : 'warning'" />
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Reconciliation') }}</dt>
					<dd>
						<CnStatusBadge :label="diffStatusLabel" :variant="diffStatusVariant" size="small" />
					</dd>
				</div>
				<div v-if="diff.approvedBy">
					<dt>{{ t('pipelinq', 'Reviewed by') }}</dt>
					<dd>{{ diff.approvedBy }}</dd>
				</div>
				<div v-if="diff.rejectionReason" class="cash-shift-variance__wide">
					<dt>{{ t('pipelinq', 'Rejection reason') }}</dt>
					<dd>{{ diff.rejectionReason }}</dd>
				</div>
			</dl>

			<div v-if="canReconcile" class="cash-shift-variance__actions">
				<NcButton variant="primary" :disabled="busy" @click="approve">
					{{ t('pipelinq', 'Approve') }}
				</NcButton>
				<NcButton variant="error" :disabled="busy" @click="showReject = true">
					{{ t('pipelinq', 'Reject') }}
				</NcButton>
			</div>
		</template>

		<CashShiftRejectDialog
			v-if="showReject"
			:submitting="busy"
			@close="showReject = false"
			@confirm="reject" />
	</CnWidgetWrapper>
</template>

<script>
import { CnIcon, CnStatusBadge, CnWidgetWrapper } from '@conduction/nextcloud-vue'
import { showError } from '@nextcloud/dialogs'
import { subscribe, unsubscribe } from '@nextcloud/event-bus'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CashShiftRejectDialog from '../../modals/CashShiftRejectDialog.vue'
import { postShiftAction } from '../../services/posShiftActions.js'
import { formatEur } from '../../services/posTotals.js'
import { useObjectStore } from '../../store/modules/object.js'

const DIFF_STATUS_LABELS = {
	pending: 'Pending',
	approved: 'Approved',
	rejected: 'Rejected',
}

const DIFF_STATUS_VARIANTS = {
	pending: 'warning',
	approved: 'success',
	rejected: 'error',
}

export default {
	name: 'CashShiftVarianceWidget',
	components: { CashShiftRejectDialog, CnIcon, CnStatusBadge, CnWidgetWrapper, NcButton, NcLoadingIcon },
	// The host also hands over register / schema / store and the widget
	// content; none of them belong on the root element.
	inheritAttrs: false,

	props: {
		/** The shift, from the detail page. */
		objectData: {
			type: Object,
			default: null,
		},

		/** The shift id, from the detail page. */
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
			diff: null,
			loading: false,
			busy: false,
			showReject: false,
		}
	},

	computed: {
		shiftId() {
			return this.objectId || this.objectData?.id || ''
		},

		status() {
			return this.objectData?.status || 'open'
		},

		diffStatusLabel() {
			const key = this.diff?.status || 'pending'
			return t('pipelinq', DIFF_STATUS_LABELS[key] || key)
		},

		diffStatusVariant() {
			return DIFF_STATUS_VARIANTS[this.diff?.status || 'pending'] || 'default'
		},

		/**
		 * Whether the current user is treated as a manager in the UI. The
		 * server authorises the reconcile; this only hides the buttons for
		 * users who clearly cannot use them. Nextcloud admins are managers.
		 *
		 * @return {boolean} Whether to show manager-only actions.
		 */
		isManager() {
			return typeof window.OC?.isUserAdmin === 'function'
				? window.OC.isUserAdmin()
				: false
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
				return t('pipelinq', 'N/A (expected amount is €0)')
			}
			return `${this.diff.diffPercentage}%`
		},

		toleranceLabel() {
			return this.diff?.withinTolerance
				? t('pipelinq', 'Within tolerance')
				: t('pipelinq', 'Outside tolerance')
		},
	},

	watch: {
		shiftId: {
			immediate: true,
			handler() {
				this.load()
			},
		},
	},

	created() {
		subscribe('cn:page:refresh', this.onPageRefresh)
	},

	beforeUnmount() {
		unsubscribe('cn:page:refresh', this.onPageRefresh)
	},

	methods: {
		formatEur,

		/**
		 * Load the shift's diffs and keep the one to show.
		 *
		 * @param {object} [options] Options.
		 * @param {boolean} [options.silent] Keep the panel on screen while
		 *   reloading.
		 * @return {Promise<void>}
		 */
		async load({ silent = false } = {}) {
			if (!this.shiftId) {
				return
			}
			this.loading = !silent
			try {
				const store = useObjectStore()
				await store.fetchCollection('cashDiff', { shift: this.shiftId, _limit: 100 })
				const diffs = (store.getCollection('cashDiff')?.results || [])
					.filter((d) => d.shift === this.shiftId)
				this.diff = this.latestDiff(diffs)
			} catch (err) {
				showError(err?.response?.data?.error || t('pipelinq', 'Could not load cash shift.'))
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
			return diffs.find((d) => d.status === 'pending') || diffs[diffs.length - 1]
		},

		/**
		 * @param {object} [payload] The refresh payload.
		 */
		onPageRefresh(payload) {
			const done = this.load({ silent: true })
			payload?.waitUntil?.(done)
		},

		/** Approve the pending variance (manager only). */
		async approve() {
			this.busy = true
			await postShiftAction(this.shiftId, 'diff/approve', { diffId: this.diff?.id }, t('pipelinq', 'Cash difference approved.'))
			this.busy = false
		},

		/**
		 * Reject the pending variance with a reason (manager only).
		 *
		 * @param {string} reason The rejection reason.
		 * @return {Promise<void>}
		 */
		async reject(reason) {
			this.busy = true
			const ok = await postShiftAction(this.shiftId, 'diff/reject', { diffId: this.diff?.id, reason }, t('pipelinq', 'Cash difference rejected.'))
			this.busy = false
			if (ok) {
				this.showReject = false
			}
		},
	},
}
</script>

<style scoped>
.cash-shift-variance {
	height: 100%;
	display: flex;
	flex-direction: column;
}

.cash-shift-variance__empty {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.cash-shift-variance__grid {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px 16px;
	margin: 0;
}

.cash-shift-variance__wide {
	grid-column: 1 / -1;
}

.cash-shift-variance__grid dt {
	margin-bottom: 2px;
	font-size: 13px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.cash-shift-variance__grid dd {
	margin: 0;
	font-variant-numeric: tabular-nums;
}

.cash-shift-variance__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>

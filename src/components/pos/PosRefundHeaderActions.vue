<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The PosRefundDetail page's `actionsComponent`: the manager-gated Confirm
  - and Reject actions, rendered in the page header.
  -
  - They POST to the bespoke /api/pos-refunds/{id}/{action} endpoints; posRefund
  - has no x-openregister-lifecycle, so CnLifecycleActions cannot drive them.
  - A successful action bumps `cn:page:refresh`, so every widget on the page
  - re-reads the refund.
  -
  - @spec openspec/specs/pos-lifecycle-guard-adoption/spec.md#REQ-PLG-001
  -->
<template>
	<div v-if="hasActions" class="pos-refund-header-actions" data-testid="pos-refund-header-actions">
		<NcButton
			v-if="canConfirm"
			variant="primary"
			:disabled="busy"
			@click="run('confirm', {}, t('pipelinq', 'Refund completed.'))">
			{{ t('pipelinq', 'Confirm') }}
		</NcButton>
		<NcButton
			v-if="canReject"
			variant="error"
			:disabled="busy"
			@click="showReject = true">
			{{ t('pipelinq', 'Reject') }}
		</NcButton>

		<PosRefundRejectDialog
			v-if="showReject"
			:submitting="busy"
			@close="showReject = false"
			@confirm="reject" />
	</div>
</template>

<script>
import { showError, showSuccess } from '@nextcloud/dialogs'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import PosRefundRejectDialog from '../../modals/PosRefundRejectDialog.vue'

export default {
	name: 'PosRefundHeaderActions',
	components: { NcButton, PosRefundRejectDialog },
	// The slot also hands over schema / store / openEditForm; none of them
	// belong on the root element.
	inheritAttrs: false,

	props: {
		/** The refund, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The refund id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			busy: false,
			showReject: false,
		}
	},

	computed: {
		refund() {
			return this.object || {}
		},

		refundId() {
			return this.objectId || this.refund.id || ''
		},

		status() {
			return this.refund.status || 'pending'
		},

		/**
		 * Whether the current user is treated as a manager in the UI. The
		 * server authorises the action; this only hides the buttons for users
		 * who clearly cannot use them. Nextcloud admins are always managers.
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
			return Boolean(this.object) && (this.canConfirm || this.canReject)
		},
	},

	methods: {
		/**
		 * POST a refund action, then refresh the page.
		 *
		 * @param {string} action The path segment (e.g. 'confirm').
		 * @param {object} body Optional JSON body.
		 * @param {string} okMsg Success toast message.
		 * @return {Promise<boolean>} Whether the action succeeded.
		 */
		async run(action, body, okMsg) {
			if (!this.refundId) {
				return false
			}
			this.busy = true
			try {
				const response = await fetch(
					generateUrl(`/apps/pipelinq/api/pos-refunds/${this.refundId}/${action}`),
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
				showSuccess(okMsg)
				emit('cn:page:refresh', {})
				return true
			} catch {
				showError(t('pipelinq', 'Action failed.'))
				return false
			} finally {
				this.busy = false
			}
		},

		/**
		 * Reject the refund with a reason.
		 *
		 * @param {string} reason The rejection reason.
		 * @return {Promise<void>}
		 */
		async reject(reason) {
			const ok = await this.run('reject', { reason }, t('pipelinq', 'Refund rejected.'))
			if (ok) {
				this.showReject = false
			}
		},
	},
}
</script>

<style scoped>
.pos-refund-header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}
</style>

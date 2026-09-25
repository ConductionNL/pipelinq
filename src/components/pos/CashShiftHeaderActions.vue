<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - The CashShiftDetail page's `actionsComponent`: Close and count shift,
  - rendered in the page header while the shift is open. Adding a drop lives on
  - the Drops widget (CashShiftDropsWidget), next to the drops themselves.
  -
  - It POSTs to the bespoke /api/pos-shifts/{id}/count endpoint, which closes
  - the shift and computes the variance server-side; cashShift has no
  - x-openregister-lifecycle, so CnLifecycleActions cannot drive it. Success
  - bumps `cn:page:refresh`, so every widget on the page re-reads the shift,
  - its drops and its variance.
  -->
<template>
	<div v-if="canAct" class="cash-shift-header-actions" data-testid="cash-shift-header-actions">
		<NcButton variant="primary" :disabled="busy" @click="showCount = true">
			{{ t('pipelinq', 'Close and count shift') }}
		</NcButton>

		<CashShiftCountDialog
			v-if="showCount"
			:submitting="busy"
			@close="showCount = false"
			@confirm="recordCount" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CashShiftCountDialog from '../../modals/CashShiftCountDialog.vue'
import { postShiftAction } from '../../services/posShiftActions.js'

export default {
	name: 'CashShiftHeaderActions',
	components: { CashShiftCountDialog, NcButton },
	// The slot also hands over schema / store / openEditForm; none of them
	// belong on the root element.
	inheritAttrs: false,

	props: {
		/** The shift, from CnDetailPage's `#actions` slot. */
		object: {
			type: Object,
			default: null,
		},

		/** The shift id, from CnDetailPage's `#actions` slot. */
		objectId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			busy: false,
			showCount: false,
		}
	},

	computed: {
		shiftId() {
			return this.objectId || this.object?.id || ''
		},

		/** @return {boolean} The closing count needs an open shift. */
		canAct() {
			return Boolean(this.object) && (this.object.status || 'open') === 'open'
		},
	},

	methods: {
		/**
		 * Close the shift and record a blind count.
		 *
		 * @param {object} payload The count payload (amount, notes).
		 * @return {Promise<void>}
		 */
		async recordCount(payload) {
			this.busy = true
			const ok = await postShiftAction(this.shiftId, 'count', payload, t('pipelinq', 'Count recorded.'))
			this.busy = false
			if (ok) {
				this.showCount = false
			}
		},
	},
}
</script>

<style scoped>
.cash-shift-header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--default-grid-baseline);
}
</style>

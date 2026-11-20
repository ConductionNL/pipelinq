<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - SlipReasonModal asks why a commit or best-case deal moves out of its
  - forecast period. LeadEditDialog opens it when a save would slip the close
  - date past the end of the old date's quarter; the save waits for the reason
  - and does not finish without one. The reason needs at least ten characters,
  - the same minimum the server checks.
  -
  - @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
  -->
<template>
	<CnDialog
		:name="t('pipelinq', 'Why does this deal slip?')"
		width="form"
		data-testid="slip-reason-dialog"
		@closing="cancel">
		<div class="slip-reason">
			<p class="slip-reason__intro">
				{{
					t(
						'pipelinq',
						'The close date moves from {from} to {to}, out of the period this deal was forecast in. Say why, so your manager can read it in the forecast trend.',
						{ from: fromDate || '-', to: toDate || '-' },
					)
				}}
			</p>
			<NcTextArea
				v-model="reason"
				:label="t('pipelinq', 'Slip reason')"
				:helperText="
					t('pipelinq', 'At least {count} characters.', {
						count: minLength,
					})
				"
				:error="touched && !valid"
				resize="vertical"
				data-testid="slip-reason-text"
				@blur="touched = true" />
		</div>
		<template #actions>
			<NcButton data-testid="slip-reason-cancel" @click="cancel">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="!valid"
				data-testid="slip-reason-save"
				@click="save">
				{{ t('pipelinq', 'Save with reason') }}
			</NcButton>
		</template>
	</CnDialog>
</template>

<script>
import { CnDialog } from '@conduction/nextcloud-vue'
import { NcButton, NcTextArea } from '@nextcloud/vue'
import {
	isSlipReasonValid,
	SLIP_REASON_MIN_LENGTH,
} from '../services/forecastSlipCheck.js'

export default {
	name: 'SlipReasonModal',

	components: {
		CnDialog,
		NcButton,
		NcTextArea,
	},

	props: {
		/** The close date the deal had, as shown to the seller. */
		fromDate: {
			type: String,
			default: '',
		},

		/** The close date the seller is saving. */
		toDate: {
			type: String,
			default: '',
		},

		/** A reason typed earlier, to start from. */
		initialReason: {
			type: String,
			default: '',
		},
	},

	emits: ['confirm', 'close'],

	data() {
		return {
			reason: this.initialReason,
			touched: false,
			minLength: SLIP_REASON_MIN_LENGTH,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether the reason is long enough to save.
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		valid() {
			return isSlipReasonValid(this.reason)
		},
	},

	methods: {
		/**
		 * Hand the reason back; refused while it is too short.
		 *
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		save() {
			this.touched = true
			if (!this.valid) {
				return
			}
			this.$emit('confirm', this.reason.trim())
		},

		/**
		 * Close without a reason; the save it belongs to does not go through.
		 *
		 * @spec openspec/changes/forecast-slip-reason/specs/forecast-roll-up-and-categories/spec.md#requirement-a-deal-that-slips-out-of-its-forecast-period-needs-a-slip-reason-req-frc-012
		 */
		cancel() {
			this.$emit('close')
		},
	},
}
</script>

<style scoped lang="scss">
.slip-reason {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  ReversalReasonDialog — collects the mandatory reason for reversing a payment.

  Replaces a native prompt, which hydra gate-34 reports. The reason is not
  decoration: it is written to the payment reversal and read back in the
  Belastingdienst audit trail, so it has to be a real, labelled, translatable
  field rather than an unstyled browser box that some platforms suppress
  outright (returning null, which the old code read as "cancelled").

  Confirm stays disabled until a non-empty reason is entered, which is the same
  guard the old `if (!reason) return` provided — expressed in the UI instead of
  after the fact.

  Lives in its own file per ADR-004 (modal-isolation).

  @spec openspec/specs/declarative-view-system/spec.md
-->
<template>
	<NcDialog :name="t('pipelinq', 'Reverse payment')" @closing="$emit('cancel')">
		<NcTextField
			id="reversal-reason"
			:label="t('pipelinq', 'Reason for reversal')"
			:modelValue="reason"
			:placeholder="t('pipelinq', 'Why is this payment being reversed?')"
			@update:modelValue="(v) => (reason = v)" />
		<template #actions>
			<NcButton @click="$emit('cancel')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="!reason.trim()"
				@click="$emit('confirm', reason.trim())">
				{{ t('pipelinq', 'Reverse payment') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ReversalReasonDialog',
	components: { NcButton, NcDialog, NcTextField },
	emits: ['confirm', 'cancel'],
	data() {
		return {
			reason: '',
		}
	},
}
</script>

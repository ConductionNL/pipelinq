<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog :name="t('pipelinq', 'Redact field')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="avg-redact">
			<NcNoteCard v-if="ownDataWarning" type="warning">
				{{ t('pipelinq', 'This appears to be the data subject\'s own data. Redacting requires Art. 23 grounds.') }}
			</NcNoteCard>

			<label class="avg-redact__label">{{ t('pipelinq', 'Field path') }}</label>
			<NcTextField :model-value="veldpad" disabled @update:model-value="() => {}" />

			<label class="avg-redact__label">{{ t('pipelinq', 'Replacement') }}</label>
			<NcTextField :model-value="naWaarde"
				:placeholder="defaultReplacement"
				@update:model-value="naWaarde = $event" />

			<label class="avg-redact__label">{{ t('pipelinq', 'Reason') }}</label>
			<NcSelect v-model="grondOption"
				:input-label="t('pipelinq', 'Reason')"
				:options="grondOptions"
				label="label"
				:clearable="false" />
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="submitting" @click="confirm">
				{{ t('pipelinq', 'Redact') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { REDACTION_GROUNDS } from '../utils/avg/avgLabels.js'

export default {
	name: 'AvgRedactionDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	props: {
		/** The evidence item id being redacted. */
		bewijsItemId: {
			type: String,
			required: true,
		},
		/** The JSONPath being redacted. */
		veldpad: {
			type: String,
			required: true,
		},
		/** Whether the field looks like the subject's own data. */
		ownDataWarning: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			naWaarde: '',
			submitting: false,
			grondOption: { value: REDACTION_GROUNDS[0], label: REDACTION_GROUNDS[0] },
		}
	},
	computed: {
		/**
		 * The reason dropdown options.
		 *
		 * @return {Array<object>} The options.
		 */
		grondOptions() {
			return REDACTION_GROUNDS.map((g) => ({ value: g, label: g }))
		},
		/**
		 * The default replacement placeholder.
		 *
		 * @return {string} The placeholder.
		 */
		defaultReplacement() {
			return `[${this.t('pipelinq', 'redacted')}: ${this.grondOption.value}]`
		},
	},
	methods: {
		/**
		 * Emit the redaction payload for the parent to submit.
		 */
		confirm() {
			this.submitting = true
			this.$emit('confirm', {
				bewijsItemId: this.bewijsItemId,
				veldpad: this.veldpad,
				naWaarde: this.naWaarde,
				grond: this.grondOption.value,
			})
		},
	},
}
</script>

<style scoped>
.avg-redact { display: flex; flex-direction: column; gap: 8px; }
.avg-redact__label { font-weight: bold; }
</style>

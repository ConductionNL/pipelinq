<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - BrpDoelbindingModal collects the verzoekreden, doelbinding (wettelijke grondslag),
  - and an optional aanvullende toelichting before a BRP lookup may proceed.
  - Compliance: REQ-BSN-002 (lookup requires doelbinding).
  -
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/tasks.md#5.3
  - @spec openspec/changes/bsn-validatie-en-brp-lookup/specs.md#REQ-BSN-002
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Request purpose limitation')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<form class="brp-doelbinding" @submit.prevent="submit">
			<NcSelect
				v-model="form.verzoekreden"
				:options="reasonOptions"
				:input-label="t('pipelinq', 'Request reason')"
				:placeholder="t('pipelinq', 'Choose a request reason')"
				:reduce="(o) => o.value"
				label="label"
				required />
			<NcSelect
				v-model="form.doelbinding"
				:options="bindingOptions"
				:input-label="t('pipelinq', 'Purpose limitation / legal basis')"
				:placeholder="t('pipelinq', 'Choose a legal basis')"
				:reduce="(o) => o.value"
				label="label"
				required />
			<NcTextArea
				v-model="form.toelichting"
				:label="t('pipelinq', 'Additional notes')"
				:placeholder="t('pipelinq', 'Optional — at least 20 characters recommended')"
				rows="3" />
			<NcCheckboxRadioSwitch
				v-model="form.vogScreening"
				type="checkbox">
				{{ t('pipelinq', 'VOG-screening (extra Justis-vlag)') }}
			</NcCheckboxRadioSwitch>
		</form>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!valid" @click="submit">
				{{ t('pipelinq', 'Retrieve') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcDialog,
	NcSelect,
	NcTextArea,
	NcCheckboxRadioSwitch,
} from '@nextcloud/vue'

export default {
	name: 'BrpDoelbindingModal',
	components: {
		NcButton,
		NcDialog,
		NcSelect,
		NcTextArea,
		NcCheckboxRadioSwitch,
	},
	emits: ['close', 'submit'],
	data() {
		return {
			form: {
				verzoekreden: '',
				doelbinding: '',
				toelichting: '',
				vogScreening: false,
			},
		}
	},
	computed: {
		reasonOptions() {
			return [
				{ value: 'Behandeling AVG-inzageverzoek art. 15', label: this.t('pipelinq', 'Behandeling AVG-inzageverzoek art. 15') },
				{ value: 'Behandeling AVG-verwijderverzoek art. 17', label: this.t('pipelinq', 'Behandeling AVG-verwijderverzoek art. 17') },
				{ value: 'VOG-screening', label: this.t('pipelinq', 'VOG-screening') },
				{ value: 'Reguliere verzoekbehandeling', label: this.t('pipelinq', 'Regular request handling') },
				{ value: 'Overig', label: this.t('pipelinq', 'Other') },
			]
		},
		bindingOptions() {
			return [
				{ value: 'Publieke taak — Wet BRP art. 3.3', label: this.t('pipelinq', 'Publieke taak — Wet BRP art. 3.3') },
				{ value: 'AVG art. 6 lid 1 sub e', label: this.t('pipelinq', 'AVG art. 6 lid 1 sub e (publieke taak)') },
				{ value: 'Rechtmatig belang', label: this.t('pipelinq', 'Rechtmatig belang') },
				{ value: 'Overig', label: this.t('pipelinq', 'Other') },
			]
		},
		valid() {
			return Boolean(this.form.verzoekreden) && Boolean(this.form.doelbinding)
		},
	},
	methods: {
		submit() {
			if (!this.valid) return
			const reden = this.form.toelichting && this.form.toelichting.length >= 20
				? `${this.form.verzoekreden} — ${this.form.toelichting}`
				: this.form.verzoekreden
			this.$emit('submit', {
				verzoekreden: reden,
				doelbinding: this.form.doelbinding,
				basis: this.form.doelbinding,
				vogScreening: this.form.vogScreening,
			})
		},
	},
}
</script>

<style scoped>
.brp-doelbinding {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline, 8px);
	padding: var(--default-grid-baseline, 8px) 0;
}
</style>

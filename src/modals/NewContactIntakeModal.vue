<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - NewContactIntakeModal collects the minimal data needed to create a new
  - contact on the back of an unmatched CTI screen-pop.
  -
  - @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.2
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'New contact from incoming call')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<form class="cti-intake" @submit.prevent="submit">
			<NcTextField
				v-model="form.name"
				:label="t('pipelinq', 'Name')"
				:placeholder="t('pipelinq', 'Full name')"
				required />
			<NcTextField
				v-model="form.phone"
				:label="t('pipelinq', 'Phone')"
				:placeholder="t('pipelinq', 'Phone number')"
				required />
			<NcTextField
				v-model="form.email"
				:label="t('pipelinq', 'Email')"
				:placeholder="t('pipelinq', 'name@example.com')" />
			<NcTextField
				v-model="form.organisation"
				:label="t('pipelinq', 'Organisation')"
				:placeholder="t('pipelinq', 'Company / organisation')" />
			<NcTextArea
				v-model="form.notes"
				:label="t('pipelinq', 'Notes')"
				:placeholder="t('pipelinq', 'Why is this caller getting in touch?')"
				rows="3" />
		</form>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!valid" @click="submit">
				{{ t('pipelinq', 'Create contact') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcTextArea, NcTextField } from '@nextcloud/vue'

export default {
	name: 'NewContactIntakeModal',
	components: { NcButton, NcDialog, NcTextArea, NcTextField },
	props: {
		prefillPhone: { type: String, default: '' },
		e164: { type: String, default: '' },
	},
	emits: ['close', 'submit'],
	data() {
		return {
			form: {
				name: '',
				phone: this.e164 || this.prefillPhone,
				email: '',
				organisation: '',
				notes: '',
			},
		}
	},
	computed: {
		valid() {
			return this.form.name.trim().length > 1 && this.form.phone.trim().length > 1
		},
	},
	methods: {
		submit() {
			if (!this.valid) {
				return
			}
			this.$emit('submit', { ...this.form })
		},
	},
}
</script>

<style scoped>
.cti-intake {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
</style>

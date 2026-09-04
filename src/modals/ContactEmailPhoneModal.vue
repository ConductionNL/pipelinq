<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Add/edit dialog for ONE entry of a client/contact's typed `emails[]` or
  - `phones[]` array (contact-channel-details). A pure form: it emits the
  - built entry back to the caller (ContactChannelsSection), which splices it
  - into the array and persists the whole client/contact object — same
  - "modal owns no store access" shape as ProductVariantDialog.vue.
  -
  - @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md
  -->
<template>
	<NcDialog
		:name="isEdit ? editTitle : addTitle"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="contact-email-phone-modal">
			<NcSelect
				v-model="form.kind"
				inputId="channel-kind"
				:inputLabel="t('pipelinq', 'Kind')"
				:aria-label-combobox="t('pipelinq', 'Kind')"
				:options="kindOptions"
				:clearable="false" />
			<NcTextField
				:label="valueLabel"
				:modelValue="form.value"
				:type="channelType === 'email' ? 'email' : 'text'"
				:error="!!error"
				:helperText="error"
				data-testid="channel-value-input"
				@update:modelValue="
					(v) => {
						form.value = v
						error = ''
					}
				" />
			<NcCheckboxRadioSwitch v-model="form.primary" type="switch">
				{{ t('pipelinq', 'Primary') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.verified" type="switch">
				{{ t('pipelinq', 'Verified') }}
			</NcCheckboxRadioSwitch>
		</div>
		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="channel-modal-save"
				@click="submit">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

const EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
const PHONE_REGEX = /^[+]?[\d\s\-().]{7,20}$/

export default {
	name: 'ContactEmailPhoneModal',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
	},

	props: {
		/** The entry being edited, or null to add a new one. */
		entry: {
			type: Object,
			default: null,
		},

		/** Which array this entry belongs to. */
		channelType: {
			type: String,
			required: true,
			validator: (v) => ['email', 'phone'].includes(v),
		},
	},

	emits: ['close', 'save'],

	data() {
		return {
			kindOptions: ['work', 'private', 'mobile', 'whatsapp', 'other'],
			form: {
				kind: this.entry?.kind || 'work',
				value: this.entry?.value || '',
				primary: this.entry?.primary || false,
				verified: this.entry?.verified || false,
			},

			error: '',
		}
	},

	computed: {
		isEdit() {
			return !!this.entry
		},

		addTitle() {
			return this.channelType === 'email'
				? t('pipelinq', 'Add email address')
				: t('pipelinq', 'Add phone number')
		},

		editTitle() {
			return this.channelType === 'email'
				? t('pipelinq', 'Edit email address')
				: t('pipelinq', 'Edit phone number')
		},

		valueLabel() {
			return this.channelType === 'email'
				? t('pipelinq', 'Email address')
				: t('pipelinq', 'Phone number')
		},
	},

	methods: {
		/**
		 * Validate and emit the built entry; the parent owns persistence.
		 *
		 * @return {void}
		 * @spec openspec/changes/contact-channel-details/specs/contacts-sync/spec.md
		 */
		submit() {
			const value = (this.form.value || '').trim()
			if (!value) {
				this.error =
					this.channelType === 'email'
						? t('pipelinq', 'Email address is required')
						: t('pipelinq', 'Phone number is required')
				return
			}
			if (this.channelType === 'email' && !EMAIL_REGEX.test(value)) {
				this.error = t('pipelinq', 'Invalid email format')
				return
			}
			if (this.channelType === 'phone' && !PHONE_REGEX.test(value)) {
				this.error = t('pipelinq', 'Invalid phone format')
				return
			}

			this.$emit('save', {
				kind: this.form.kind,
				value,
				primary: !!this.form.primary,
				verified: !!this.form.verified,
			})
		},
	},
}
</script>

<style scoped>
.contact-email-phone-modal {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}
</style>

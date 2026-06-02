<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8
-->
<template>
	<NcDialog
		:name="isEdit ? t('pipelinq', 'Edit role') : t('pipelinq', 'New role')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="role-form">
			<NcTextField
				:value.sync="form.name"
				:label="t('pipelinq', 'Name')"
				:error="nameError"
				:helper-text="nameError ? t('pipelinq', 'Enter a name for the role') : ''" />

			<NcTextField
				:value.sync="form.description"
				:label="t('pipelinq', 'Description')" />

			<NcCheckboxRadioSwitch :checked.sync="form.canVoid">
				{{ t('pipelinq', 'May void transactions') }}
			</NcCheckboxRadioSwitch>

			<div class="role-form__discount">
				<label for="role-max-discount">{{ t('pipelinq', 'Maximum discount (%)') }}</label>
				<input
					id="role-max-discount"
					v-model.number="form.maxDiscountPercent"
					type="range"
					min="0"
					max="100"
					step="1">
				<span class="role-form__discount-value">{{ form.maxDiscountPercent }}%</span>
			</div>

			<NcCheckboxRadioSwitch :checked.sync="form.canRefund">
				{{ t('pipelinq', 'May process refunds') }}
			</NcCheckboxRadioSwitch>

			<NcCheckboxRadioSwitch :checked.sync="form.canNoSale">
				{{ t('pipelinq', 'May open the drawer without a sale (no-sale)') }}
			</NcCheckboxRadioSwitch>

			<NcNoteCard v-if="message" type="error">
				{{ message }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton type="primary" :disabled="busy" @click="save">
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcTextField, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'PosRoleFormDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},
	props: {
		role: {
			type: Object,
			default: null,
		},
	},
	emits: ['close', 'saved'],
	data() {
		return {
			busy: false,
			nameError: false,
			message: '',
			form: {
				name: this.role?.name || '',
				description: this.role?.description || '',
				canVoid: this.role?.canVoid === true,
				maxDiscountPercent: Number(this.role?.maxDiscountPercent || 0),
				canRefund: this.role?.canRefund === true,
				canNoSale: this.role?.canNoSale === true,
			},
		}
	},
	computed: {
		/**
		 * Whether this dialog is editing an existing role.
		 *
		 * @return {boolean} True for edit mode.
		 */
		isEdit() {
			return !!this.role?.id
		},
	},
	methods: {
		/**
		 * Validate and persist the role via the admin-gated endpoint.
		 */
		async save() {
			this.nameError = false
			this.message = ''
			if (this.form.name.trim() === '') {
				this.nameError = true
				return
			}

			this.busy = true
			try {
				const path = this.isEdit
					? `/apps/pipelinq/api/pos/roles/${this.role.id}`
					: '/apps/pipelinq/api/pos/roles'
				const response = await fetch(OC.generateUrl(path), {
					method: this.isEdit ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(this.form),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.message = data.error || t('pipelinq', 'Could not save the role.')
					return
				}
				showSuccess(t('pipelinq', 'Role saved.'))
				this.$emit('saved', data.role)
			} catch (e) {
				this.message = t('pipelinq', 'Could not save the role.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.role-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;

	&__discount {
		display: flex;
		align-items: center;
		gap: 12px;

		input[type='range'] {
			flex: 1;
		}
	}

	&__discount-value {
		min-width: 42px;
		text-align: right;
		font-weight: bold;
	}
}
</style>

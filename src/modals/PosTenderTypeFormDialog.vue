<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS tender type create / edit modal (pos-split-tender REQ-PST-001).
  -
  - @spec openspec/changes/pos-split-tender/tasks.md#8.2
  - @spec openspec/changes/pos-split-tender/tasks.md#8.3
  -->
<template>
	<NcDialog
		:name="
			isNew
				? t('pipelinq', 'New tender type')
				: t('pipelinq', 'Edit tender type')
		"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="pos-tender-type-form">
			<NcTextField
				v-model="form.name"
				:label="t('pipelinq', 'Name')"
				:placeholder="t('pipelinq', 'e.g. Contant, Betaalpas, Cadeaubon')"
				required />
			<NcTextField
				v-model="form.code"
				:disabled="!isNew"
				:label="t('pipelinq', 'Code')"
				:placeholder="t('pipelinq', 'CASH, CARD, VOUCHER, …')"
				:helperText="
					!isNew
						? t('pipelinq', 'Code is immutable after creation')
						: t(
								'pipelinq',
								'Machine-readable identifier (uppercase letters)',
							)
				"
				required />
			<NcTextField
				v-model="form.description"
				:label="t('pipelinq', 'Description')" />
			<NcTextField
				v-model="form.glAccount"
				:label="t('pipelinq', 'GL account')"
				:placeholder="
					t('pipelinq', 'e.g. 1100 (kas), 1200 (bank), 2100 (debiteuren)')
				"
				required />
			<NcCheckboxRadioSwitch v-model="form.requiresReference" type="switch">
				{{
					t(
						'pipelinq',
						'Requires external reference (card auth, voucher serial)',
					)
				}}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.requiresPin" type="switch">
				{{ t('pipelinq', 'Requires PIN entry on terminal') }}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.allowsChange" type="switch">
				{{
					t('pipelinq', 'Allows change calculation on overpayment (CASH)')
				}}
			</NcCheckboxRadioSwitch>
			<NcCheckboxRadioSwitch v-model="form.isActive" type="switch">
				{{ t('pipelinq', 'Active') }}
			</NcCheckboxRadioSwitch>
			<NcTextField
				v-model.number="form.sortOrder"
				type="number"
				:label="t('pipelinq', 'Sort order')" />
			<p v-if="errorMessage" class="pos-tender-type-form__error" role="alert">
				{{ errorMessage }}
			</p>
		</div>
		<template #actions>
			<NcButton :disabled="saving" @click="$emit('close')">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				:disabled="saving || !canSave"
				@click="submit">
				{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcDialog,
	NcTextField,
} from '@nextcloud/vue'

export default {
	name: 'PosTenderTypeFormDialog',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcDialog,
		NcTextField,
	},

	props: {
		tenderType: {
			type: Object,
			default: null,
		},
	},

	emits: ['close', 'saved'],
	data() {
		return {
			form: this.initialForm(),
			saving: false,
			errorMessage: '',
		}
	},

	computed: {
		isNew() {
			return !this.tenderType || !this.idOf(this.tenderType)
		},

		canSave() {
			return !!this.form.name && !!this.form.code && !!this.form.glAccount
		},
	},

	methods: {
		initialForm() {
			const src = this.tenderType || {}
			return {
				name: src.name || '',
				code: (src.code || '').toUpperCase(),
				description: src.description || '',
				glAccount: src.glAccount || '',
				requiresReference: !!src.requiresReference,
				requiresPin: !!src.requiresPin,
				allowsChange: !!src.allowsChange,
				isActive: src.isActive === undefined ? true : !!src.isActive,
				sortOrder: Number(src.sortOrder || 0),
			}
		},

		async submit() {
			this.saving = true
			this.errorMessage = ''
			try {
				const id = this.idOf(this.tenderType)
				const url = this.isNew
					? generateUrl('/apps/pipelinq/api/pos/tender-types')
					: generateUrl('/apps/pipelinq/api/pos/tender-types/{id}', { id })
				const method = this.isNew ? 'post' : 'put'
				const payload = {
					...this.form,
					code: (this.form.code || '').toUpperCase(),
				}
				await axios[method](url, payload)
				showSuccess(
					this.isNew
						? t('pipelinq', 'Tender type created')
						: t('pipelinq', 'Tender type updated'),
				)
				this.$emit('saved')
			} catch (error) {
				const msg =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to save tender type')
				this.errorMessage = msg
				showError(msg)
			} finally {
				this.saving = false
			}
		},

		idOf(type) {
			if (type?.['@self']?.id) {
				return type['@self'].id
			}
			return type?.id || type?.uuid || ''
		},
	},
}
</script>

<style scoped>
.pos-tender-type-form {
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 8px 0;
	min-width: 360px;
}

.pos-tender-type-form__error {
	color: var(--color-error);
	margin: 0;
}
</style>

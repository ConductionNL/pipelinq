<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS role admin form (create / edit).
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.2
  -->
<template>
	<div class="pos-role-form">
		<div class="pos-role-form__header">
			<NcButton @click="goBack">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2>
				{{
					isNew
						? t('pipelinq', 'New POS role')
						: form.name || t('pipelinq', 'POS role')
				}}
			</h2>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="pos-role-form__fields">
				<NcTextField
					v-model="form.name"
					:label="t('pipelinq', 'Name')"
					:placeholder="t('pipelinq', 'e.g. Supervisor')"
					required />
				<NcTextField
					v-model="form.description"
					:label="t('pipelinq', 'Description')" />
				<NcCheckboxRadioSwitch v-model="form.canVoid" type="switch">
					{{ t('pipelinq', 'May void transactions') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.canRefund" type="switch">
					{{ t('pipelinq', 'May process refunds') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch v-model="form.canNoSale" type="switch">
					{{ t('pipelinq', 'May open the cash drawer (no-sale)') }}
				</NcCheckboxRadioSwitch>
				<label class="pos-role-form__slider">
					<span
						>{{ t('pipelinq', 'Maximum discount %') }}:
						{{ form.maxDiscountPercent }}%</span
					>
					<input
						v-model.number="form.maxDiscountPercent"
						type="range"
						min="0"
						max="100"
						step="1"
						:aria-label="t('pipelinq', 'Maximum discount percentage')" />
				</label>
				<p v-if="errorMessage" class="pos-role-form__error" role="alert">
					{{ errorMessage }}
				</p>
			</div>

			<div class="pos-role-form__actions">
				<NcButton
					variant="primary"
					:disabled="saving || !canSave"
					@click="save">
					{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton
					v-if="!isNew"
					:disabled="saving"
					variant="error"
					@click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>
		</template>
		<ConfirmDialog
			v-if="showDeleteConfirm"
			:name="t('pipelinq', 'Delete role')"
			:message="
				t(
					'pipelinq',
					'Delete this role? Staff assignments must be removed first.',
				)
			"
			:confirmLabel="t('pipelinq', 'Delete')"
			@confirm="performDelete"
			@cancel="showDeleteConfirm = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcTextField,
} from '@nextcloud/vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'

export default {
	name: 'PosRoleForm',
	components: {
		ConfirmDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcTextField,
	},

	props: {
		id: { type: String, default: '' },
	},

	emits: ['done'],

	data() {
		return {
			form: {
				name: '',
				description: '',
				canVoid: false,
				maxDiscountPercent: 0,
				canRefund: false,
				canNoSale: false,
			},

			loading: false,
			saving: false,
			errorMessage: '',
			showDeleteConfirm: false,
		}
	},

	computed: {
		isNew() {
			return !this.id
		},

		canSave() {
			return (
				!!this.form.name
				&& this.form.maxDiscountPercent >= 0
				&& this.form.maxDiscountPercent <= 100
			)
		},
	},

	async mounted() {
		if (!this.isNew) {
			await this.load()
		}
	},

	methods: {
		async load() {
			this.loading = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/roles/{id}', {
					id: this.id,
				})
				const response = await axios.get(url)
				const role = response?.data?.role || {}
				this.form.name = role.name || ''
				this.form.description = role.description || ''
				this.form.canVoid = !!role.canVoid
				this.form.maxDiscountPercent = Number(role.maxDiscountPercent || 0)
				this.form.canRefund = !!role.canRefund
				this.form.canNoSale = !!role.canNoSale
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to load role')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.2
		 */
		async save() {
			this.saving = true
			this.errorMessage = ''
			try {
				const url = this.isNew
					? generateUrl('/apps/pipelinq/api/pos/roles')
					: generateUrl('/apps/pipelinq/api/pos/roles/{id}', {
							id: this.id,
						})
				const method = this.isNew ? 'post' : 'put'
				await axios[method](url, { ...this.form })
				this.$emit('done')
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to save role')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Open the delete confirmation.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.2
		 */
		confirmDelete() {
			this.showDeleteConfirm = true
		},

		/**
		 * Delete the role once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.2
		 */
		async performDelete() {
			this.showDeleteConfirm = false
			this.saving = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/roles/{id}', {
					id: this.id,
				})
				await axios.delete(url)
				this.$emit('done')
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to delete role')
			} finally {
				this.saving = false
			}
		},

		/**
		 * Leave the form. The host (PosRoleManager, on the admin page) closes the
		 * dialog — this form no longer routes back to a list page of its own.
		 *
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		goBack() {
			this.$emit('done')
		},
	},
}
</script>

<style scoped>
.pos-role-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.pos-role-form__header {
	display: flex;
	gap: 12px;
	align-items: center;
}

.pos-role-form__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.pos-role-form__slider {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.pos-role-form__actions {
	display: flex;
	gap: 8px;
}

.pos-role-form__error {
	color: var(--color-error);
}
</style>

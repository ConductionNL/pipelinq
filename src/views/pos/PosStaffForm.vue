<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS staff admin form (create / edit).
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.4
  -->
<template>
	<div class="pos-staff-form">
		<div class="pos-staff-form__header">
			<NcButton @click="goBack">
				{{ t('pipelinq', 'Back to list') }}
			</NcButton>
			<h2>{{ isNew ? t('pipelinq', 'New staff member') : (form.displayName || t('pipelinq', 'Staff member')) }}</h2>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<div class="pos-staff-form__fields">
				<NcTextField
					v-model="form.displayName"
					:label="t('pipelinq', 'Display name')"
					required />
				<NcTextField
					v-model="form.userId"
					:label="t('pipelinq', 'Linked Nextcloud user (optional)')"
					:placeholder="t('pipelinq', 'e.g. anna.devries')" />
				<NcSelect
					v-model="form.posRole"
					:options="roleOptions"
					:input-label="t('pipelinq', 'Role')"
					:placeholder="t('pipelinq', 'Choose a role')"
					:reduce="(o) => o.value"
					label="label" />
				<NcTextField
					v-model="form.pin"
					:label="isNew ? t('pipelinq', 'PIN (required, 4–6 digits)') : t('pipelinq', 'New PIN (optional, 4–6 digits)')"
					type="password"
					inputmode="numeric"
					maxlength="6"
					autocomplete="new-password" />
				<NcCheckboxRadioSwitch
					v-model="form.isActive"
					type="switch">
					{{ t('pipelinq', 'Active') }}
				</NcCheckboxRadioSwitch>
				<p v-if="errorMessage" class="pos-staff-form__error" role="alert">
					{{ errorMessage }}
				</p>
			</div>

			<div class="pos-staff-form__actions">
				<NcButton variant="primary" :disabled="saving || !canSave" @click="save">
					{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save') }}
				</NcButton>
				<NcButton v-if="!isNew"
					:disabled="saving"
					variant="error"
					@click="confirmDelete">
					{{ t('pipelinq', 'Delete') }}
				</NcButton>
			</div>
		</template>
		<ConfirmDialog v-if="showDeleteConfirm"
			:name="t('pipelinq', 'Delete staff member')"
			:message="t('pipelinq', 'Delete this staff member? This cannot be undone.')"
			:confirm-label="t('pipelinq', 'Delete')"
			@confirm="performDelete"
			@cancel="showDeleteConfirm = false" />
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'

export default {
	name: 'PosStaffForm',
	components: { ConfirmDialog, NcButton, NcCheckboxRadioSwitch, NcLoadingIcon, NcSelect, NcTextField },
	props: {
		id: { type: String, default: '' },
	},
	data() {
		return {
			form: {
				displayName: '',
				userId: '',
				posRole: '',
				pin: '',
				isActive: true,
			},
			roles: [],
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
		roleOptions() {
			return this.roles.map((r) => ({ value: r.id, label: r.name || r.id }))
		},
		canSave() {
			if (!this.form.displayName || !this.form.posRole) {
				return false
			}
			if (this.isNew && !/^\d{4,6}$/.test(this.form.pin)) {
				return false
			}
			if (!this.isNew && this.form.pin !== '' && !/^\d{4,6}$/.test(this.form.pin)) {
				return false
			}
			return true
		},
	},
	async mounted() {
		await this.loadRoles()
		if (!this.isNew) {
			await this.load()
		}
	},
	methods: {
		async loadRoles() {
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/roles')
				const response = await axios.get(url)
				this.roles = response?.data?.roles || []
			} catch (error) {
				this.roles = []
			}
		},
		async load() {
			this.loading = true
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/staff/{id}', { id: this.id })
				const response = await axios.get(url)
				const staff = response?.data?.staff || {}
				this.form.displayName = staff.displayName || ''
				this.form.userId = staff.userId || ''
				this.form.posRole = staff.posRole || ''
				this.form.isActive = staff.isActive !== false
				this.form.pin = ''
			} catch (error) {
				this.errorMessage = error?.response?.data?.error || t('pipelinq', 'Failed to load staff')
			} finally {
				this.loading = false
			}
		},
		async save() {
			this.saving = true
			this.errorMessage = ''
			try {
				const url = this.isNew
					? generateUrl('/apps/pipelinq/api/pos/staff')
					: generateUrl('/apps/pipelinq/api/pos/staff/{id}', { id: this.id })
				const method = this.isNew ? 'post' : 'put'
				const payload = {
					displayName: this.form.displayName,
					userId: this.form.userId,
					posRole: this.form.posRole,
					isActive: this.form.isActive,
				}
				if (this.form.pin) {
					payload.pin = this.form.pin
				}
				await axios[method](url, payload)
				this.$emit('done')
			} catch (error) {
				this.errorMessage = error?.response?.data?.error || t('pipelinq', 'Failed to save staff')
			} finally {
				this.saving = false
			}
		},
		/**
		 * Open the delete confirmation.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.4
		 */
		confirmDelete() {
			this.showDeleteConfirm = true
		},
		/**
		 * Delete the staff member once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.4
		 */
		async performDelete() {
			this.showDeleteConfirm = false
			this.saving = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/staff/{id}', { id: this.id })
				await axios.delete(url)
				this.$emit('done')
			} catch (error) {
				this.errorMessage = error?.response?.data?.error || t('pipelinq', 'Failed to delete staff')
			} finally {
				this.saving = false
			}
		},
		/**
		 * Leave the form. The host (PosStaffManager, on the admin page) closes the
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
.pos-staff-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.pos-staff-form__header {
	display: flex;
	gap: 12px;
	align-items: center;
}

.pos-staff-form__fields {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 480px;
}

.pos-staff-form__actions {
	display: flex;
	gap: 8px;
}

.pos-staff-form__error {
	color: var(--color-error);
}
</style>

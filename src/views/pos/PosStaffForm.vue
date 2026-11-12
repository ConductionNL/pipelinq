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
			<h2>
				{{
					isNew
						? t('pipelinq', 'New staff member')
						: form.displayName || t('pipelinq', 'Staff member')
				}}
			</h2>
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
					:inputLabel="t('pipelinq', 'Role')"
					:placeholder="t('pipelinq', 'Choose a role')"
					:reduce="(o) => o.value"
					label="label" />
				<NcTextField
					v-model="form.pin"
					:label="
						isNew
							? t('pipelinq', 'PIN (required, 4–6 digits)')
							: t('pipelinq', 'New PIN (optional, 4–6 digits)')
					"
					type="password"
					inputmode="numeric"
					maxlength="6"
					autocomplete="new-password" />
				<NcCheckboxRadioSwitch v-model="form.isActive" type="switch">
					{{ t('pipelinq', 'Active') }}
				</NcCheckboxRadioSwitch>
				<p v-if="errorMessage" class="pos-staff-form__error" role="alert">
					{{ errorMessage }}
				</p>
			</div>

			<div class="pos-staff-form__actions">
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
			:name="t('pipelinq', 'Delete staff member')"
			:message="
				t('pipelinq', 'Delete this staff member? This cannot be undone.')
			"
			:confirmLabel="t('pipelinq', 'Delete')"
			@confirm="performDelete"
			@cancel="showDeleteConfirm = false" />
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import ConfirmDialog from '../../dialogs/ConfirmDialog.vue'
import { fetchPosRoles } from '../../services/posRolesApi.js'
import posRecordFormMixin from './posRecordFormMixin.js'

export default {
	name: 'PosStaffForm',
	components: {
		ConfirmDialog,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
	},

	mixins: [posRecordFormMixin],

	emits: ['done'],

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
			recordPath: 'staff',
		}
	},

	computed: {
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
			if (
				!this.isNew
				&& this.form.pin !== ''
				&& !/^\d{4,6}$/.test(this.form.pin)
			) {
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
		/**
		 * @spec exclude POS roles have no owning requirement, see PinLoginModal::loadStaff
		 */
		async loadRoles() {
			this.roles = await fetchPosRoles()
		},

		async load() {
			await this.loadRecord({
				read: (data) => this.readStaff(data.staff || {}),
				failure: t('pipelinq', 'Failed to load staff'),
			})
		},

		/**
		 * @param {object} staff The stored staff member; the PIN is never read back.
		 * @return {void}
		 */
		readStaff(staff) {
			this.form.displayName = staff.displayName || ''
			this.form.userId = staff.userId || ''
			this.form.posRole = staff.posRole || ''
			this.form.isActive = staff.isActive !== false
			this.form.pin = ''
		},

		/**
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.4
		 */
		async save() {
			const payload = {
				displayName: this.form.displayName,
				userId: this.form.userId,
				posRole: this.form.posRole,
				isActive: this.form.isActive,
			}
			if (this.form.pin) {
				payload.pin = this.form.pin
			}
			await this.saveRecord({
				payload,
				failure: t('pipelinq', 'Failed to save staff'),
			})
		},

		/**
		 * Delete the staff member once the dialog confirms.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.4
		 */
		async performDelete() {
			await this.deleteRecord(t('pipelinq', 'Failed to delete staff'))
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

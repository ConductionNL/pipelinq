<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8
-->
<template>
	<NcDialog
		:name="isEdit ? t('pipelinq', 'Edit staff member') : t('pipelinq', 'New staff member')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="staff-form">
			<NcTextField
				:value.sync="form.displayName"
				:label="t('pipelinq', 'Name')"
				:error="nameError"
				:helper-text="nameError ? t('pipelinq', 'Enter a name for the staff member') : ''" />

			<NcTextField
				:value.sync="form.userId"
				:label="t('pipelinq', 'Nextcloud user (optional)')" />

			<NcSelect
				v-model="selectedRole"
				:options="roleOptions"
				:input-label="t('pipelinq', 'Role')"
				:placeholder="t('pipelinq', 'Select a role')"
				:loading="loadingRoles"
				label="name"
				:clearable="false" />

			<NcTextField
				:value.sync="form.pin"
				type="password"
				inputmode="numeric"
				:label="isEdit ? t('pipelinq', 'PIN (leave blank to keep current)') : t('pipelinq', 'PIN (4-6 digits)')"
				:error="pinError"
				:helper-text="pinError ? t('pipelinq', 'PIN must be 4 to 6 digits') : ''" />

			<NcCheckboxRadioSwitch :checked.sync="form.isActive">
				{{ t('pipelinq', 'Active') }}
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
import { NcDialog, NcButton, NcTextField, NcSelect, NcCheckboxRadioSwitch, NcNoteCard } from '@nextcloud/vue'
import { showSuccess } from '@nextcloud/dialogs'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosStaffFormDialog',
	components: {
		NcDialog,
		NcButton,
		NcTextField,
		NcSelect,
		NcCheckboxRadioSwitch,
		NcNoteCard,
	},
	props: {
		staff: {
			type: Object,
			default: null,
		},
	},
	emits: ['close', 'saved'],
	setup() {
		return { objectStore: useObjectStore() }
	},
	data() {
		return {
			busy: false,
			loadingRoles: false,
			nameError: false,
			pinError: false,
			message: '',
			roleOptions: [],
			selectedRole: null,
			form: {
				displayName: this.staff?.displayName || '',
				userId: this.staff?.userId || '',
				posRole: this.staff?.posRole || '',
				pin: '',
				isActive: this.staff ? this.staff.isActive !== false : true,
			},
		}
	},
	computed: {
		/**
		 * Whether this dialog is editing an existing staff member.
		 *
		 * @return {boolean} True for edit mode.
		 */
		isEdit() {
			return !!this.staff?.id
		},
	},
	mounted() {
		this.loadRoles()
	},
	methods: {
		/**
		 * Load the available roles for the picker.
		 */
		async loadRoles() {
			this.loadingRoles = true
			try {
				await this.objectStore.fetchCollection('posRole', { _limit: 200 })
				const rows = this.objectStore.getCollection('posRole')?.results || []
				this.roleOptions = rows.map(r => ({ id: r.id, name: r.name }))
				this.selectedRole = this.roleOptions.find(r => r.id === this.form.posRole) || null
			} catch (e) {
				this.message = t('pipelinq', 'Could not load roles.')
			} finally {
				this.loadingRoles = false
			}
		},
		/**
		 * Validate and persist the staff member via the admin-gated endpoint.
		 *
		 * The PIN is sent only when set; the server hashes it and never returns
		 * the hash.
		 */
		async save() {
			this.nameError = false
			this.pinError = false
			this.message = ''

			if (this.form.displayName.trim() === '') {
				this.nameError = true
				return
			}
			if (this.form.pin !== '' && !/^[0-9]{4,6}$/.test(this.form.pin)) {
				this.pinError = true
				return
			}
			if (!this.isEdit && this.form.pin === '') {
				this.pinError = true
				return
			}

			this.form.posRole = this.selectedRole?.id || ''
			if (this.form.posRole === '') {
				this.message = t('pipelinq', 'Select a role for the staff member.')
				return
			}

			this.busy = true
			try {
				const path = this.isEdit
					? `/apps/pipelinq/api/pos/staff/${this.staff.id}`
					: '/apps/pipelinq/api/pos/staff'
				const body = { ...this.form }
				if (body.pin === '') {
					delete body.pin
				}
				const response = await fetch(OC.generateUrl(path), {
					method: this.isEdit ? 'PUT' : 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(body),
				})
				const data = await response.json().catch(() => ({}))
				if (!response.ok) {
					this.message = data.error || t('pipelinq', 'Could not save the staff member.')
					return
				}
				showSuccess(t('pipelinq', 'Staff member saved.'))
				this.$emit('saved', data.staff)
			} catch (e) {
				this.message = t('pipelinq', 'Could not save the staff member.')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
.staff-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 0;
}
</style>

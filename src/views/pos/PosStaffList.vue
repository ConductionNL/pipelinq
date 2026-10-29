<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS staff admin list (pos-staff-pin-permissions REQ-PSP-002).
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
  -->
<template>
	<div class="pos-staff-list">
		<!-- No heading or add button of its own: the host section
		     (PosStaffManager) names this surface and adds a member from its title
		     row, as the board draws it. -->

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<p v-if="errorMessage" class="pos-staff-list__error" role="alert">
				{{ errorMessage }}
			</p>
			<!-- One row per staff member, with a sort and a filter in every head,
			     as the board draws it. -->
			<CnDataTable
				v-if="staff.length"
				class="pos-staff-list__table"
				data-testid="pos-staff"
				:columns="columns"
				:schema="tableSchema"
				:rows="tableRows"
				:rowClass="() => 'pos-staff-list__row'"
				filterable
				localHeaders>
				<template #column-isActive="{ row }">
					{{ row.isActive ? t('pipelinq', 'Yes') : t('pipelinq', 'No') }}
				</template>
				<template #column-lastLoginAt="{ row }">
					{{ loginMoment(row.lastLoginAt) }}
				</template>
				<template #actions-header>
					<span class="hidden-visually">{{ t('pipelinq', 'Actions') }}</span>
				</template>
				<template #row-actions="{ row }">
					<NcButton @click="edit(row)">
						{{ t('pipelinq', 'Edit') }}
					</NcButton>
				</template>
			</CnDataTable>
			<p v-else>
				{{ t('pipelinq', 'No staff members defined yet.') }}
			</p>
			<p class="pos-staff-list__note">
				{{ t('pipelinq', 'Every staff member signs in at the till with a PIN of 4 to 6 digits.') }}
			</p>
		</template>
	</div>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'PosStaffList',
	components: { CnDataTable, NcButton, NcLoadingIcon },
	emits: ['create', 'edit'],

	data() {
		return {
			staff: [],
			roles: [],
			loading: false,
			errorMessage: '',
		}
	},

	computed: {
		/**
		 * The table's columns, every one sortable and filterable from its head.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
		 */
		columns() {
			return [
				{ key: 'displayName', label: t('pipelinq', 'Display name') },
				{ key: 'role', label: t('pipelinq', 'Role') },
				{ key: 'isActive', label: t('pipelinq', 'Active') },
				{ key: 'lastLoginAt', label: t('pipelinq', 'Last login') },
			]
		},

		/**
		 * The fields the heads sort and filter on.
		 *
		 * @return {object}
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
		 */
		tableSchema() {
			return {
				properties: {
					displayName: { type: 'string' },
					role: { type: 'string' },
					isActive: { type: 'boolean' },
					lastLoginAt: { type: 'string', format: 'date-time' },
				},
			}
		},

		/**
		 * One row per staff member: the role by name and the stored login
		 * moment, so the head sorts by time; the cell reads it as a moment.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
		 */
		tableRows() {
			return this.staff.map((member) => ({
				id: member.id,
				displayName: member.displayName,
				role: this.roleName(member.posRole),
				isActive: member.isActive !== false,
				lastLoginAt: member.lastLoginAt || null,
			}))
		},
	},

	async mounted() {
		await Promise.all([this.loadStaff(), this.loadRoles()])
	},

	methods: {
		async loadStaff() {
			this.loading = true
			this.errorMessage = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/staff')
				const response = await axios.get(url)
				this.staff = response?.data?.staff || []
			} catch (error) {
				this.errorMessage =
					error?.response?.data?.error
					|| t('pipelinq', 'Failed to load staff')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @spec exclude POS roles have no owning requirement, see PinLoginModal::loadStaff
		 */
		async loadRoles() {
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/roles')
				const response = await axios.get(url)
				this.roles = response?.data?.roles || []
			} catch {
				this.roles = []
			}
		},

		/**
		 * The last login as a moment in the reader's language.
		 *
		 * @param {string} iso The stored moment, or empty.
		 * @return {string} Day, month and time, or a dash.
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.3
		 */
		loginMoment(iso) {
			if (!iso) {
				return '—'
			}
			const date = new Date(iso)
			if (Number.isNaN(date.getTime())) {
				return String(iso)
			}
			return date.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		},

		roleName(id) {
			const role = this.roles.find((r) => r.id === id)
			return role?.name || id || '—'
		},

		/**
		 * Ask the host to open this row for editing.
		 *
		 * POS staff is admin master-data, so this list now lives on the Nextcloud
		 * admin page (nav-ia-cleanup), which is its own webpack entry with no
		 * vue-router. It therefore emits instead of routing to a detail page, and
		 * PosStaffManager opens the form in a dialog.
		 *
		 * @param {object} row The staff row.
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		edit(row) {
			this.$emit('edit', row.id)
		},

		/**
		 * Ask the host to open an empty form.
		 *
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		createNew() {
			this.$emit('create')
		},
	},
}
</script>

<style scoped>
.pos-staff-list__note {
	margin: 12px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.pos-staff-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.pos-staff-list__error {
	color: var(--color-error);
}
</style>

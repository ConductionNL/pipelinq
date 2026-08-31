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
		<!-- No heading of its own: the host NcSettingsSection (PosStaffManager, on
		     the admin page) already names this surface, and a second "POS staff"
		     title would just repeat it. -->
		<div class="pos-staff-list__header">
			<NcButton variant="primary" @click="createNew">
				{{ t('pipelinq', 'New staff member') }}
			</NcButton>
		</div>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<p v-if="errorMessage" class="pos-staff-list__error" role="alert">
				{{ errorMessage }}
			</p>
			<table v-if="staff.length" class="pos-staff-list__table">
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Display name') }}</th>
						<th scope="col">{{ t('pipelinq', 'Role') }}</th>
						<th scope="col">{{ t('pipelinq', 'Active') }}</th>
						<th scope="col">{{ t('pipelinq', 'Last login') }}</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in staff" :key="row.id">
						<td>{{ row.displayName }}</td>
						<td>{{ roleName(row.posRole) }}</td>
						<td>
							{{
								row.isActive === false
									? t('pipelinq', 'No')
									: t('pipelinq', 'Yes')
							}}
						</td>
						<td>{{ row.lastLoginAt || '—' }}</td>
						<td>
							<NcButton @click="edit(row)">
								{{ t('pipelinq', 'Edit') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else>
				{{ t('pipelinq', 'No staff members defined yet.') }}
			</p>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

export default {
	name: 'PosStaffList',
	components: { NcButton, NcLoadingIcon },
	emits: ['create', 'edit'],

	data() {
		return {
			staff: [],
			roles: [],
			loading: false,
			errorMessage: '',
		}
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

		async loadRoles() {
			try {
				const url = generateUrl('/apps/pipelinq/api/pos/roles')
				const response = await axios.get(url)
				this.roles = response?.data?.roles || []
			} catch (error) {
				this.roles = []
			}
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
.pos-staff-list {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
}

.pos-staff-list__header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.pos-staff-list__table {
	width: 100%;
	border-collapse: collapse;
}

.pos-staff-list__table th,
.pos-staff-list__table td {
	padding: 8px;
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.pos-staff-list__error {
	color: var(--color-error);
}
</style>

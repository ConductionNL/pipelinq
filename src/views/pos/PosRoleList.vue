<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - POS role admin list (pos-staff-pin-permissions REQ-PSP-001).
  -
  - @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.1
  -->
<template>
	<div class="pos-role-list">
		<!-- A table of the rights, as the board draws it (one row per role); the
		     host section (PosRoleManager) adds a role from its title row. -->
		<NcLoadingIcon v-if="loading && !objects.length" :size="32" />
		<table v-else-if="objects.length" class="pos-role-list__table">
			<thead>
				<tr>
					<th scope="col">{{ t('pipelinq', 'Role') }}</th>
					<th scope="col">{{ t('pipelinq', 'May cancel') }}</th>
					<th scope="col">{{ t('pipelinq', 'May refund') }}</th>
					<th scope="col">{{ t('pipelinq', 'May open the drawer') }}</th>
					<th scope="col">{{ t('pipelinq', 'Maximum discount') }}</th>
					<th scope="col"><span class="hidden-visually">{{ t('pipelinq', 'Actions') }}</span></th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="role in objects" :key="role.id" data-testid="pos-role">
					<td>{{ role.name }}</td>
					<td>{{ yesNo(role.canVoid) }}</td>
					<td>{{ yesNo(role.canRefund) }}</td>
					<td>{{ yesNo(role.canNoSale) }}</td>
					<td>{{ (Number(role.maxDiscountPercent) || 0) + '%' }}</td>
					<td>
						<NcButton @click="openRole(role)">
							{{ t('pipelinq', 'Edit') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
		<p v-else class="pos-role-list__empty">
			{{ t('pipelinq', 'No POS roles defined yet') }}
		</p>
	</div>
</template>

<script>
import { useListView } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { inject } from 'vue'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'PosRoleList',
	components: { NcButton, NcLoadingIcon },
	emits: ['create', 'edit'],

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('posRole', { sidebarState, objectStore })
	},

	methods: {
		/**
		 * A right as the table reads it.
		 *
		 * @param {boolean} value Whether the role has the right.
		 * @return {string} "Yes" or "No".
		 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.1
		 */
		yesNo(value) {
			return value === true ? t('pipelinq', 'Yes') : t('pipelinq', 'No')
		},

		/**
		 * Ask the host to open this role for editing.
		 *
		 * POS roles are admin master-data, so this list now lives on the Nextcloud
		 * admin page (nav-ia-cleanup), which is its own webpack entry with no
		 * vue-router. It therefore emits instead of routing to a detail page, and
		 * PosRoleManager opens the form in a dialog.
		 *
		 * @param {object} row The role row.
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openRole(row) {
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
/* Cells take the board look's settings-table rhythm; the table only spans
   the card. */
.pos-role-list__table {
	width: 100%;
	border-collapse: collapse;
}

.pos-role-list__empty {
	color: var(--color-text-maxcontrast);
}
</style>

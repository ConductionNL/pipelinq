<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PosRoleManager — the POS roles section of the Nextcloud admin page.
  -
  - A POS role is a permission set (may void, may refund, may open the drawer …),
  - so it is administrator master-data rather than an operator surface, and no
  - longer sits in the app navigation (nav-ia-cleanup). It lives on
  - /settings/admin/pipelinq, next to POS staff, which references it.
  -
  - The admin page is its own webpack entry with no vue-router, so the list emits
  - instead of routing to a detail page and the form opens in a dialog.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'POS roles')"
		:description="
			t(
				'pipelinq',
				'Permission sets a cashier can hold at the register — voiding, refunds, opening the cash drawer.',
			)
		">
		<PosRoleList :key="reloadKey" @edit="openRole" @create="openNew" />

		<PosRoleFormDialog
			v-if="dialogOpen"
			:roleId="editingId"
			@done="closeDialog" />
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'
import PosRoleFormDialog from '../../dialogs/PosRoleFormDialog.vue'
import PosRoleList from '../pos/PosRoleList.vue'

export default {
	name: 'PosRoleManager',
	components: {
		NcSettingsSection,
		PosRoleList,
		PosRoleFormDialog,
	},

	data() {
		return {
			dialogOpen: false,
			editingId: '',
			// Bumped on close so the list refetches and shows the saved row.
			reloadKey: 0,
		}
	},

	methods: {
		/**
		 * Open an existing role in the dialog.
		 *
		 * @param {string} id The role uuid.
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openRole(id) {
			this.editingId = id
			this.dialogOpen = true
		},

		/**
		 * Open an empty role form.
		 *
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openNew() {
			this.editingId = ''
			this.dialogOpen = true
		},

		/**
		 * Close the dialog and re-render the list so a save or delete shows up.
		 *
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		closeDialog() {
			this.dialogOpen = false
			this.editingId = ''
			this.reloadKey++
		},
	},
}
</script>

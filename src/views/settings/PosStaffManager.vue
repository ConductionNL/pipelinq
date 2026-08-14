<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PosStaffManager — the POS staff section of the Nextcloud admin page.
  -
  - POS staff (cashiers, their PINs and roles) is administrator master-data, not
  - something an operator works from day to day, so it no longer sits in the app
  - navigation (nav-ia-cleanup). It lives on /settings/admin/pipelinq, where an
  - administrator already goes to configure the app and where Nextcloud's own
  - admin delegation applies.
  -
  - The admin page is its own webpack entry with no vue-router, so the list emits
  - instead of routing to a detail page and the form opens in a dialog.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'POS staff')"
		:description="
			t(
				'pipelinq',
				'Cashiers who can sign in at the register, and the POS role each one holds.',
			)
		">
		<PosStaffList :key="reloadKey" @edit="openStaff" @create="openNew" />

		<PosStaffFormDialog
			v-if="dialogOpen"
			:staff-id="editingId"
			@done="closeDialog" />
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'
import PosStaffList from '../pos/PosStaffList.vue'
import PosStaffFormDialog from '../../dialogs/PosStaffFormDialog.vue'

export default {
	name: 'PosStaffManager',
	components: {
		NcSettingsSection,
		PosStaffList,
		PosStaffFormDialog,
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
		 * Open an existing staff member in the dialog.
		 *
		 * @param {string} id The staff uuid.
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		openStaff(id) {
			this.editingId = id
			this.dialogOpen = true
		},
		/**
		 * Open an empty staff form.
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

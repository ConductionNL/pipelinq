<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PosStaffFormDialog wraps the POS staff form for the Nextcloud admin page.
  -
  - POS staff is admin master-data, so it moved off the app nav onto
  - /settings/admin/pipelinq (nav-ia-cleanup). That page is its own webpack entry
  - with no vue-router, so the old list -> /pos/staff/:id detail route cannot work
  - there. The form is shown in a dialog instead; it lives in its own file because
  - a modal must never be written inline inside its parent (ADR-004).
  -->
<template>
	<NcDialog :name="dialogTitle" size="normal" @closing="$emit('done')">
		<PosStaffForm :id="staffId" @done="$emit('done')" />
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import PosStaffForm from '../views/pos/PosStaffForm.vue'

export default {
	name: 'PosStaffFormDialog',
	components: {
		NcDialog,
		PosStaffForm,
	},

	props: {
		/**
		 * The staff member to edit, or '' to create a new one.
		 */
		staffId: {
			type: String,
			default: '',
		},
	},

	computed: {
		/**
		 * @return {string} Dialog heading, reflecting create vs edit.
		 * @spec openspec/changes/nav-ia-cleanup/specs/nav-ia-cleanup/spec.md#requirement-admin-configuration-lives-on-the-admin-page
		 */
		dialogTitle() {
			return this.staffId
				? t('pipelinq', 'Edit POS staff member')
				: t('pipelinq', 'New POS staff member')
		},
	},
}
</script>

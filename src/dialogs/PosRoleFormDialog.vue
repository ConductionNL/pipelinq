<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - PosRoleFormDialog wraps the POS role form for the Nextcloud admin page.
  -
  - POS roles are admin master-data, so they moved off the app nav onto
  - /settings/admin/pipelinq (nav-ia-cleanup). That page is its own webpack entry
  - with no vue-router, so the old list -> /pos/roles/:id detail route cannot work
  - there. The form is shown in a dialog instead; it lives in its own file because
  - a modal must never be written inline inside its parent (ADR-004).
  -->
<template>
	<NcDialog :name="dialogTitle"
		size="normal"
		@closing="$emit('done')">
		<PosRoleForm :id="roleId" @done="$emit('done')" />
	</NcDialog>
</template>

<script>
import { NcDialog } from '@nextcloud/vue'
import PosRoleForm from '../views/pos/PosRoleForm.vue'

export default {
	name: 'PosRoleFormDialog',
	components: {
		NcDialog,
		PosRoleForm,
	},
	props: {
		/**
		 * The role to edit, or '' to create a new one.
		 */
		roleId: {
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
			return this.roleId
				? t('pipelinq', 'Edit POS role')
				: t('pipelinq', 'New POS role')
		},
	},
}
</script>

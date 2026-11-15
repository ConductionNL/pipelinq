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
	<CnSettingsSection
		:name="t('pipelinq', 'Till roles')"
		:description="
			t(
				'pipelinq',
				'Rights a till employee can have: cancelling, refunding, opening the cash drawer.',
			)
		">
		<!-- Adding one is the section's title-row button, as the board draws it. -->
		<template #actions>
			<NcButton class="cn-button--tonal" variant="secondary" @click="openNew">
				<template #icon>
					<Plus :size="16" />
				</template>
				{{ t('pipelinq', 'New role') }}
			</NcButton>
		</template>
		<PosRoleList :key="reloadKey" @edit="openRecord" @create="openNew" />

		<PosRoleFormDialog
			v-if="dialogOpen"
			:roleId="editingId"
			@done="closeDialog" />
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PosRoleFormDialog from '../../dialogs/PosRoleFormDialog.vue'
import PosRoleList from '../pos/PosRoleList.vue'
import recordDialogMixin from '../../mixins/recordDialogMixin.js'

export default {
	name: 'PosRoleManager',
	components: {
		CnSettingsSection,
		NcButton,
		Plus,
		PosRoleList,
		PosRoleFormDialog,
	},

	mixins: [recordDialogMixin],
}
</script>

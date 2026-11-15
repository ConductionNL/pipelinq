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
	<CnSettingsSection
		:name="t('pipelinq', 'Till staff')"
		:description="
			t(
				'pipelinq',
				'Till staff who can sign in at the till, and the till role of each.',
			)
		">
		<!-- Adding one is the section's title-row button, as the board draws it. -->
		<template #actions>
			<NcButton class="cn-button--tonal" variant="secondary" @click="openNew">
				<template #icon>
					<Plus :size="16" />
				</template>
				{{ t('pipelinq', 'New staff member') }}
			</NcButton>
		</template>
		<PosStaffList :key="reloadKey" @edit="openRecord" @create="openNew" />

		<PosStaffFormDialog
			v-if="dialogOpen"
			:staffId="editingId"
			@done="closeDialog" />
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import PosStaffFormDialog from '../../dialogs/PosStaffFormDialog.vue'
import PosStaffList from '../pos/PosStaffList.vue'
import recordDialogMixin from '../../mixins/recordDialogMixin.js'

export default {
	name: 'PosStaffManager',
	components: {
		CnSettingsSection,
		NcButton,
		Plus,
		PosStaffList,
		PosStaffFormDialog,
	},

	mixins: [recordDialogMixin],
}
</script>

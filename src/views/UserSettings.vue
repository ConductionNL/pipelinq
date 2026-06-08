<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Pipelinq per-user settings dialog. Hosts the leaf-first
  - email-matching SyncSettingsSection (and any future per-user
  - sections). Rendered as an NcAppSettingsDialog — NOT a routed
  - page, per ADR-004 (no admin-visible Vue route).
  -
  - @spec openspec/changes/email-calendar-sync/specs/email-calendar-sync/spec.md#req-per-user-matching-settings
  -->
<template>
	<NcAppSettingsDialog
		:open="open"
		:show-navigation="true"
		:name="t('pipelinq', 'Pipelinq settings')"
		@update:open="onUpdateOpen">
		<NcAppSettingsSection
			id="sync-email"
			:name="t('pipelinq', 'Email matching')">
			<SyncSettingsSection />
		</NcAppSettingsSection>
	</NcAppSettingsDialog>
</template>

<script>
import { NcAppSettingsDialog, NcAppSettingsSection } from '@conduction/nextcloud-vue'
import SyncSettingsSection from '../components/sync/SyncSettingsSection.vue'

export default {
	name: 'UserSettings',
	components: {
		NcAppSettingsDialog,
		NcAppSettingsSection,
		SyncSettingsSection,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['update:open'],
	methods: {
		/**
		 * Forward the open-state change up so the parent can close the dialog.
		 *
		 * @param {boolean} value The new open state.
		 */
		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},
	},
}
</script>

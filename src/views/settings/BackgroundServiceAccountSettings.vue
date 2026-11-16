<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - BackgroundServiceAccountSettings lets an admin pick the account Pipelinq's
  - background work writes as. Today that work is the call log: each incoming
  - call a telephony connection reports is kept for 30 days. Until an account
  - is picked, nothing is logged.
  -
  - @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
  -->
<template>
	<NcSettingsSection
		id="section-background-service-account"
		:name="t('pipelinq', 'Background work')"
		:description="
			t(
				'pipelinq',
				'Some work runs with nobody signed in, such as logging an incoming call. Pipelinq saves it as the account below.',
			)
		">
		<ServiceAccountPicker
			:url="serviceAccountUrl"
			inputId="background-service-account"
			:legend="t('pipelinq', 'Background service account')"
			:help="
				(group) =>
					t(
						'pipelinq',
						'Pipelinq logs each incoming call for 30 days, saved as this account. It joins the group {group}, which may only create, read and remove those call logs.',
						{ group },
					)
			"
			:consequence="
				t('pipelinq', 'Until you choose one, incoming calls are not logged.')
			"
			:savedMessage="
				(user) =>
					t('pipelinq', 'Incoming calls are now logged as {user}.', {
						user,
					})
			"
			:loadError="
				t('pipelinq', 'Could not load the background service account.')
			"
			:saveError="
				t('pipelinq', 'Could not save the background service account.')
			"
			defaultGroup="pipelinq-background-service" />
	</NcSettingsSection>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcSettingsSection } from '@nextcloud/vue'
import ServiceAccountPicker from '../../components/admin/ServiceAccountPicker.vue'

export default {
	name: 'BackgroundServiceAccountSettings',
	components: { NcSettingsSection, ServiceAccountPicker },

	computed: {
		/**
		 * The admin endpoint that reads and picks the account.
		 *
		 * @return {string} The URL.
		 *
		 * @spec openspec/changes/kcc-agent-panel/specs/kcc-agent-panel/spec.md#requirement-the-mirror-writes-as-the-background-service-account
		 */
		serviceAccountUrl() {
			return generateUrl('/apps/pipelinq/api/background/service-account')
		},
	},

	methods: { t },
}
</script>

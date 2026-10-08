<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Run the setup wizard again from the admin page (pipelinq-audit-admin-forms-pos, A1).
  - The wizard opens by itself only once per setup version; after that this
  - card is the way back in. Same pattern as CnAdminSettingsShell's
  - "Run setup wizard" action (ADR-042): the wizard is the manifest's own
  - `setup.steps`, so it asks exactly what the first run asked.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Setup wizard')"
		:description="
			t(
				'pipelinq',
				'Go through the first-time setup again, for example to load example data or change your organisation details.',
			)
		">
		<NcButton
			variant="secondary"
			data-testid="pipelinq-run-setup-wizard"
			@click="open = true">
			<template #icon>
				<AutoFix :size="20" />
			</template>
			{{ t('pipelinq', 'Run the setup wizard again') }}
		</NcButton>
		<CnSetupWizard
			v-if="open"
			appId="pipelinq"
			appName="Pipelinq"
			:steps="steps"
			:cancellable="true"
			@complete="open = false"
			@close="open = false" />
	</NcSettingsSection>
</template>

<script>
import { CnSetupWizard } from '@conduction/nextcloud-vue'
import { NcButton, NcSettingsSection } from '@nextcloud/vue'
import AutoFix from 'vue-material-design-icons/AutoFix.vue'
import manifest from '../../manifest.json'

export default {
	name: 'SetupWizardSection',
	components: {
		AutoFix,
		CnSetupWizard,
		NcButton,
		NcSettingsSection,
	},

	data() {
		return {
			open: false,
			steps: (manifest.setup && manifest.setup.steps) || [],
		}
	},
}
</script>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - Read-only state of the Shillinq and XWiki integrations
  - (pipelinq-setup-wizard-review). Nobody types a base URL: the server
  - detects both (IntegrationDetector) and hands the result over as the
  - `detected_integrations` initial state.
  -->
<template>
	<CnSettingsSection
		:name="t('pipelinq', 'Detected integrations')"
		:description="
			t(
				'pipelinq',
				'Pipelinq finds these apps on this server by itself. Install or remove an app to change what you see here.',
			)
		">
		<NcNoteCard :type="shillinq.installed ? 'success' : 'info'">
			<template v-if="shillinq.installed">
				{{ t('pipelinq', 'Shillinq is installed. Billing hands off to it.') }}
				<a :href="shillinq.url">{{ t('pipelinq', 'Open Shillinq') }}</a>
			</template>
			<template v-else>
				{{ t('pipelinq', 'Shillinq is not installed. Billing hand-off stays off.') }}
			</template>
		</NcNoteCard>
		<NcNoteCard :type="xwiki.available ? 'success' : 'info'">
			{{ xwikiText }}
		</NcNoteCard>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { NcNoteCard } from '@nextcloud/vue'

export default {
	name: 'DetectedIntegrations',
	components: {
		CnSettingsSection,
		NcNoteCard,
	},

	data() {
		const detected = loadState('pipelinq', 'detected_integrations', {})
		return {
			shillinq: detected.shillinq || { installed: false, url: '' },
			xwiki: detected.xwiki || { available: false, source: 'none' },
		}
	},

	computed: {
		/**
		 * The XWiki line, by the route the server found.
		 *
		 * @spec openspec/changes/pipelinq-setup-wizard-review/specs/first-time-setup/spec.md
		 * @return {string} The sentence to show.
		 */
		xwikiText() {
			if (this.xwiki.source === 'xwiki-app') {
				return t('pipelinq', 'XWiki is connected through the XWiki app.')
			}
			if (this.xwiki.source === 'openregister') {
				return t('pipelinq', 'XWiki is connected through OpenRegister and integriq.')
			}
			return t('pipelinq', 'XWiki is not connected. Install the XWiki app, or integriq with an XWiki source.')
		},
	},
}
</script>

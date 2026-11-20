<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  -
  - The Accessibility card of the admin page (platform-accessibility-statement,
  - REQ-PAS-003): the date of the last manual WCAG audit, the conformance
  - report and the draft toegankelijkheidsverklaring the municipality
  - completes and publishes. Both documents live on the docs site.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Accessibility')"
		:description="
			t(
				'pipelinq',
				'What the back office meets under WCAG 2.1 AA, and a draft accessibility statement your organisation completes and publishes.',
			)
		">
		<p class="accessibility-settings__audit">
			<template v-if="auditDate">
				{{ t('pipelinq', 'Last manual audit: {date}', { date: auditDate }) }}
			</template>
			<template v-else>
				{{ t('pipelinq', 'No manual audit has been recorded yet.') }}
			</template>
		</p>
		<ul class="accessibility-settings__links">
			<li>
				<a
					class="accessibility-settings__report"
					:href="reportUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('pipelinq', 'Accessibility conformance report') }}
				</a>
			</li>
			<li>
				<a
					class="accessibility-settings__statement"
					:href="statementUrl"
					target="_blank"
					rel="noopener noreferrer">
					{{ t('pipelinq', 'Draft accessibility statement') }}
				</a>
			</li>
		</ul>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection } from '@nextcloud/vue'
import {
	accessibilityStatement,
	formatAuditDate,
} from '../../config/accessibilityStatement.js'

export default {
	name: 'AccessibilitySettings',
	components: {
		NcSettingsSection,
	},

	props: {
		/** The conformance report on the docs site. */
		reportUrl: {
			type: String,
			default: accessibilityStatement.reportUrl,
		},

		/** The draft toegankelijkheidsverklaring on the docs site. */
		statementUrl: {
			type: String,
			default: accessibilityStatement.statementUrl,
		},

		/** The ISO date of the last manual audit, or null when none is recorded. */
		lastAuditDate: {
			type: String,
			default: accessibilityStatement.lastAuditDate,
		},
	},

	computed: {
		/**
		 * The last audit date as a long date, or '' when none is recorded.
		 *
		 * @spec openspec/changes/platform-accessibility-statement
		 * @return {string} The formatted date.
		 */
		auditDate() {
			return formatAuditDate(this.lastAuditDate)
		},
	},
}
</script>

<style scoped>
.accessibility-settings__audit {
	margin-bottom: calc(var(--default-grid-baseline, 4px) * 2);
}

.accessibility-settings__links a {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>

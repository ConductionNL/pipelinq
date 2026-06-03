<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
  -->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Computer Telephony Integration (CTI)')"
		:description="t('pipelinq', 'Configure the telephony platform that powers screen-pop and click-to-dial.')">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="cti-settings">
			<p v-if="!config.platform" class="cti-settings__empty">
				{{ t('pipelinq', 'No CTI platform is configured yet. Import the Pipelinq register to seed the default configuration.') }}
			</p>

			<dl v-else class="cti-settings__list">
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'Platform') }}</dt>
					<dd>{{ config.platform }}</dd>
				</div>
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'API base URL') }}</dt>
					<dd>{{ config.apiBaseUrl || '—' }}</dd>
				</div>
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'Credentials source') }}</dt>
					<dd>{{ config.credentialsRef || t('pipelinq', 'Not linked') }}</dd>
				</div>
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'Screen-pop') }}</dt>
					<dd>{{ config.screenPopEnabled ? t('pipelinq', 'Enabled') : t('pipelinq', 'Disabled') }}</dd>
				</div>
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'Screen-pop delay') }}</dt>
					<dd>{{ (config.screenPopDelayMs || 0) }} ms</dd>
				</div>
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'Click-to-dial') }}</dt>
					<dd>{{ config.clickToDialEnabled ? t('pipelinq', 'Enabled') : t('pipelinq', 'Disabled') }}</dd>
				</div>
				<div class="cti-settings__row">
					<dt>{{ t('pipelinq', 'Default outbound caller ID') }}</dt>
					<dd>{{ config.defaultOutboundCallerId || '—' }}</dd>
				</div>
			</dl>

			<p class="cti-settings__note">
				{{ t('pipelinq', 'The webhook shared secret is stored in app configuration and is never shown here.') }}
			</p>
		</div>
	</NcSettingsSection>
</template>

<script>
import { NcSettingsSection, NcLoadingIcon } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'CtiSettings',
	components: {
		NcSettingsSection,
		NcLoadingIcon,
	},
	data() {
		return {
			config: {},
			loading: true,
		}
	},
	async mounted() {
		await this.load()
	},
	methods: {
		t,
		async load() {
			this.loading = true
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/cti/config'))
				this.config = data.config || {}
			} catch (e) {
				showError(t('pipelinq', 'CTI configuration could not be loaded'))
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.cti-settings__list {
	margin: 0;
}

.cti-settings__row {
	display: flex;
	gap: 12px;
	padding: 6px 0;
	border-bottom: 1px solid var(--color-border);
}

.cti-settings__row dt {
	min-width: 220px;
	font-weight: bold;
}

.cti-settings__row dd {
	margin: 0;
}

.cti-settings__note {
	margin-top: 12px;
	color: var(--color-text-maxcontrast);
}
</style>

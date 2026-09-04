<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Marketing traffic settings (marketing-campaign-attribution): the campaign
  parameters switch, the Portaliq portal slug, and the Search Console
  connection (properties plus a service account key). The key is write-only:
  the page learns only whether one is on file and which service account
  email it belongs to, from GET /api/marketing/search-queries.

  @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-marketing-traffic-settings
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Marketing traffic')"
		:description="
			t(
				'pipelinq',
				'Campaign parameters on blast links, the Portaliq portal that mail events and site visits are attributed to, and the Search Console import.',
			)
		">
		<NcCheckboxRadioSwitch
			v-model="utmAuto"
			type="switch"
			data-testid="marketing-utm-auto">
			{{ t('pipelinq', 'Add campaign parameters to blast links') }}
		</NcCheckboxRadioSwitch>
		<p class="marketing-traffic__hint">
			{{
				t(
					'pipelinq',
					'Every link in a blast gets utm_source, utm_medium, utm_campaign and utm_content when it does not carry them yet. Parameters you wrote yourself are kept.',
				)
			}}
		</p>

		<NcTextField
			v-model="trafficPortal"
			:label="t('pipelinq', 'Portaliq portal slug')"
			placeholder="open-tilburg"
			data-testid="marketing-traffic-portal"
			:helperText="
				t(
					'pipelinq',
					'Mail opens and clicks are reported to this portal, and the blast performance page reads the site sessions of each campaign from it. Leave empty to keep everything inside Pipelinq.',
				)
			" />

		<h3 class="marketing-traffic__subheading">
			{{ t('pipelinq', 'Search Console') }}
		</h3>
		<NcTextArea
			v-model="gscProperties"
			:label="t('pipelinq', 'Search Console properties')"
			placeholder="https://example.org/&#10;sc-domain:example.org"
			data-testid="marketing-gsc-properties"
			:helperText="
				t(
					'pipelinq',
					'One property per line: a site URL such as https://example.org/ or a domain property such as sc-domain:example.org.',
				)
			"
			resize="vertical" />
		<NcTextArea
			v-model="gscKey"
			:label="t('pipelinq', 'Service account key (JSON)')"
			:placeholder="keyPlaceholder"
			data-testid="marketing-gsc-key"
			:helperText="keyHelp"
			resize="vertical" />
		<NcNoteCard v-if="serviceAccountEmail" type="info">
			{{
				t(
					'pipelinq',
					'Add {email} as a user with full permission on each property in Search Console, or the import is refused.',
					{ email: serviceAccountEmail },
				)
			}}
		</NcNoteCard>
		<p v-if="lastImportAt" class="marketing-traffic__hint">
			{{ t('pipelinq', 'Last import: {when}', { when: lastImportAt }) }}
		</p>

		<div class="marketing-traffic__actions">
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="marketing-traffic-save"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
				</template>
				{{ t('pipelinq', 'Save marketing traffic settings') }}
			</NcButton>
			<NcButton
				v-if="keySet"
				variant="tertiary"
				:disabled="saving"
				data-testid="marketing-gsc-key-clear"
				@click="clearKey">
				{{ t('pipelinq', 'Remove the stored key') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'

const UTM_KEY = 'blast.utm_auto'
const PORTAL_KEY = 'blast.traffic_portal'
const PROPERTIES_KEY = 'search.gsc.properties'
const SECRET_KEY = 'search.gsc.service_account_key'

export default {
	name: 'MarketingTrafficSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** The settings map as GET /api/settings returned it. */
		config: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['saved'],

	data() {
		return {
			utmAuto: true,
			trafficPortal: '',
			gscProperties: '',
			gscKey: '',
			keySet: false,
			serviceAccountEmail: '',
			lastImportAt: '',
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		settingsStore() {
			return useSettingsStore()
		},

		keyPlaceholder() {
			return this.keySet
				? this.t(
						'pipelinq',
						'A key is stored. Paste a new one to replace it.',
					)
				: '{ "type": "service_account", ... }'
		},

		keyHelp() {
			return this.t(
				'pipelinq',
				'The JSON key file of a Google Cloud service account. It is stored encrypted and never shown again.',
			)
		},
	},

	watch: {
		config: {
			immediate: true,
			handler(value) {
				this.applyConfig(value || {})
			},
		},
	},

	mounted() {
		this.fetchStatus()
	},

	methods: {
		/**
		 * Copy the stored values into the form.
		 *
		 * @param {object} config The settings map.
		 */
		applyConfig(config) {
			this.utmAuto = String(config[UTM_KEY] ?? 'true') !== 'false'
			this.trafficPortal = String(config[PORTAL_KEY] ?? '')
			this.gscProperties = String(config[PROPERTIES_KEY] ?? '').replace(
				/,\s*/g,
				'\n',
			)
			this.keySet = String(config[`${SECRET_KEY}_set`] ?? 'false') === 'true'
			this.lastImportAt = String(config['search.gsc.last_import_at'] ?? '')
		},

		/**
		 * The service account email and last import, from the read endpoint.
		 * Failure is non-fatal: the form still works without them.
		 */
		async fetchStatus() {
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/marketing/search-queries',
				)
				const { data } = await axios.get(url, { params: { limit: 1 } })
				this.serviceAccountEmail = data?.serviceAccountEmail || ''
				this.lastImportAt = data?.lastImportAt || this.lastImportAt
			} catch {
				this.serviceAccountEmail = ''
			}
		},

		/**
		 * Persist the section. The key is sent only when the admin pasted one.
		 *
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-marketing-traffic-settings
		 */
		async save() {
			this.saving = true
			this.message = ''
			const payload = {
				[UTM_KEY]: this.utmAuto ? 'true' : 'false',
				[PORTAL_KEY]: this.trafficPortal.trim(),
				[PROPERTIES_KEY]: this.gscProperties.trim(),
			}
			if (this.gscKey.trim() !== '') {
				payload[SECRET_KEY] = this.gscKey.trim()
			}
			const result = await this.settingsStore.saveSettings(payload)
			if (result) {
				this.gscKey = ''
				this.applyConfig(result)
				this.message = this.t('pipelinq', 'Marketing traffic settings saved')
				this.messageType = 'success'
				this.$emit('saved', result)
				await this.fetchStatus()
			} else {
				this.message = this.t(
					'pipelinq',
					'Saving the marketing traffic settings failed',
				)
				this.messageType = 'error'
			}
			this.saving = false
		},

		/**
		 * Delete the stored key.
		 */
		async clearKey() {
			this.saving = true
			this.message = ''
			const result = await this.settingsStore.saveSettings({
				[`${SECRET_KEY}_clear`]: 'true',
			})
			if (result) {
				this.applyConfig(result)
				this.serviceAccountEmail = ''
				this.message = this.t('pipelinq', 'The stored key was removed')
				this.messageType = 'success'
				this.$emit('saved', result)
			}
			this.saving = false
		},
	},
}
</script>

<style scoped lang="scss">
.marketing-traffic__hint {
	color: var(--color-text-maxcontrast);
	margin: 4px 0 12px;
}

.marketing-traffic__subheading {
	font-size: 1rem;
	font-weight: bold;
	margin: 20px 0 8px;
}

.marketing-traffic__actions {
	display: flex;
	gap: 8px;
	margin-top: 12px;
}
</style>

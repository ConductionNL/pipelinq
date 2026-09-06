<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Marketing intelligence settings (marketing-search-intelligence, phase 5).

  NO SECRET IS ENTERED HERE, AND THE SECTION SAYS SO. The only credential this
  phase uses is a Matomo token, and a token lives in the OpenRegister
  credential broker (ADR-064). What is entered here is the credential's id.
  The server refuses a value shaped like Matomo's own 32-character token_auth,
  because pasting one into a field that accepts any string is the one likely
  way that rule gets broken and it is silent otherwise.

  @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-marketing-intelligence-settings-hold-the-sources-and-hold-no-secret
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Marketing intelligence')"
		:description="
			t(
				'pipelinq',
				'Where the keyword, Matomo and competitor reads leave the instance, and how relevance is scored. Every outbound read goes through an OpenConnector source, so this section holds source ids and never a key.',
			)
		">
		<h3 class="marketing-intel__subheading">
			{{ t('pipelinq', 'Keywords') }}
		</h3>
		<NcTextField
			v-model="crawlSource"
			:label="t('pipelinq', 'Crawl source for our own pages')"
			placeholder="own-site"
			data-testid="marketing-intel-crawl-source"
			:helperText="
				t(
					'pipelinq',
					'The OpenConnector source that reaches your own website. The content gap check reads the title and headings of the pages that already appear in search. Leave it empty and that check does not run, which the Keywords page says rather than reporting no gaps.',
				)
			" />
		<NcTextField
			v-model="minImpressions"
			type="number"
			:label="t('pipelinq', 'Impressions before a query counts')"
			placeholder="100"
			data-testid="marketing-intel-min-impressions"
			:helperText="
				t(
					'pipelinq',
					'How many impressions a query needs over the window before any keyword finding names it. Lower it for a small site.',
				)
			" />

		<h3 class="marketing-intel__subheading">
			{{ t('pipelinq', 'Matomo') }}
		</h3>
		<NcTextField
			v-model="matomoBaseUrl"
			:label="t('pipelinq', 'Matomo address')"
			placeholder="https://matomo.example.org"
			data-testid="marketing-intel-matomo-url"
			:helperText="
				t(
					'pipelinq',
					'Used for links out of the reports. The reads go through the source below.',
				)
			" />
		<NcTextField
			v-model="matomoSiteId"
			:label="t('pipelinq', 'Matomo site id')"
			placeholder="1"
			data-testid="marketing-intel-matomo-site" />
		<NcTextField
			v-model="matomoSource"
			:label="t('pipelinq', 'Matomo connector source')"
			placeholder="matomo"
			data-testid="marketing-intel-matomo-source"
			:helperText="
				t(
					'pipelinq',
					'The OpenConnector source that reaches your Matomo instance.',
				)
			" />
		<NcTextField
			v-model="matomoCredential"
			:label="t('pipelinq', 'Matomo credential id')"
			placeholder="b7f4a9c1-2d3e-4f56-8a90-1b2c3d4e5f60"
			data-testid="marketing-intel-matomo-credential"
			:helperText="
				t(
					'pipelinq',
					'The id of the credential the OpenRegister broker holds for Matomo. Store the token there, not here: a value that looks like a Matomo token is refused.',
				)
			" />

		<h3 class="marketing-intel__subheading">
			{{ t('pipelinq', 'Competitors') }}
		</h3>
		<NcTextField
			v-model="competitorSource"
			:label="t('pipelinq', 'Egress source for competitor reads')"
			placeholder="outbound-web"
			data-testid="marketing-intel-competitor-source"
			:helperText="
				t(
					'pipelinq',
					'The OpenConnector source feeds, sitemaps, page watches and public timelines are read through. A competitor on their own domain can carry a source of its own instead.',
				)
			" />
		<NcTextField
			v-model="userAgent"
			:label="t('pipelinq', 'User agent for competitor reads')"
			placeholder="Pipelinq competitor watch"
			data-testid="marketing-intel-user-agent"
			:helperText="
				t(
					'pipelinq',
					'How these reads identify themselves to the sites they read.',
				)
			" />
		<NcCheckboxRadioSwitch
			v-model="relevance"
			type="switch"
			data-testid="marketing-intel-relevance">
			{{ t('pipelinq', 'Let hermiq score how relevant an item is') }}
		</NcCheckboxRadioSwitch>
		<p class="marketing-intel__hint">
			{{
				t(
					'pipelinq',
					"Off by default: it sends a competitor's headline to the model you configured in hermiq. When it is off, or hermiq cannot answer, an item is stored without a score and shown as not scored.",
				)
			}}
		</p>
		<NcTextArea
			v-model="relevanceContext"
			:label="t('pipelinq', 'What counts as relevant here')"
			:placeholder="
				t(
					'pipelinq',
					'Tenders at municipalities, open source in the public sector.',
				)
			"
			data-testid="marketing-intel-relevance-context"
			:helperText="
				t(
					'pipelinq',
					'Put in front of the model verbatim, in your own words.',
				)
			"
			resize="vertical" />

		<div class="marketing-intel__actions">
			<NcButton
				variant="primary"
				:disabled="saving"
				data-testid="marketing-intel-save"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="16" />
				</template>
				{{ t('pipelinq', 'Save marketing intelligence settings') }}
			</NcButton>
		</div>
		<NcNoteCard v-if="message" :type="messageType">
			{{ message }}
		</NcNoteCard>
	</NcSettingsSection>
</template>

<script>
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

const CRAWL_KEY = 'search.crawl_source'
const FLOOR_KEY = 'search.striking_min_impressions'
const MATOMO_URL_KEY = 'matomo.base_url'
const MATOMO_SITE_KEY = 'matomo.site_id'
const MATOMO_SOURCE_KEY = 'matomo.source_id'
const MATOMO_CREDENTIAL_KEY = 'matomo.credential_ref'
const COMPETITOR_SOURCE_KEY = 'competitor.egress_source'
const USER_AGENT_KEY = 'competitor.user_agent'
const RELEVANCE_KEY = 'competitor.relevance'
const RELEVANCE_CONTEXT_KEY = 'competitor.relevance_context'

export default {
	name: 'MarketingIntelSettings',
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
			crawlSource: '',
			minImpressions: '',
			matomoBaseUrl: '',
			matomoSiteId: '',
			matomoSource: '',
			matomoCredential: '',
			competitorSource: '',
			userAgent: '',
			relevance: false,
			relevanceContext: '',
			saving: false,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * @return {object} The pinia settings store.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-marketing-intelligence-settings-hold-the-sources-and-hold-no-secret
		 */
		settingsStore() {
			return useSettingsStore()
		},
	},

	watch: {
		config: {
			immediate: true,
			/**
			 * @param {object} value The settings map.
			 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-marketing-intelligence-settings-hold-the-sources-and-hold-no-secret
			 */
			handler(value) {
				this.applyConfig(value || {})
			},
		},
	},

	methods: {
		/**
		 * Copy the stored values into the form.
		 *
		 * @param {object} config The settings map.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-marketing-intelligence-settings-hold-the-sources-and-hold-no-secret
		 */
		applyConfig(config) {
			this.crawlSource = String(config[CRAWL_KEY] ?? '')
			this.minImpressions = String(config[FLOOR_KEY] ?? '')
			this.matomoBaseUrl = String(config[MATOMO_URL_KEY] ?? '')
			this.matomoSiteId = String(config[MATOMO_SITE_KEY] ?? '')
			this.matomoSource = String(config[MATOMO_SOURCE_KEY] ?? '')
			this.matomoCredential = String(config[MATOMO_CREDENTIAL_KEY] ?? '')
			this.competitorSource = String(config[COMPETITOR_SOURCE_KEY] ?? '')
			this.userAgent = String(config[USER_AGENT_KEY] ?? '')
			this.relevance = String(config[RELEVANCE_KEY] ?? 'false') === 'true'
			this.relevanceContext = String(config[RELEVANCE_CONTEXT_KEY] ?? '')
		},

		/**
		 * Persist the section. A refused credential value comes back as a
		 * failed save with the server's own message, which is where the
		 * "that is a token, not a reference" wording lives.
		 *
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-analytics-connectors/spec.md#requirement-matomo-is-read-through-a-source-with-the-token-as-a-credential-reference
		 */
		async save() {
			this.saving = true
			this.message = ''
			const result = await this.settingsStore.saveSettings({
				[CRAWL_KEY]: this.crawlSource.trim(),
				[FLOOR_KEY]: String(this.minImpressions).trim(),
				[MATOMO_URL_KEY]: this.matomoBaseUrl.trim(),
				[MATOMO_SITE_KEY]: String(this.matomoSiteId).trim(),
				[MATOMO_SOURCE_KEY]: this.matomoSource.trim(),
				[MATOMO_CREDENTIAL_KEY]: this.matomoCredential.trim(),
				[COMPETITOR_SOURCE_KEY]: this.competitorSource.trim(),
				[USER_AGENT_KEY]: this.userAgent.trim(),
				[RELEVANCE_KEY]: this.relevance ? 'true' : 'false',
				[RELEVANCE_CONTEXT_KEY]: this.relevanceContext.trim(),
			})
			if (result) {
				this.applyConfig(result)
				this.message = this.t(
					'pipelinq',
					'Marketing intelligence settings saved',
				)
				this.messageType = 'success'
				this.$emit('saved', result)
			} else {
				this.message = this.t(
					'pipelinq',
					'Saving the marketing intelligence settings failed. A Matomo token is refused here: store it in the credential broker and enter the credential id instead.',
				)
				this.messageType = 'error'
			}
			this.saving = false
		},
	},
}
</script>

<style scoped lang="scss">
.marketing-intel__subheading {
	margin-top: 20px;
}

.marketing-intel__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 12px;
}

.marketing-intel__actions {
	display: flex;
	gap: 12px;
	margin-top: 16px;
}
</style>

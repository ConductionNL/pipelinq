<template>
	<!-- Boards PqBeheer, PqBeheerKoppelingen, PqBeheerKassa,
	     PqBeheerMarketing and PqBeheerPortaal: the library admin shell draws
	     the title, the path and the Documentatie button, then a tab strip.
	     Algemeen holds the version card and the sections in the board's
	     order; each other tab holds one part of the page. `lazyTabs` mounts
	     only the open tab, so the page no longer fires every section's reads
	     at once. -->
	<CnAdminSettingsShell
		appId="pipelinq"
		appName="Pipelinq"
		look="board"
		:title="pageTitle"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="breadcrumbNote"
		docUrl="https://pipelinq.conduction.nl/docs/intro"
		:appVersion="appVersion"
		:versionTitle="t('pipelinq', 'Version Information')"
		:versionDescription="
			t('pipelinq', 'Information about the current Pipelinq installation')
		"
		:versionCardWide="false"
		:showSupport="false"
		:reimportUrl="reimportUrl"
		:showSetup="isAdmin"
		:setupSteps="setupSteps"
		:tabs="adminTabs"
		tabsVariant="underline"
		lazyTabs
		@tabChange="onTabChange"
		@reimported="onReimported"
		@reimportError="onReimportError">
		<!-- Register & Schema Mapping -->
		<CnRegisterMapping
			:name="t('pipelinq', 'Register Configuration')"
			:description="
				t(
					'pipelinq',
					'Map Pipelinq object types to OpenRegister registers and schemas',
				)
			"
			:groups="registerGroups"
			:configuration="config"
			:saving="saving"
			@save="save" />

		<!-- Lead Sources -->
		<TagManager
			v-if="isConfigured"
			:title="t('pipelinq', 'Lead Sources')"
			:tags="leadSourceTags"
			:loading="leadSourcesLoading"
			:addLabel="t('pipelinq', 'Add source')"
			:addPlaceholder="t('pipelinq', 'Enter source name…')"
			:usageCheck="checkLeadSourceUsage"
			:tagLabel="leadSourceLabel"
			@add="addLeadSource"
			@remove="removeLeadSource"
			@rename="renameLeadSource" />

		<!-- Request Channels -->
		<TagManager
			v-if="isConfigured"
			:title="t('pipelinq', 'Request Channels')"
			:tags="requestChannelTags"
			:loading="requestChannelsLoading"
			:addLabel="t('pipelinq', 'Add channel')"
			:addPlaceholder="t('pipelinq', 'Enter channel name…')"
			:usageCheck="checkRequestChannelUsage"
			@add="addRequestChannel"
			@remove="removeRequestChannel"
			@rename="renameRequestChannel" />

		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.leadManagement"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Product Categories -->
		<ProductCategoryManager v-if="isConfigured" />

		<!-- Menu structure: simple (default) or full, and the modules
		     (simple-structure-profile). -->
		<MenuStructureSettings v-if="isAdmin" class="cn-settings-section--wide" />

		<!-- Skill Management -->
		<SkillSettings v-if="isConfigured" />

		<!-- Agent Profile Management -->
		<AgentProfileSettings v-if="isConfigured" />

		<!-- Pipeline Management -->
		<PipelineManager v-if="isConfigured" />

		<!-- Forecast Configuration -->
		<ForecastSettings v-if="isConfigured" />

		<!-- Provisioning is an admin action, never a wizard step
		     (pipelinq-setup-wizard-review). -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.provisionData"
			appId="pipelinq" />


		<!-- BRP lookups and the mTLS certificate (bsn-validatie-en-brp-lookup). -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.brpMonitor"
			appId="pipelinq" />

		<!-- Re-import Status -->
		<div v-if="message" class="actions-section">
			<NcNoteCard :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
		<!-- The support lines, as the last card across the page. -->
		<NcSettingsSection
			class="cn-settings-section--wide"
			:name="t('pipelinq', 'Support')">
			<p>
				{{ t('pipelinq', 'For support, contact us at') }}
				<a href="mailto:support@conduction.nl">support@conduction.nl</a>
			</p>
			<p>
				{{
					t(
						'pipelinq',
						'For a Service Level Agreement (SLA), contact us at',
					)
				}}
				<a href="mailto:sales@conduction.nl">sales@conduction.nl</a>
			</p>
		</NcSettingsSection>

		<template #tab-connections>
			<!-- Telephony and channels: configuration, not an operator surface
			     (nav-ia-cleanup). -->
			<CtiSettings
				v-if="isAdmin && isConfigured"
				class="cn-settings-section--wide" />

			<MessagingSettings v-if="isAdmin && isConfigured" />

			<!-- xWiki Integration (xwiki-integration) -->
			<NcSettingsSection
				v-if="isAdmin"
				:name="t('pipelinq', 'xWiki integration')"
				:description="
					t(
						'pipelinq',
						'Configure the xWiki knowledge base proxy used by the dashboard widget and the detail sidebar tabs. Pipelinq finds xWiki through the xWiki app or through OpenRegister.',
					)
				">
				<NcNoteCard
					v-if="xwikiStatus"
					:type="xwikiStatus.available ? 'success' : 'warning'">
					<span v-if="xwikiStatus.available">
						{{
							t('pipelinq', 'xWiki reachable at {url}', {
								url: xwikiStatus.baseUrl,
							})
						}}
						<template v-if="xwikiStatus.version">
							({{
								t('pipelinq', 'version {ver}', {
									ver: xwikiStatus.version,
								})
							}})
						</template>
					</span>
					<span v-else>
						{{
							t(
								'pipelinq',
								'xWiki not reachable. Install the xWiki app, or connect xWiki through OpenRegister and integriq.',
							)
						}}
					</span>
				</NcNoteCard>
				<NcNoteCard v-if="!xwikiAppInstalled" type="warning">
					{{
						t(
							'pipelinq',
							'The optional xWiki Nextcloud app is not installed. Pipelinq searches xWiki through OpenRegister instead.',
						)
					}}
				</NcNoteCard>
				<NcTextField
					v-model="config.xwiki_default_space"
					:label="t('pipelinq', 'Default xWiki space')"
					placeholder="Kennisbank"
					:helperText="
						t(
							'pipelinq',
							'Space name pre-selected by the dashboard widget. Leave empty to search across all spaces.',
						)
					" />
				<NcTextField
					v-model="config.xwiki_cache_ttl"
					type="number"
					:label="t('pipelinq', 'Cache TTL (seconds)')"
					placeholder="300"
					:helperText="
						t(
							'pipelinq',
							'How long search and page results are cached server-side. Default: 300.',
						)
					" />
				<div class="xwiki-actions">
					<NcButton
						variant="primary"
						:disabled="savingXwiki"
						@click="saveXwiki">
						<template #icon>
							<NcLoadingIcon v-if="savingXwiki" :size="16" />
						</template>
						{{ t('pipelinq', 'Save xWiki settings') }}
					</NcButton>
					<NcButton
						variant="secondary"
						:disabled="testingXwiki"
						@click="testXwiki">
						<template #icon>
							<NcLoadingIcon v-if="testingXwiki" :size="16" />
						</template>
						{{ t('pipelinq', 'Test connection') }}
					</NcButton>
				</div>
				<NcNoteCard v-if="xwikiMessage" :type="xwikiMessageType">
					{{ xwikiMessage }}
				</NcNoteCard>
			</NcSettingsSection>

			<!-- Shillinq integration. Nobody types a webhook URL: the hand-offs
			     follow the detected Shillinq app (pipelinq-audit-admin-forms-pos).
			     Without Shillinq, DetectedIntegrations says it is not installed. -->
			<CnSettingsFieldsSection
				v-if="isAdmin && shillinqInstalled"
				v-bind="sections.shillinq"
				appId="pipelinq"
				@saved="onSectionSaved" />

			<!-- Shillinq and XWiki, detected rather than typed. -->
			<DetectedIntegrations v-if="isAdmin" />

			<!-- The CTI webhook log: not on the board, kept after its sections. -->
			<CtiEventLog v-if="isAdmin && isConfigured" />
		</template>

		<template #tab-integrations>
			<!-- Board PqIntegrations: the connections this app declares, as
			     integriq checks them. Until the library draws them here, the
			     tab opens the in-app list scoped to this app. -->
			<NcSettingsSection
				class="cn-settings-section--wide"
				:name="t('pipelinq', 'Integrations')"
				:description="
					t(
						'pipelinq',
						'The connections Pipelinq declares, checked by integriq.',
					)
				">
				<a :href="integrationsUrl" class="pos-lookup-link">
					{{ t('pipelinq', 'Open the integrations') }}
				</a>
			</NcSettingsSection>
		</template>

		<template #tab-pos>
			<!-- Point of Sale configuration. `PaymentSettingsForm` (PSP providers —
			     who processes the money) and `PosTenderTypeList` (tender types — how
			     the customer pays at the register) read as duplicates but are two
			     different things; the labels are what confused them. -->
			<PaymentSettingsForm v-if="isAdmin && isConfigured" />
			<PosTenderTypeManager v-if="isAdmin && isConfigured" />
			<VatRatesSettings v-if="isAdmin" :config="config" />
			<PosRoleManager v-if="isAdmin && isConfigured" />
			<PosStaffManager v-if="isAdmin && isConfigured" />

			<!-- The till's customer lookup is a page of its own in the app. -->
			<NcSettingsSection
				v-if="isAdmin && isConfigured"
				class="cn-settings-section--wide"
				:name="t('pipelinq', 'Customer lookup at the till')"
				:description="
					t(
						'pipelinq',
						'Search fields, how far back the purchase history goes and consents for the till.',
					)
				">
				<a :href="posCustomerLookupUrl" class="pos-lookup-link">
					{{ t('pipelinq', 'Open customer lookup at the till') }}
				</a>
			</NcSettingsSection>
		</template>

		<!-- Board PqKassaKlantzoeken: the till's customer lookup settings. -->
		<template #tab-pos-lookup>
			<PosCustomerSettings v-if="isAdmin && isConfigured" />
		</template>

		<template #tab-marketing>
			<!-- Marketing intelligence (marketing-search-intelligence): the
			     sources the keyword, Matomo and competitor reads leave through,
			     and the relevance switch. No secret is entered here: the Matomo
			     token lives in the credential broker. -->
			<CnSettingsFieldsSection
				v-if="isAdmin"
				v-bind="sections.marketingIntel"
				appId="pipelinq"
				@saved="onSectionSaved" />

			<!-- Marketing traffic (marketing-campaign-attribution): campaign
			     parameters on blast links, the Portaliq portal, Search Console. -->
			<MarketingTrafficSettings
				v-if="isAdmin"
				:config="config"
				@saved="onMarketingTrafficSaved" />

			<!-- Mail transports and their SPF/DKIM/DMARC check (marketing-mail-transports). -->
			<DeliverabilitySettings v-if="isAdmin && isConfigured" />

			<!-- Mailing list signup embed (marketing-lists-and-double-opt-in) -->
			<MailingListEmbedSettings v-if="isConfigured" />

			<!-- Prospect Discovery Settings -->
			<ProspectSettings v-if="isConfigured" />

			<!-- BI Export Configuration -->
			<CnSettingsFieldsSection
				v-if="isAdmin"
				v-bind="sections.exportConfiguration"
				appId="pipelinq"
				@saved="onSectionSaved" />
		</template>

		<template #tab-portal>
			<!-- Customer portal tenant config, accounts and audit trail
			     (pipelinq#2041). Admin only, like the endpoints it calls. -->
			<PortalSettings v-if="isAdmin && isConfigured" />
		</template>
	</CnAdminSettingsShell>
</template>

<script>
import {
	CnAdminSettingsShell,
	CnRegisterMapping,
	CnSettingsFieldsSection,
} from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
	NcTextField,
} from '@nextcloud/vue'
import AgentProfileSettings from '../../components/admin/AgentProfileSettings.vue'
import ForecastSettings from '../../components/admin/ForecastSettings.vue'
import PortalSettings from '../../components/admin/PortalSettings.vue'
import SkillSettings from '../../components/admin/SkillSettings.vue'
import PosCustomerSettings from '../admin/PosCustomerSettings.vue'
import CtiEventLog from './CtiEventLog.vue'
import CtiSettings from './CtiSettings.vue'
// marketing-mail-transports: transport list + SPF/DKIM/DMARC panel.
import DeliverabilitySettings from './DeliverabilitySettings.vue'
import DetectedIntegrations from './DetectedIntegrations.vue'
import MailingListEmbedSettings from './MailingListEmbedSettings.vue'
import MarketingTrafficSettings from './MarketingTrafficSettings.vue'
import MenuStructureSettings from './MenuStructureSettings.vue'
// Configuration surfaces moved off the app nav onto this admin page
// (nav-ia-cleanup): channels, telephony, and the POS master-data.
import MessagingSettings from './MessagingSettings.vue'
import PaymentSettingsForm from './PaymentSettingsForm.vue'
import PipelineManager from './PipelineManager.vue'
import PosRoleManager from './PosRoleManager.vue'
import PosStaffManager from './PosStaffManager.vue'
import PosTenderTypeManager from './PosTenderTypeManager.vue'
import ProductCategoryManager from './ProductCategoryManager.vue'
import ProspectSettings from './ProspectSettings.vue'
import TagManager from './TagManager.vue'
import VatRatesSettings from './VatRatesSettings.vue'
import { objectTypeGroups, objectTypes } from '../../config/objectTypes.js'
import manifest from '../../manifest.json'
import { leadSourceLabel } from '../../services/leadSourceLabels.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useObjectStore } from '../../store/modules/object.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import adminSections from './adminSections.json'

/**
 * Whether the server detected the Shillinq app (IntegrationDetector).
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/admin-settings/spec.md#requirement-shillinq-hand-offs-follow-the-detected-app
 * @return {boolean} True when Shillinq is installed.
 */
function shillinqDetected() {
	const detected = loadState('pipelinq', 'detected_integrations', {})
	return detected?.shillinq?.installed === true
}

/**
 * The admin page's tabs (boards PqBeheer, PqBeheerKoppelingen,
 * PqBeheerKassa, PqBeheerMarketing, PqBeheerPortaal): the strip label, the
 * page title and the line after the path while the tab is open. The strings
 * are catalogue keys, translated where they are read.
 */
const ADMIN_TABS = [
	{
		id: 'general',
		label: 'General',
		title: 'Pipelinq settings',
		note: 'Set up your Pipelinq installation',
	},
	{
		id: 'connections',
		label: 'Connections',
		title: 'Pipelinq settings',
		note: 'The connections: telephony, messages, xWiki and Shillinq',
	},
	{
		id: 'integrations',
		label: 'Integrations',
		title: 'Pipelinq settings: integrations',
		note: 'Applies to the whole organisation; integriq checks every connection',
	},
	{
		id: 'pos',
		label: 'Point of sale',
		title: 'Pipelinq settings: point of sale',
		note: 'Only when the module is on',
	},
	{
		id: 'pos-lookup',
		label: 'Customer lookup at the till',
		title: 'Pipelinq settings: customer lookup at the till',
		note: 'Administration setting for the point of sale module',
	},
	{
		id: 'marketing',
		label: 'Marketing and data',
		title: 'Pipelinq settings: marketing and data',
		note: 'Marketing, prospects and export',
	},
	{
		id: 'portal',
		label: 'Customer portal',
		title: 'Customer portal',
		note: 'Each portal has its own domain, look and tabs',
	},
]

/**
 * The tab the address names (`?tab=<id>`, written by the admin shell), or
 * Algemeen.
 *
 * @return {string} A tab id.
 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
 */
function openTabFromAddress() {
	const id = new URLSearchParams(window.location.search).get('tab')
	return ADMIN_TABS.some((tab) => tab.id === id) ? id : ADMIN_TABS[0].id
}

export default {
	name: 'PipelinqAdminSettings',
	components: {
		MessagingSettings,
		DeliverabilitySettings,
		CtiEventLog,
		CtiSettings,
		PaymentSettingsForm,
		PosTenderTypeManager,
		PosStaffManager,
		PosRoleManager,
		PortalSettings,
		PosCustomerSettings,
		CnAdminSettingsShell,
		CnRegisterMapping,
		CnSettingsFieldsSection,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
		PipelineManager,
		ProductCategoryManager,
		ProspectSettings,
		TagManager,
		SkillSettings,
		AgentProfileSettings,
		ForecastSettings,
		MailingListEmbedSettings,
		MarketingTrafficSettings,
		MenuStructureSettings,
		DetectedIntegrations,
		VatRatesSettings,
	},

	data() {
		return {
			config: {},
			appVersion: loadState('pipelinq', 'version', 'Unknown'),
			// The open tab, read from the address the shell writes it to.
			activeTab: openTabFromAddress(),
			reimportUrl: generateUrl('/apps/pipelinq/api/settings/reimport'),
			// The declared settings sections, and the first-time setup the
			// shell's "Run setup wizard" opens again.
			sections: adminSections,
			setupSteps: (manifest.setup && manifest.setup.steps) || [],
			saving: false,
			message: '',
			messageType: 'success',
			isAdmin: false,
			// Shillinq is detected, never configured (pipelinq-audit-admin-forms-pos).
			shillinqInstalled: shillinqDetected(),
			// xWiki integration (xwiki-integration).
			savingXwiki: false,
			testingXwiki: false,
			xwikiMessage: '',
			xwikiMessageType: 'success',
			xwikiStatus: null,
			xwikiAppInstalled: true,
		}
	},

	computed: {
		/**
		 * The tab strip: Algemeen, then one tab per part of the page.
		 *
		 * @return {Array<{id: string, label: string}>}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		adminTabs() {
			return ADMIN_TABS.map((tab) => ({
				id: tab.id,
				label: t('pipelinq', tab.label),
			}))
		},

		/**
		 * The open tab's entry, Algemeen when the address names none.
		 *
		 * @return {{id: string, label: string, title: string, note: string}}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		openTab() {
			return (
				ADMIN_TABS.find((tab) => tab.id === this.activeTab) || ADMIN_TABS[0]
			)
		},

		/**
		 * The page title: the board names the open part after a colon.
		 *
		 * @return {string}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		pageTitle() {
			return t('pipelinq', this.openTab.title)
		},

		/**
		 * The path under the title: the server's administration settings,
		 * the app, then the open tab when it is not Algemeen.
		 *
		 * @return {Array<{label: string, href?: string}>}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		breadcrumbs() {
			const crumbs = [
				{
					label: t('pipelinq', 'Administration settings'),
					href: generateUrl('/settings/admin'),
				},
				{ label: 'Pipelinq' },
			]
			if (this.openTab !== ADMIN_TABS[0]) {
				crumbs.push({ label: t('pipelinq', this.openTab.label) })
			}
			return crumbs
		},

		/**
		 * The line after the path, saying what the open tab holds.
		 *
		 * @return {string}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		breadcrumbNote() {
			return t('pipelinq', this.openTab.note)
		},

		/**
		 * The in-app integrations list, scoped to this app.
		 *
		 * @return {string}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		integrationsUrl() {
			return generateUrl('/apps/pipelinq/settings/integrations?app=pipelinq')
		},

		/**
		 * The tab that holds the till's customer lookup settings.
		 *
		 * @return {string}
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		posCustomerLookupUrl() {
			return '?tab=pos-lookup'
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-87
		 */
		settingsStore() {
			return useSettingsStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-74
		 */
		leadSourcesStore() {
			return useLeadSourcesStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-85
		 */
		requestChannelsStore() {
			return useRequestChannelsStore()
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-76
		 */
		objectStore() {
			return useObjectStore()
		},

		isConfigured() {
			return !!this.config.register
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-72
		 */
		leadSourceTags() {
			return this.leadSourcesStore.tags
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-73
		 */
		leadSourcesLoading() {
			return this.leadSourcesStore.loading
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-83
		 */
		requestChannelTags() {
			return this.requestChannelsStore.tags
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-84
		 */
		requestChannelsLoading() {
			return this.requestChannelsStore.loading
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-77
		 */
		registerGroups() {
			const types = objectTypes()
			return objectTypeGroups()
				.map((group) => ({
					name: group.name,
					description: group.description,
					registerConfigKey: group.registerConfigKey,
					types: types
						.filter((type) => type.group === group.key)
						.map(({ slug, label, description }) => ({
							slug,
							label,
							description,
						})),
				}))
				.filter((group) => group.types.length > 0)
		},
	},

	/**
	 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-75
	 */
	async mounted() {
		const data = await this.settingsStore.fetchSettings()
		if (data) {
			this.config = data.config || {}
			this.isAdmin = this.settingsStore.isAdmin
		}

		if (this.isConfigured) {
			this.leadSourcesStore.fetchSources()
			this.requestChannelsStore.fetchChannels()
		}

		if (this.isAdmin) {
			// xWiki integration (xwiki-integration): probe status so the
			// admin section shows the reachable / unreachable badge on load.
			this.testXwiki()
		}
	},

	methods: {
		/**
		 * Follow the shell's tab strip, so the title and the path name the
		 * open tab.
		 *
		 * @param {string} id The id of the opened tab.
		 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
		 */
		onTabChange(id) {
			this.activeTab = id
		},

		/**
		 * @param {string} name A stored lead source name.
		 * @return {string} Its label, or the name as an admin typed it.
		 * @spec openspec/specs/lead-management/spec.md
		 */
		leadSourceLabel(name) {
			return leadSourceLabel(name)
		},

		/**
		 * The shell re-imported the configuration: take the config it returns,
		 * so the sections show what the import wrote.
		 *
		 * @param {object} data The re-import response.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-78
		 */
		onReimported(data) {
			if (data && data.config) {
				this.config = data.config
			}
		},

		/**
		 * The import runs even when a schema is rejected, and the shell then
		 * reports the server's message as an error. Read the config again, so
		 * the sections show what the import did write.
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-78
		 */
		async onReimportError() {
			const data = await this.settingsStore.fetchSettings()
			if (data && data.config) {
				this.config = data.config
			}
		},

		/**
		 * Keep the page's config in step with what the marketing traffic
		 * section saved, so the other sections do not overwrite it.
		 *
		 * @param {object} updated The updated config returned by the section.
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-marketing-traffic-settings
		 */
		onMarketingTrafficSaved(updated) {
			this.config = updated || this.config
		},

		/**
		 * Keep the page's config in step with what a declared section saved,
		 * so the register mapping does not write an older value back.
		 *
		 * @param {object} sent What the section sent, by setting key.
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-forms-are-declared-sections
		 */
		onSectionSaved(sent) {
			this.config = { ...this.config, ...(sent || {}) }
		},

		/**
		 * Persist the app configuration.
		 *
		 * @param {object} configuration The full settings payload to save.
		 * @return {Promise<void>}
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-86
		 */
		async save(configuration) {
			this.saving = true
			this.message = ''
			const result = await this.settingsStore.saveSettings(configuration)
			if (result) {
				this.config = this.settingsStore.config || result
				this.message = t('pipelinq', 'Configuration saved')
				this.messageType = 'success'
			}
			this.saving = false
		},

		/**
		 * @param {string} name The lead source to add.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-67
		 */
		async addLeadSource(name) {
			await this.leadSourcesStore.addSource(name)
		},

		/**
		 * @param {string} id Identifier of the lead source to remove.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-79
		 */
		async removeLeadSource(id) {
			await this.leadSourcesStore.removeSource(id)
		},

		/**
		 * @param {string} id Identifier of the lead source.
		 * @param {string} name The new name.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-81
		 */
		async renameLeadSource(id, name) {
			await this.leadSourcesStore.renameSource(id, name)
		},

		/**
		 * @param {string} name The request channel to add.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-68
		 */
		async addRequestChannel(name) {
			await this.requestChannelsStore.addChannel(name)
		},

		/**
		 * @param {string} id Identifier of the request channel to remove.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-80
		 */
		async removeRequestChannel(id) {
			await this.requestChannelsStore.removeChannel(id)
		},

		/**
		 * @param {string} id Identifier of the request channel.
		 * @param {string} name The new name.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-82
		 */
		async renameRequestChannel(id, name) {
			await this.requestChannelsStore.renameChannel(id, name)
		},

		/**
		 * @param {string} sourceName The lead source to count references for.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-69
		 */
		async checkLeadSourceUsage(sourceName) {
			return this.countObjectsWithField('lead', 'source', sourceName)
		},

		/**
		 * @param {string} channelName The request channel to count references for.
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-70
		 */
		async checkRequestChannelUsage(channelName) {
			// A request is a `ticket` narrowed by ticketType (unify-ticket-supertype),
			// so the usage count must exclude complaints and contactmomenten.
			return this.countObjectsWithField('ticket', 'channel', channelName, {
				ticketType: 'request',
			})
		},

		/**
		 * Persist xWiki integration settings (xwiki-integration).
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#8.1
		 */
		async saveXwiki() {
			this.savingXwiki = true
			this.xwikiMessage = ''
			try {
				const result = await this.settingsStore.saveSettings({
					...this.config,
					xwiki_default_space: (
						this.config.xwiki_default_space || ''
					).trim(),
					xwiki_cache_ttl: String(this.config.xwiki_cache_ttl || 300),
				})
				if (result) {
					this.config = this.settingsStore.config || result
				}
				this.xwikiMessage = t('pipelinq', 'xWiki settings saved.')
				this.xwikiMessageType = 'success'
				await this.testXwiki()
			} catch (e) {
				this.xwikiMessage =
					e.response?.data?.message
					|| t('pipelinq', 'Failed to save xWiki settings.')
				this.xwikiMessageType = 'error'
			} finally {
				this.savingXwiki = false
			}
		},

		/**
		 * Test the xWiki connection via /api/xwiki/status.
		 *
		 * @spec openspec/changes/xwiki-integration/tasks.md#8.1
		 */
		async testXwiki() {
			this.testingXwiki = true
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/xwiki/status'),
					{
						headers: {
							requesttoken:
								typeof OC !== 'undefined' && OC.requestToken
									? OC.requestToken
									: '',
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				const data = await response.json()
				this.xwikiStatus = data
				this.xwikiAppInstalled = data && data.source === 'xwiki-app'
				if (data.available) {
					this.xwikiMessage = t('pipelinq', 'xWiki connection successful.')
					this.xwikiMessageType = 'success'
				} else {
					this.xwikiMessage = t(
						'pipelinq',
						'xWiki not reachable. Install the xWiki app, or connect xWiki through OpenRegister and integriq.',
					)
					this.xwikiMessageType = 'warning'
				}
			} catch (e) {
				this.xwikiMessage =
					e.message
					|| t('pipelinq', 'Failed to reach the xWiki status endpoint.')
				this.xwikiMessageType = 'error'
			} finally {
				this.testingXwiki = false
			}
		},

		/**
		 * Count objects of `type` whose `field` equals `value`, used to warn
		 * before deleting a value something still references.
		 *
		 * @param {string} type The registered object-type slug.
		 * @param {string} field The property to match on.
		 * @param {string} value The value to match.
		 * @param {object} [extraFilters] Additional query filters, e.g. the
		 *   `ticketType` discriminator needed to narrow the `ticket` supertype
		 *   down to one of its subtypes (unify-ticket-supertype).
		 *
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-71
		 */
		async countObjectsWithField(type, field, value, extraFilters = {}) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return 0
			const extra = Object.entries(extraFilters)
				.map(([k, v]) => `${k}=${encodeURIComponent(v)}&`)
				.join('')
			const url = generateUrl(
				`/apps/openregister/api/objects/${config.register}/${config.schema}?${extra}${field}=${encodeURIComponent(value)}&_limit=1`,
			)
			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})
			if (!response.ok) return 0
			const data = await response.json()
			return data.total || 0
		},
	},
}
</script>

<style scoped>
.actions-section {
	margin-top: 16px;
}

.pos-lookup-link {
	color: var(--color-primary-element);
	text-decoration: underline;
}
</style>

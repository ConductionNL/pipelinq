<template>
	<!-- Board PqBeheer: the library admin shell draws the title, the path and
	     the Documentatie button, then every settings section as a card in two
	     columns. The version card comes first; the sections follow in the
	     board's order (register, lead sources, request channels, lead
	     management, product categories, menu structure, skills, agent
	     profiles), then every other section the page has. -->
	<CnAdminSettingsShell
		appId="pipelinq"
		appName="Pipelinq"
		look="board"
		:title="t('pipelinq', 'Pipelinq settings')"
		:breadcrumbs="breadcrumbs"
		:breadcrumbNote="t('pipelinq', 'Set up your Pipelinq installation')"
		docUrl="https://pipelinq.conduction.nl/docs/intro"
		:appVersion="appVersion"
		:versionTitle="t('pipelinq', 'Version Information')"
		:versionDescription="
			t('pipelinq', 'Information about the current Pipelinq installation')
		"
		:reimportUrl="reimportUrl"
		:showSetup="isAdmin"
		:setupSteps="setupSteps"
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
			:addLabel="t('pipelinq', '+ Add Source')"
			:addPlaceholder="t('pipelinq', 'Enter source name…')"
			:tagLabel="leadSourceLabel"
			:remove="removeLeadSource"
			@add="addLeadSource"
			@rename="renameLeadSource" />

		<!-- Request Channels -->
		<TagManager
			v-if="isConfigured"
			:title="t('pipelinq', 'Request Channels')"
			:tags="requestChannelTags"
			:loading="requestChannelsLoading"
			:addLabel="t('pipelinq', '+ Add Channel')"
			:addPlaceholder="t('pipelinq', 'Enter channel name…')"
			:remove="removeRequestChannel"
			@add="addRequestChannel"
			@rename="renameRequestChannel" />

		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.leadManagement"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Product Categories -->
		<CnSettingsSection
			v-if="isConfigured"
			:name="t('pipelinq', 'Product Categories')">
			<CnIndexPage :key="`productCategory-${listKey}`" v-bind="lists.productCategory" />
		</CnSettingsSection>

		<!-- Menu structure: simple (default) or full, and the modules
		     (simple-structure-profile). -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.menuStructure"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Skill Management -->
		<CnSettingsSection
			v-if="isConfigured"
			:name="t('pipelinq', 'Skills')"
			:description="
				t(
					'pipelinq',
					'Define skills for routing requests to the right agents',
				)
			">
			<CnIndexPage :key="`skill-${listKey}`" v-bind="lists.skill" />
		</CnSettingsSection>

		<!-- Agent Profile Management -->
		<CnSettingsSection
			v-if="isConfigured"
			:name="t('pipelinq', 'Agent Profiles')"
			:description="
				t(
					'pipelinq',
					'Assign skills and configure routing for agents',
				)
			">
			<CnIndexPage :key="`agentProfile-${listKey}`" v-bind="lists.agentProfile" />
		</CnSettingsSection>

		<!-- Provisioning is an admin action, never a wizard step
		     (pipelinq-setup-wizard-review). -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.provisionData"
			appId="pipelinq" />

		<!-- Shillinq and XWiki, detected rather than typed. -->
		<DetectedIntegrations v-if="isAdmin" />

		<!-- The VAT rate per VAT class (pipelinq-forms-review). -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.vatRates"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Pipeline Management -->
		<PipelineManager v-if="isConfigured" />

		<CnSettingsFieldsSection
			v-if="isConfigured"
			v-bind="sections.forecast"
			appId="pipelinq" />

		<CnSettingsFieldsSection
			v-if="isConfigured"
			v-bind="sections.prospect"
			appId="pipelinq" />

		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.exportConfiguration"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Mailing list signup embed (marketing-lists-and-double-opt-in) -->
		<MailingListEmbedSettings v-if="isConfigured" />

		<!-- Shillinq integration. Nobody types a webhook URL: the hand-offs
		     follow the detected Shillinq app (pipelinq-audit-admin-forms-pos).
		     Without Shillinq, DetectedIntegrations says it is not installed. -->
		<CnSettingsFieldsSection
			v-if="isAdmin && shillinqInstalled"
			v-bind="sections.shillinq"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- xWiki Integration (xwiki-integration). Whether and how xWiki is
		     connected is shown once, in DetectedIntegrations. -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.xwiki"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Channels & telephony. Configuration, not an operator surface, so it
		     lives here rather than in the app nav (nav-ia-cleanup). -->
		<MessagingSettings v-if="isAdmin && isConfigured" />
		<DeliverabilitySettings v-if="isAdmin && isConfigured" />
		<CtiPage v-if="isAdmin && isConfigured" />

		<!-- Customer portal tenant config, accounts and audit trail
		     (pipelinq#2041). Admin only, like the endpoints it calls. -->
		<PortalSettings v-if="isAdmin && isConfigured" />

		<!-- Point of Sale configuration. Payment providers (who processes the
		     money) and tender types (how the customer pays at the register) read
		     as duplicates but are two different things. -->
		<PaymentSettingsForm v-if="isAdmin && isConfigured" />
		<CnSettingsFieldsSection
			v-if="isAdmin && isConfigured"
			v-bind="sections.posCustomer"
			appId="pipelinq" />
		<CnSettingsSection
			v-if="isAdmin && isConfigured"
			:name="t('pipelinq', 'POS tender types')"
			:description="
				t(
					'pipelinq',
					'How a customer can pay at the till (cash, pin, voucher) and the GL account each one posts to. Distinct from the payment providers above, which process the money.',
				)
			">
			<CnIndexPage :key="`posTenderType-${listKey}`" v-bind="lists.posTenderType" />
		</CnSettingsSection>
		<CnSettingsSection
			v-if="isAdmin && isConfigured"
			:name="t('pipelinq', 'POS staff')"
			:description="
				t(
					'pipelinq',
					'Cashiers who can sign in at the register, and the POS role each one holds.',
				)
			">
			<CnIndexPage :key="`posStaff-${listKey}`" v-bind="lists.posStaff" />
		</CnSettingsSection>
		<CnSettingsSection
			v-if="isAdmin && isConfigured"
			:name="t('pipelinq', 'POS roles')"
			:description="
				t(
					'pipelinq',
					'Permission sets a cashier can hold at the register: voiding, refunds, opening the cash drawer.',
				)
			">
			<CnIndexPage :key="`posRole-${listKey}`" v-bind="lists.posRole" />
		</CnSettingsSection>

		<!-- Marketing traffic (marketing-campaign-attribution): campaign
		     parameters on blast links, the Portaliq portal, Search Console. -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.marketingTraffic"
			appId="pipelinq"
			@saved="onSectionSaved" />

		<!-- Marketing intelligence (marketing-search-intelligence): the
		     sources the keyword, Matomo and competitor reads leave through,
		     and the relevance switch. No secret is entered here: the Matomo
		     token lives in the credential broker. -->
		<CnSettingsFieldsSection
			v-if="isAdmin"
			v-bind="sections.marketingIntel"
			appId="pipelinq"
			@saved="onSectionSaved" />

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
	</CnAdminSettingsShell>

	<!-- The admin page has no CnAppRoot, so it mounts the modals its lists
	     open (a row, Add, a row action). Closing one reads the lists again. -->
	<component
		:is="adminModalComponent"
		v-if="adminModalComponent"
		v-bind="adminModal.props"
		@close="closeAdminModal"
		@done="closeAdminModal"
		@saved="closeAdminModal" />
</template>

<script>
import {
	CnActionDialog,
	CnAdminSettingsShell,
	CnIndexPage,
	CnRegisterMapping,
	CnSettingsFieldsSection,
	CnSettingsSection,
} from '@conduction/nextcloud-vue'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import { NcNoteCard } from '@nextcloud/vue'
import PortalSettings from '../../components/admin/PortalSettings.vue'
import PosRoleFormDialog from '../../dialogs/PosRoleFormDialog.vue'
import PosStaffFormDialog from '../../dialogs/PosStaffFormDialog.vue'
import PosTenderTypeFormDialog from '../../modals/PosTenderTypeFormDialog.vue'
import CtiPage from './CtiPage.vue'
// marketing-mail-transports: transport list + SPF/DKIM/DMARC panel.
import DeliverabilitySettings from './DeliverabilitySettings.vue'
import DetectedIntegrations from './DetectedIntegrations.vue'
import MailingListEmbedSettings from './MailingListEmbedSettings.vue'
// Configuration surfaces moved off the app nav onto this admin page
// (nav-ia-cleanup): channels, telephony, and the POS master-data.
import MessagingSettings from './MessagingSettings.vue'
import PaymentSettingsForm from './PaymentSettingsForm.vue'
import PipelineManager from './PipelineManager.vue'
import TagManager from './TagManager.vue'
import { objectTypeGroups, objectTypes } from '../../config/objectTypes.js'
import manifest from '../../manifest.json'
import { leadSourceLabel } from '../../services/leadSourceLabels.js'
import { useLeadSourcesStore } from '../../store/modules/leadSources.js'
import { useObjectStore } from '../../store/modules/object.js'
import { useRequestChannelsStore } from '../../store/modules/requestChannels.js'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useSkillsStore } from '../../store/modules/skills.js'
import { adminLists } from './adminLists.js'
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
 * The modals the page's lists open, by the name a declaration uses.
 *
 * @type {Record<string, object>}
 */
const ADMIN_MODALS = {
	CnActionDialog,
	PosRoleFormDialog,
	PosStaffFormDialog,
	PosTenderTypeFormDialog,
}

export default {
	name: 'PipelinqAdminSettings',
	components: {
		MessagingSettings,
		DeliverabilitySettings,
		CtiPage,
		PaymentSettingsForm,
		PortalSettings,
		CnAdminSettingsShell,
		CnIndexPage,
		CnRegisterMapping,
		CnSettingsFieldsSection,
		CnSettingsSection,
		NcNoteCard,
		PipelineManager,
		TagManager,
		MailingListEmbedSettings,
		DetectedIntegrations,
	},

	provide() {
		return {
			cnOpenModal: (key, props = {}) => this.openAdminModal(key, props),
		}
	},

	data() {
		return {
			config: {},
			// The modal a list on this page opened: `{ key, props }`, or null.
			adminModal: null,
			// Bumped when a modal closes, so the lists read their rows again.
			listKey: 0,
			appVersion: loadState('pipelinq', 'version', 'Unknown'),
			// Board PqBeheer: the path under the title. The first crumb goes back
			// to the server's administration settings.
			breadcrumbs: [
				{
					label: t('pipelinq', 'Administration settings'),
					href: generateUrl('/settings/admin'),
				},
				{ label: 'Pipelinq' },
			],

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
		}
	},

	computed: {
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

		/**
		 * The page's lists, as CnIndexPage props.
		 *
		 * @return {Record<string, object>}
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-lists-are-declared-index-pages
		 */
		lists() {
			const registry = this.objectStore.objectTypeRegistry || {}
			return adminLists(
				t,
				(slug) => registry[slug],
				(key, props) => this.openAdminModal(key, props),
				useSkillsStore().skills || [],
			)
		},

		/**
		 * The component of the open modal, or null.
		 *
		 * @return {object|null}
		 */
		adminModalComponent() {
			return this.adminModal ? ADMIN_MODALS[this.adminModal.key] || null : null
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
			useSkillsStore().fetchSkills()
			this.leadSourcesStore.fetchSources()
			this.requestChannelsStore.fetchChannels()
		}
	},

	methods: {
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
		 * Open one of the page's modals; an unknown name opens nothing.
		 *
		 * @param {string} key The modal's name.
		 * @param {object} props Its props.
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-lists-are-declared-index-pages
		 */
		openAdminModal(key, props = {}) {
			if (!ADMIN_MODALS[key]) {
				return
			}
			this.adminModal = { key, props }
		},

		/**
		 * Close the open modal and read the lists again.
		 *
		 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-lists-are-declared-index-pages
		 */
		closeAdminModal() {
			this.adminModal = null
			this.listKey += 1
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

	},
}
</script>

<style scoped>
.actions-section {
	margin-top: 16px;
}
</style>

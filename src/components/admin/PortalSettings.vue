<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Customer portal admin section (pipelinq#2041), an overview of every portal
  - on the install (openspec portal-tenants-on-one-admin-tab): the portal list,
  - the selected portal's form, the service account and the latest portal
  - actions across portals; the Auditlog tab holds the selected portal's
  - accounts and its full audit trail.
  -
  - The portal tenant config decides which tabs a resident sees, the branding,
  - the domain, B2B or B2C mode and the support contacts. It had an admin API
  - and no screen. This section reads the full config back first (GET), because
  - the save replaces the whole record, then lists the tenant's portal accounts
  - and its most recent audit events. It lives in the admin settings, not in
  - the in-app router (ADR-004), and every endpoint it calls is admin only.
-->
<template>
	<NcSettingsSection
		:name="t('pipelinq', 'Customer portal')"
		:description="
			t(
				'pipelinq',
				'Choose what residents and businesses see in the customer portal, how it looks and where it lives, and review its accounts and audit trail.',
			)
		">
		<div class="portal-settings">
			<div class="portal-tabs" role="tablist">
				<button
					type="button"
					role="tab"
					data-testid="portal-tab-tenants"
					:aria-selected="activeTab === 'tenants' ? 'true' : 'false'"
					class="portal-tab"
					:class="{ 'portal-tab--active': activeTab === 'tenants' }"
					@click="activeTab = 'tenants'">
					{{ t('pipelinq', 'Portals') }}
					<span class="portal-tab__count">{{ tenants.length }}</span>
				</button>
				<button
					type="button"
					role="tab"
					data-testid="portal-tab-audit"
					:aria-selected="activeTab === 'audit' ? 'true' : 'false'"
					class="portal-tab"
					:class="{ 'portal-tab--active': activeTab === 'audit' }"
					@click="activeTab = 'audit'">
					{{ t('pipelinq', 'Audit log') }}
					<span class="portal-tab__count">{{ recentTotal }}</span>
				</button>
			</div>

			<template v-if="activeTab === 'tenants'">
				<section class="portal-card">
					<header class="portal-card__head">
						<h3>{{ t('pipelinq', 'Portals') }}</h3>
						<NcButton data-testid="portal-add" @click="addTenant">
							<template #icon>
								<Plus :size="20" />
							</template>
							{{ t('pipelinq', 'Add portal') }}
						</NcButton>
					</header>
					<p v-if="tenants.length === 0" class="portal-empty">
						{{ t('pipelinq', 'No portals yet. Add one to give residents a portal on its own domain.') }}
					</p>
					<table v-else class="portal-table portal-table--tenants">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Portal') }}</th>
								<th scope="col">{{ t('pipelinq', 'Domain') }}</th>
								<th scope="col">{{ t('pipelinq', 'Colour') }}</th>
								<th scope="col">{{ t('pipelinq', 'Tabs') }}</th>
								<th scope="col">{{ t('pipelinq', 'Status') }}</th>
								<th scope="col">{{ t('pipelinq', 'Actions') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr
								v-for="tenant in tenants"
								:key="tenant.tenantId"
								data-testid="portal-tenant-row"
								:class="{ 'portal-table__row--selected': tenant.tenantId === tenantId }"
								@click="selectTenant(tenant.tenantId)">
								<td class="portal-table__name">{{ tenant.displayName || tenant.tenantId }}</td>
								<td>{{ tenant.customDomain || tenant.subdomain || '' }}</td>
								<td>
									<span class="portal-colour">
										<span class="portal-colour__swatch" :style="{ background: tenant.brandPrimaryColor }" />
										{{ tenant.brandPrimaryColor }}
									</span>
								</td>
								<td>{{ featureWords(tenant.enabledFeatures) }}</td>
								<td>
									<span class="portal-status" :class="'portal-status--' + statusOf(tenant)">
										{{ statusLabel(statusOf(tenant)) }}
									</span>
								</td>
								<td @click.stop>
									<NcActions :ariaLabel="t('pipelinq', 'Row actions')">
										<NcActionButton @click="selectTenant(tenant.tenantId)">
											<template #icon>
												<Pencil :size="20" />
											</template>
											{{ t('pipelinq', 'Edit') }}
										</NcActionButton>
									</NcActions>
								</td>
							</tr>
						</tbody>
					</table>
				</section>

				<NcLoadingIcon v-if="loading" :size="24" />

				<section v-else-if="form" class="portal-card" data-testid="portal-form">
					<header class="portal-card__head">
						<div>
							<h3>{{ form.displayName || t('pipelinq', 'New portal') }}</h3>
							<p class="portal-help">
								{{ t('pipelinq', 'The portal residents see. Changes show after you save.') }}
							</p>
						</div>
						<span class="portal-status" :class="'portal-status--' + statusOf(form)">
							{{ statusLabel(statusOf(form)) }}
						</span>
					</header>

					<NcNoteCard v-if="!configured && !adding" type="info">
						{{
							t(
								'pipelinq',
								'This tenant has no saved configuration yet. The portal uses the defaults shown below until you save.',
							)
						}}
					</NcNoteCard>

					<div class="portal-grid">
						<div class="portal-field">
							<label for="portal-display-name">{{ t('pipelinq', 'Name') }}</label>
							<input id="portal-display-name" v-model="form.displayName" type="text">
						</div>
						<div class="portal-field">
							<label for="portal-custom-domain">{{ t('pipelinq', 'Domain') }}</label>
							<input
								id="portal-custom-domain"
								v-model.trim="form.customDomain"
								type="text"
								placeholder="portaal.example.nl">
							<span class="portal-help portal-help--small">
								{{ t('pipelinq', 'Point the DNS of this domain at this install; the portal is found by its domain.') }}
							</span>
						</div>
						<div class="portal-field">
							<label for="portal-primary-color">{{ t('pipelinq', 'Main colour') }}</label>
							<span class="portal-colour-input">
								<span class="portal-colour__swatch" :style="{ background: form.brandPrimaryColor }" />
								<input
									id="portal-primary-color"
									v-model="form.brandPrimaryColor"
									type="text"
									maxlength="7">
							</span>
						</div>
						<div class="portal-field">
							<label for="portal-status">{{ t('pipelinq', 'Status') }}</label>
							<select id="portal-status" v-model="form.status">
								<option value="live">{{ statusLabel('live') }}</option>
								<option value="draft">{{ statusLabel('draft') }}</option>
							</select>
						</div>
					</div>

					<fieldset class="portal-group">
						<legend>{{ t('pipelinq', 'Tabs residents see') }}</legend>
						<NcCheckboxRadioSwitch
							v-for="feature in featureOptions"
							:key="feature.key"
							class="portal-chip"
							:modelValue="hasFeature(feature.key)"
							@update:modelValue="toggleFeature(feature.key, $event)">
							{{ feature.label }}
						</NcCheckboxRadioSwitch>
					</fieldset>

					<details class="portal-more">
						<summary>{{ t('pipelinq', 'More settings') }}</summary>
						<fieldset class="portal-group">
							<legend>{{ t('pipelinq', 'Audience') }}</legend>
							<NcCheckboxRadioSwitch v-model="form.b2cEnabled">
								{{ t('pipelinq', 'Residents (B2C)') }}
							</NcCheckboxRadioSwitch>
							<NcCheckboxRadioSwitch v-model="form.b2bEnabled">
								{{ t('pipelinq', 'Businesses (B2B)') }}
							</NcCheckboxRadioSwitch>
						</fieldset>
						<div class="portal-grid">
							<div class="portal-field">
								<label for="portal-background-color">{{ t('pipelinq', 'Background colour (hex)') }}</label>
								<input id="portal-background-color" v-model="form.brandBackgroundColor" type="text" maxlength="7">
							</div>
							<div class="portal-field">
								<label for="portal-subdomain">{{ t('pipelinq', 'Subdomain') }}</label>
								<input id="portal-subdomain" v-model.trim="form.subdomain" type="text">
							</div>
							<div class="portal-field">
								<label for="portal-support-email">{{ t('pipelinq', 'Support email') }}</label>
								<input id="portal-support-email" v-model.trim="form.supportEmail" type="email" autocomplete="off">
							</div>
							<div class="portal-field">
								<label for="portal-support-phone">{{ t('pipelinq', 'Support phone') }}</label>
								<input id="portal-support-phone" v-model.trim="form.supportPhone" type="tel" autocomplete="off">
							</div>
						</div>
					</details>

					<NcNoteCard v-if="message" :type="messageType">
						{{ message }}
					</NcNoteCard>

					<div class="portal-actions">
						<NcButton variant="primary" :disabled="saving" @click="save">
							<template #icon>
								<NcLoadingIcon v-if="saving" :size="20" />
								<Check v-else :size="20" />
							</template>
							{{ saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save portal') }}
						</NcButton>
					</div>
				</section>

				<NcNoteCard v-else-if="message" :type="messageType">
					{{ message }}
				</NcNoteCard>

				<section class="portal-card">
					<ServiceAccountPicker
						:url="adminUrl('service-account')"
						inputId="portal-service-account"
						:legend="t('pipelinq', 'Portal service account')"
						:help="
							(group) =>
								t(
									'pipelinq',
									'Residents do not have a Nextcloud account, so the portal saves logins, password resets and the audit trail as this account. It joins the group {group}, which may only create and change portal records.',
									{ group },
								)
						"
						:consequence="
							t(
								'pipelinq',
								'Until you choose one, residents cannot log in or reset their password.',
							)
						"
						:savedMessage="
							(user) =>
								t('pipelinq', 'The portal now saves as {user}.', { user })
						"
						:loadError="
							t('pipelinq', 'Could not load the portal service account.')
						"
						:saveError="
							t('pipelinq', 'Could not save the portal service account.')
						"
						defaultGroup="pipelinq-portal-service" />
				</section>

				<section class="portal-card">
					<header class="portal-card__head">
						<div>
							<h3>{{ t('pipelinq', 'Latest actions in the portal') }}</h3>
							<p class="portal-help">
								{{ t('pipelinq', 'Every action of a portal user is recorded and cannot be changed. The full log is on the Audit log tab.') }}
							</p>
						</div>
						<NcButton data-testid="portal-recent-download" :disabled="recent.length === 0" @click="downloadRecent">
							<template #icon>
								<Download :size="20" />
							</template>
							{{ t('pipelinq', 'Download') }}
						</NcButton>
					</header>
					<p v-if="recent.length === 0" class="portal-empty">
						{{ t('pipelinq', 'No portal actions yet.') }}
					</p>
					<table v-else class="portal-table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'When') }}</th>
								<th scope="col">{{ t('pipelinq', 'Portal user') }}</th>
								<th scope="col">{{ t('pipelinq', 'Portal') }}</th>
								<th scope="col">{{ t('pipelinq', 'Action') }}</th>
								<th scope="col">{{ t('pipelinq', 'Object') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(row, index) in recentRows" :key="index" data-testid="portal-recent-row">
								<td>{{ row.when }}</td>
								<td>{{ row.user }}</td>
								<td>{{ row.portal }}</td>
								<td>{{ row.action }}</td>
								<td>{{ row.object }}</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>

			<template v-else>
				<section class="portal-card">
					<h3>{{ t('pipelinq', 'Portal accounts') }}</h3>
					<p v-if="accounts.length === 0" class="portal-empty">
						{{ t('pipelinq', 'No portal accounts for this tenant yet.') }}
					</p>
					<table v-else class="portal-table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Name') }}</th>
								<th scope="col">{{ t('pipelinq', 'Email') }}</th>
								<th scope="col">{{ t('pipelinq', 'Type') }}</th>
								<th scope="col">{{ t('pipelinq', 'Status') }}</th>
								<th scope="col">{{ t('pipelinq', 'Last login') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="account in accounts" :key="account.id">
								<td>{{ account.displayName }}</td>
								<td>{{ account.email }}</td>
								<td>{{ account.accountType }}</td>
								<td>{{ account.status }}</td>
								<td>{{ account.lastLoginAt }}</td>
							</tr>
						</tbody>
					</table>
				</section>

				<section class="portal-card">
					<h3>{{ t('pipelinq', 'Recent audit events') }}</h3>
					<p v-if="tenantEvents.length === 0" class="portal-empty">
						{{ t('pipelinq', 'No audit events for this tenant yet.') }}
					</p>
					<table v-else class="portal-table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'When') }}</th>
								<th scope="col">{{ t('pipelinq', 'Event') }}</th>
								<th scope="col">{{ t('pipelinq', 'Outcome') }}</th>
								<th scope="col">{{ t('pipelinq', 'Account') }}</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="(event, index) in tenantEvents" :key="index">
								<td>{{ event.occurredAt }}</td>
								<td>{{ event.eventType }}</td>
								<td>{{ event.outcome }}</td>
								<td>{{ event.accountId }}</td>
							</tr>
						</tbody>
					</table>
				</section>
			</template>
		</div>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcActionButton,
	NcActions,
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
} from '@nextcloud/vue'
import Check from 'vue-material-design-icons/Check.vue'
import Download from 'vue-material-design-icons/Download.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { downloadCsv } from '../../services/csvExport.js'
import ServiceAccountPicker from './ServiceAccountPicker.vue'

/** How many audit events the section shows. */
const RECENT_EVENT_LIMIT = 20

/** The status a tenant without one has. */
const DEFAULT_STATUS = 'live'

/**
 * A tenant id made from a portal name: lower case, words joined by hyphens.
 *
 * @param {string} name The portal name.
 * @return {string} The id, or an empty string for a name without letters or digits.
 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
 */
export function tenantIdFromName(name) {
	return String(name || '')
		.normalize('NFKD')
		.replace(/[̀-ͯ]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

export default {
	name: 'PortalSettings',
	components: {
		Check,
		Download,
		NcActionButton,
		NcActions,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		Pencil,
		Plus,
		ServiceAccountPicker,
	},

	data() {
		return {
			activeTab: 'tenants',
			tenants: [],
			tenantId: 'default',
			adding: false,
			loading: true,
			saving: false,
			configured: false,
			form: null,
			accounts: [],
			events: [],
			recent: [],
			recentTotal: 0,
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The portal tabs an admin can switch on, keyed as PortalTenantService stores them.
		 *
		 * @return {Array<{key: string, label: string}>} The options.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		featureOptions() {
			return [
				{ key: 'requests', label: t('pipelinq', 'Requests') },
				{ key: 'appointments', label: t('pipelinq', 'Appointments') },
				{ key: 'invoices', label: t('pipelinq', 'Invoices') },
				{ key: 'documents', label: t('pipelinq', 'Documents') },
				{ key: 'contracts', label: t('pipelinq', 'Contracts') },
				{ key: 'signing', label: t('pipelinq', 'Signing') },
				{ key: 'orders', label: t('pipelinq', 'Orders') },
				{ key: 'profile', label: t('pipelinq', 'Profile') },
			]
		},

		/**
		 * The selected tenant's most recent audit events (newest first).
		 *
		 * @return {Array<object>} The events to show.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
		 */
		tenantEvents() {
			return this.events.slice(0, RECENT_EVENT_LIMIT)
		},

		/**
		 * The latest actions across portals, as the table shows them.
		 *
		 * @return {Array<object>} when, user, portal, action and object per event.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
		 */
		recentRows() {
			return this.recent.map((event) => ({
				when: this.formatWhen(event.occurredAt),
				user: event.accountName || event.accountId || '',
				portal: this.portalName(event.tenantId),
				action: event.eventType || '',
				object: event.details?.object || event.objectId || '',
			}))
		},
	},

	/**
	 * Load the portals, the first portal and the latest actions on open (the
	 * service account picker loads itself).
	 *
	 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
	 */
	mounted() {
		this.loadOverview()
	},

	methods: {
		t,

		/**
		 * Build an admin portal API url.
		 *
		 * @param {string} path The path below /portal/api/admin/.
		 * @return {string} The url.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		adminUrl(path) {
			return generateUrl('/apps/pipelinq/portal/api/admin/' + path)
		},

		/**
		 * Read the portal list and the latest actions, then open the first portal.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		async loadOverview() {
			await Promise.all([this.loadTenants(), this.loadRecent()])
			this.tenantId = this.tenants[0]?.tenantId || 'default'
			await this.loadAll()
		},

		/**
		 * Read every stored portal. A failed read leaves the list empty; the
		 * default tenant still opens.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		async loadTenants() {
			try {
				const response = await axios.get(this.adminUrl('tenants'))
				this.tenants = Array.isArray(response.data?.tenants) ? response.data.tenants : []
			} catch {
				this.tenants = []
			}
		},

		/**
		 * Read the latest actions of every portal and the log total.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
		 */
		async loadRecent() {
			try {
				const response = await axios.get(this.adminUrl('audit-events'), {
					params: { all: '1', limit: RECENT_EVENT_LIMIT },
				})
				this.recent = Array.isArray(response.data?.events) ? response.data.events : []
				this.recentTotal = Number(response.data?.total ?? this.recent.length)
			} catch {
				this.recent = []
				this.recentTotal = 0
			}
		},

		/**
		 * Open a portal from the list.
		 *
		 * @param {string} tenantId The portal's tenant id.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		async selectTenant(tenantId) {
			this.adding = false
			this.tenantId = tenantId
			await this.loadAll()
		},

		/**
		 * Open an empty form for a new portal; saving creates it.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		addTenant() {
			this.adding = true
			this.tenantId = ''
			this.configured = false
			this.message = ''
			this.accounts = []
			this.events = []
			this.form = {
				displayName: '',
				customDomain: '',
				brandPrimaryColor: '#21468B',
				brandBackgroundColor: '#FFFFFF',
				enabledFeatures: ['requests'],
				b2bEnabled: false,
				b2cEnabled: true,
				status: 'draft',
			}
		},

		/**
		 * Load the selected tenant's config, its accounts and its audit events.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		async loadAll() {
			this.loading = true
			this.message = ''
			const params = { tenantId: this.tenantId || 'default' }
			try {
				const [config, accounts, events] = await Promise.all([
					axios.get(this.adminUrl('tenant-config'), { params }),
					axios.get(this.adminUrl('accounts'), { params }),
					axios.get(this.adminUrl('audit-events'), { params }),
				])
				const configBody = config.data || {}
				this.configured = configBody.configured === true
				this.form = {
					enabledFeatures: [],
					...(configBody.config || {}),
				}
				this.form.status = this.statusOf(this.form)
				this.accounts = Array.isArray(accounts.data?.accounts)
					? accounts.data.accounts
					: []
				this.events = Array.isArray(events.data?.events)
					? events.data.events
					: []
			} catch {
				this.form = null
				this.message = t(
					'pipelinq',
					'Could not load the customer portal configuration.',
				)
				this.messageType = 'error'
			} finally {
				this.loading = false
			}
		},

		/**
		 * A tenant's status; a record without one is live.
		 *
		 * @param {object} tenant The tenant config.
		 * @return {string} live or draft.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-a-portal-can-be-prepared-before-it-goes-live
		 */
		statusOf(tenant) {
			return tenant?.status || DEFAULT_STATUS
		},

		/**
		 * @param {string} status live or draft.
		 * @return {string} Its label.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-a-portal-can-be-prepared-before-it-goes-live
		 */
		statusLabel(status) {
			return status === 'draft' ? t('pipelinq', 'Draft') : t('pipelinq', 'Live')
		},

		/**
		 * The tabs a tenant shows, in words, in the order the form lists them.
		 *
		 * @param {Array<string>} features The enabled feature keys.
		 * @return {string} The labels joined by commas.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		featureWords(features) {
			const enabled = Array.isArray(features) ? features : []
			return this.featureOptions
				.filter((option) => enabled.includes(option.key))
				.map((option) => option.label)
				.join(', ')
		},

		/**
		 * A tenant's name for the actions table.
		 *
		 * @param {string} tenantId The tenant id.
		 * @return {string} Its display name, or the id.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
		 */
		portalName(tenantId) {
			const tenant = this.tenants.find((row) => row.tenantId === tenantId)
			return tenant?.displayName || tenantId || ''
		},

		/**
		 * A moment as "today 10:14" or a short date and time.
		 *
		 * @param {string} iso An ISO date-time.
		 * @return {string} The moment in words.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
		 */
		formatWhen(iso) {
			const date = new Date(iso)
			if (!iso || Number.isNaN(date.getTime())) {
				return iso || ''
			}
			const time = date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
			if (date.toDateString() === new Date().toDateString()) {
				return t('pipelinq', 'today {time}', { time })
			}
			return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' }) + ' ' + time
		},

		/**
		 * Download the shown actions as CSV.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-shows-the-latest-portal-actions-across-portals
		 */
		downloadRecent() {
			downloadCsv('portal-actions.csv', this.recentRows, ['when', 'user', 'portal', 'action', 'object'])
		},

		/**
		 * Whether a portal tab is switched on.
		 *
		 * @param {string} key The feature key.
		 * @return {boolean} True when enabled.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		hasFeature(key) {
			return (this.form?.enabledFeatures || []).includes(key)
		},

		/**
		 * Switch a portal tab on or off.
		 *
		 * @param {string} key The feature key.
		 * @param {boolean} enabled Whether it should be on.
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		toggleFeature(key, enabled) {
			const current = (this.form.enabledFeatures || []).filter(
				(feature) => feature !== key,
			)
			this.form.enabledFeatures = enabled ? [...current, key] : current
		},

		/**
		 * Save the whole tenant config (the server replaces the record). A new
		 * portal is stored under an id made from its name.
		 *
		 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
		 */
		async save() {
			const tenantId = this.adding
				? tenantIdFromName(this.form.displayName)
				: this.tenantId || 'default'
			if (tenantId === '') {
				this.message = t('pipelinq', 'Give the portal a name first.')
				this.messageType = 'error'
				return
			}
			this.saving = true
			this.message = ''
			try {
				const response = await axios.post(
					this.adminUrl('tenant-config'),
					{ config: this.form },
					{ params: { tenantId } },
				)
				this.form = { enabledFeatures: [], ...(response.data || this.form) }
				this.configured = true
				this.adding = false
				this.tenantId = tenantId
				this.message = t('pipelinq', 'Customer portal configuration saved.')
				this.messageType = 'success'
				await this.loadTenants()
			} catch (error) {
				this.message =
					error?.response?.data?.message
					|| t(
						'pipelinq',
						'Could not save the customer portal configuration.',
					)
				this.messageType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.portal-settings {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.portal-tabs {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
}

.portal-tab {
	display: inline-flex;
	align-items: center;
	gap: 8px;
	min-height: 40px;
	padding: 0 14px;
	border: 0;
	border-bottom: 2px solid transparent;
	background: none;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
}

.portal-tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
}

.portal-tab__count {
	padding: 0 8px;
	border-radius: 10px;
	background: var(--color-background-dark);
	font-size: 12px;
}

.portal-card {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 18px 22px;
	border: 1px solid var(--color-border);
	border-radius: 12px;
	background: var(--color-main-background);
}

.portal-card h3 {
	margin: 0;
	font-size: 17px;
}

.portal-card__head {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 16px;
}

.portal-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
}

.portal-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.portal-field label {
	font-weight: 600;
	font-size: 13px;
}

.portal-field input,
.portal-field select {
	height: 40px;
	padding: 0 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-large, 8px);
}

.portal-colour,
.portal-colour-input {
	display: inline-flex;
	align-items: center;
	gap: 8px;
}

.portal-colour-input input {
	flex: 1;
}

.portal-colour__swatch {
	display: inline-block;
	width: 16px;
	height: 16px;
	border-radius: 3px;
	border: 1px solid var(--color-border);
}

.portal-status {
	display: inline-block;
	padding: 2px 10px;
	border-radius: 10.5px;
	font-size: 13px;
	font-weight: 600;
}

.portal-status--live {
	background: var(--color-success-hover, #e7f3ea);
	color: var(--color-success-text);
}

.portal-status--draft {
	background: var(--color-warning-hover, #fff4de);
	color: var(--color-warning-text);
}

.portal-group {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	border: 0;
	padding: 0;
	margin: 0;
}

.portal-group legend {
	font-weight: 600;
	font-size: 13px;
	margin-bottom: 8px;
}

.portal-chip {
	padding: 0 10px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large, 8px);
}

.portal-more summary {
	cursor: pointer;
	font-weight: 600;
	margin-bottom: 12px;
}

.portal-help {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.portal-help--small {
	font-size: 13px;
}

.portal-actions {
	display: flex;
	justify-content: flex-end;
}

.portal-empty {
	color: var(--color-text-maxcontrast);
}

.portal-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.portal-table th,
.portal-table td {
	text-align: start;
	padding: 12px;
	border-bottom: 1px solid var(--color-border);
}

.portal-table th {
	padding-block: 10px;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
}

.portal-table__name {
	font-weight: 600;
}

.portal-table--tenants tbody tr {
	cursor: pointer;
}

.portal-table__row--selected {
	background: var(--color-background-hover);
}
</style>

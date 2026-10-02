<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Customer portal admin section (pipelinq#2041).
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
			<div class="portal-row">
				<div class="portal-field">
					<label for="portal-tenant-id">{{
						t('pipelinq', 'Tenant')
					}}</label>
					<input
						id="portal-tenant-id"
						v-model.trim="tenantId"
						type="text"
						@keyup.enter="loadAll" />
				</div>
				<div class="portal-field portal-field--action">
					<NcButton :disabled="loading" @click="loadAll">
						{{ t('pipelinq', 'Load') }}
					</NcButton>
				</div>
			</div>

			<NcLoadingIcon v-if="loading" :size="24" />

			<template v-else-if="form">
				<NcNoteCard v-if="!configured" type="info">
					{{
						t(
							'pipelinq',
							'This tenant has no saved configuration yet. The portal uses the defaults shown below until you save.',
						)
					}}
				</NcNoteCard>

				<fieldset class="portal-group">
					<legend>{{ t('pipelinq', 'Portal tabs') }}</legend>
					<NcCheckboxRadioSwitch
						v-for="feature in featureOptions"
						:key="feature.key"
						:modelValue="hasFeature(feature.key)"
						@update:modelValue="toggleFeature(feature.key, $event)">
						{{ feature.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<fieldset class="portal-group">
					<legend>{{ t('pipelinq', 'Audience') }}</legend>
					<NcCheckboxRadioSwitch v-model="form.b2cEnabled">
						{{ t('pipelinq', 'Residents (B2C)') }}
					</NcCheckboxRadioSwitch>
					<NcCheckboxRadioSwitch v-model="form.b2bEnabled">
						{{ t('pipelinq', 'Businesses (B2B)') }}
					</NcCheckboxRadioSwitch>
				</fieldset>

				<div class="portal-row">
					<div class="portal-field">
						<label for="portal-display-name">{{
							t('pipelinq', 'Portal name')
						}}</label>
						<input
							id="portal-display-name"
							v-model="form.displayName"
							type="text" />
					</div>
					<div class="portal-field">
						<label for="portal-primary-color">{{
							t('pipelinq', 'Primary colour (hex)')
						}}</label>
						<input
							id="portal-primary-color"
							v-model="form.brandPrimaryColor"
							type="text"
							maxlength="7" />
					</div>
					<div class="portal-field">
						<label for="portal-background-color">{{
							t('pipelinq', 'Background colour (hex)')
						}}</label>
						<input
							id="portal-background-color"
							v-model="form.brandBackgroundColor"
							type="text"
							maxlength="7" />
					</div>
				</div>

				<div class="portal-row">
					<div class="portal-field">
						<label for="portal-custom-domain">{{
							t('pipelinq', 'Custom domain')
						}}</label>
						<input
							id="portal-custom-domain"
							v-model.trim="form.customDomain"
							type="text"
							placeholder="portaal.example.nl" />
					</div>
					<div class="portal-field">
						<label for="portal-subdomain">{{
							t('pipelinq', 'Subdomain')
						}}</label>
						<input
							id="portal-subdomain"
							v-model.trim="form.subdomain"
							type="text" />
					</div>
				</div>

				<div class="portal-row">
					<div class="portal-field">
						<label for="portal-support-email">{{
							t('pipelinq', 'Support email')
						}}</label>
						<input
							id="portal-support-email"
							v-model.trim="form.supportEmail"
							type="email"
							autocomplete="off" />
					</div>
					<div class="portal-field">
						<label for="portal-support-phone">{{
							t('pipelinq', 'Support phone')
						}}</label>
						<input
							id="portal-support-phone"
							v-model.trim="form.supportPhone"
							type="tel"
							autocomplete="off" />
					</div>
				</div>

				<NcNoteCard v-if="message" :type="messageType">
					{{ message }}
				</NcNoteCard>

				<div class="portal-actions">
					<NcButton variant="primary" :disabled="saving" @click="save">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="20" />
						</template>
						{{
							saving ? t('pipelinq', 'Saving…') : t('pipelinq', 'Save')
						}}
					</NcButton>
				</div>

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

				<h3>{{ t('pipelinq', 'Recent audit events') }}</h3>
				<p v-if="recentEvents.length === 0" class="portal-empty">
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
						<tr v-for="(event, index) in recentEvents" :key="index">
							<td>{{ event.occurredAt }}</td>
							<td>{{ event.eventType }}</td>
							<td>{{ event.outcome }}</td>
							<td>{{ event.accountId }}</td>
						</tr>
					</tbody>
				</table>
			</template>

			<NcNoteCard v-else-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</NcSettingsSection>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcLoadingIcon,
	NcNoteCard,
	NcSettingsSection,
} from '@nextcloud/vue'

/** How many audit events the section shows. */
const RECENT_EVENT_LIMIT = 20

export default {
	name: 'PortalSettings',
	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
	},

	data() {
		return {
			tenantId: 'default',
			loading: true,
			saving: false,
			configured: false,
			form: null,
			accounts: [],
			events: [],
			message: '',
			messageType: 'success',
		}
	},

	computed: {
		/**
		 * The portal tabs an admin can switch on, keyed as PortalTenantService stores them.
		 *
		 * @return {Array<{key: string, label: string}>} The options.
		 */
		featureOptions() {
			return [
				{ key: 'requests', label: t('pipelinq', 'Requests') },
				{ key: 'documents', label: t('pipelinq', 'Documents') },
				{ key: 'invoices', label: t('pipelinq', 'Invoices') },
				{ key: 'contracts', label: t('pipelinq', 'Contracts') },
				{ key: 'orders', label: t('pipelinq', 'Orders') },
				{ key: 'profile', label: t('pipelinq', 'Profile') },
			]
		},

		/**
		 * The most recent audit events (the API returns them newest first).
		 *
		 * @return {Array<object>} The events to show.
		 */
		recentEvents() {
			return this.events.slice(0, RECENT_EVENT_LIMIT)
		},
	},

	mounted() {
		this.loadAll()
	},

	methods: {
		t,

		/**
		 * Build an admin portal API url.
		 *
		 * @param {string} path The path below /portal/api/admin/.
		 * @return {string} The url.
		 */
		adminUrl(path) {
			return generateUrl('/apps/pipelinq/portal/api/admin/' + path)
		},

		/**
		 * Load the tenant config, its accounts and its audit events.
		 *
		 * @spec exclude the portal admin screen has no owning requirement; customer-portal
		 *   specifies only the widget-mode origin allow-list (pipelinq#2041)
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
		 * Whether a portal tab is switched on.
		 *
		 * @param {string} key The feature key.
		 * @return {boolean} True when enabled.
		 */
		hasFeature(key) {
			return (this.form?.enabledFeatures || []).includes(key)
		},

		/**
		 * Switch a portal tab on or off.
		 *
		 * @param {string} key The feature key.
		 * @param {boolean} enabled Whether it should be on.
		 */
		toggleFeature(key, enabled) {
			const current = (this.form.enabledFeatures || []).filter(
				(feature) => feature !== key,
			)
			this.form.enabledFeatures = enabled ? [...current, key] : current
		},

		/**
		 * Save the whole tenant config (the server replaces the record).
		 *
		 * @spec exclude the portal admin screen has no owning requirement; customer-portal
		 *   specifies only the widget-mode origin allow-list (pipelinq#2041)
		 */
		async save() {
			this.saving = true
			this.message = ''
			try {
				const response = await axios.post(
					this.adminUrl('tenant-config'),
					{ config: this.form },
					{ params: { tenantId: this.tenantId || 'default' } },
				)
				this.form = { enabledFeatures: [], ...(response.data || this.form) }
				this.configured = true
				this.message = t('pipelinq', 'Customer portal configuration saved.')
				this.messageType = 'success'
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
	gap: 12px;
	max-width: 900px;
}

.portal-row {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.portal-field {
	display: flex;
	flex-direction: column;
	gap: 2px;
	flex: 1 1 200px;
}

.portal-field--action {
	flex: 0 0 auto;
	justify-content: flex-end;
}

.portal-field label {
	font-weight: 600;
	font-size: 13px;
}

.portal-field input {
	padding: 6px 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
}

.portal-group {
	display: flex;
	flex-wrap: wrap;
	gap: 4px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	padding: 8px 12px;
}

.portal-group legend {
	font-weight: 600;
	font-size: 13px;
	padding: 0 4px;
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
}

.portal-table th,
.portal-table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border);
}
</style>

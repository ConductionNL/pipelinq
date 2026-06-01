<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<div class="pipelinq-admin-settings">
		<!-- Version info and register mapping (existing sections) -->
		<CnVersionInfoCard :app-id="appId" />

		<CnRegisterMapping :app-id="appId" />

		<!-- Objects API Access -->
		<CnSettingsSection
			:name="t('pipelinq', 'Objects API Access')"
			:description="t('pipelinq', 'Restrict which Nextcloud groups can access each object type via the Objecten API. Unrestricted schemas are accessible to all authenticated users.')">
			<div v-if="Object.keys(settings.objectenAccess || {}).length === 0" class="pipelinq-empty-state">
				{{ t('pipelinq', 'No schemas registered. Run re-import first.') }}
			</div>
			<table v-else class="pipelinq-access-table">
				<thead>
					<tr>
						<th>{{ t('pipelinq', 'Schema') }}</th>
						<th>{{ t('pipelinq', 'Allowed Groups') }}</th>
						<th>{{ t('pipelinq', 'Actions') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="(groupIds, slug) in settings.objectenAccess" :key="slug">
						<td class="schema-slug">{{ slug }}</td>
						<td>
							<NcSelect
								v-model="accessMap[slug]"
								:options="availableGroups"
								:placeholder="t('pipelinq', 'All authenticated users')"
								:multiple="true"
								label="displayName"
								track-by="id" />
						</td>
						<td>
							<NcButton :aria-label="t('pipelinq', 'Save access for {schema}', { schema: slug })"
								type="primary"
								@click="saveSchemaAccess(slug)">
								{{ t('pipelinq', 'Save') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</CnSettingsSection>

		<!-- REST API Authentication -->
		<CnSettingsSection
			:name="t('pipelinq', 'REST API Authentication')"
			:description="t('pipelinq', 'Configure API token and OAuth 2.0 authentication for external system integrations.')">
			<div class="pipelinq-tabs">
				<NcButton
					:class="{ active: activeAuthTab === 'tokens' }"
					type="tertiary"
					@click="activeAuthTab = 'tokens'">
					{{ t('pipelinq', 'Tokens') }}
				</NcButton>
				<NcButton
					:class="{ active: activeAuthTab === 'oauth' }"
					type="tertiary"
					@click="activeAuthTab = 'oauth'">
					{{ t('pipelinq', 'OAuth 2.0') }}
				</NcButton>
			</div>

			<!-- Token management tab -->
			<div v-if="activeAuthTab === 'tokens'" class="pipelinq-tab-content">
				<NcButton type="primary" @click="showGenerateTokenDialog = true">
					{{ t('pipelinq', 'Generate Token') }}
				</NcButton>

				<table v-if="settings.apiTokens && settings.apiTokens.length > 0" class="pipelinq-token-table">
					<thead>
						<tr>
							<th>{{ t('pipelinq', 'Label') }}</th>
							<th>{{ t('pipelinq', 'Created') }}</th>
							<th>{{ t('pipelinq', 'Last Used') }}</th>
							<th>{{ t('pipelinq', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="token in settings.apiTokens" :key="token.id">
							<td>{{ token.label }}</td>
							<td>{{ formatDate(token.created) }}</td>
							<td>{{ token.lastUsed ? formatDate(token.lastUsed) : t('pipelinq', 'Never') }}</td>
							<td>
								<NcButton
									type="error"
									:aria-label="t('pipelinq', 'Revoke token {label}', { label: token.label })"
									@click="revokeToken(token.id)">
									{{ t('pipelinq', 'Revoke') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-else class="pipelinq-empty-state">
					{{ t('pipelinq', 'No API tokens have been generated yet.') }}
				</p>
			</div>

			<!-- OAuth 2.0 configuration tab -->
			<div v-if="activeAuthTab === 'oauth'" class="pipelinq-tab-content">
				<div class="pipelinq-form-row">
					<label for="oauth-client-id">{{ t('pipelinq', 'Client ID') }}</label>
					<NcInputField
						id="oauth-client-id"
						v-model="oauthForm.clientId"
						:label="t('pipelinq', 'Client ID')"
						:placeholder="t('pipelinq', 'OAuth 2.0 client identifier')" />
				</div>
				<div class="pipelinq-form-row">
					<label for="oauth-client-secret">{{ t('pipelinq', 'Client Secret') }}</label>
					<NcInputField
						id="oauth-client-secret"
						v-model="oauthForm.clientSecret"
						type="password"
						:label="t('pipelinq', 'Client Secret')"
						:placeholder="settings.oauthConfig && settings.oauthConfig.hasSecret ? '••••••••' : t('pipelinq', 'OAuth 2.0 client secret')" />
				</div>
				<div class="pipelinq-form-row">
					<label for="oauth-token-endpoint">{{ t('pipelinq', 'Token Endpoint') }}</label>
					<NcInputField
						id="oauth-token-endpoint"
						v-model="oauthForm.tokenEndpoint"
						:label="t('pipelinq', 'Token Endpoint')"
						:placeholder="t('pipelinq', 'https://auth.example.com/token')" />
				</div>
				<div class="pipelinq-form-row">
					<label for="oauth-auth-endpoint">{{ t('pipelinq', 'Authorization Endpoint') }}</label>
					<NcInputField
						id="oauth-auth-endpoint"
						v-model="oauthForm.authEndpoint"
						:label="t('pipelinq', 'Authorization Endpoint')"
						:placeholder="t('pipelinq', 'https://auth.example.com/authorize')" />
				</div>
				<div class="pipelinq-form-row">
					<label for="oauth-scopes">{{ t('pipelinq', 'Scopes') }}</label>
					<NcInputField
						id="oauth-scopes"
						v-model="oauthForm.scopes"
						:label="t('pipelinq', 'Scopes')"
						:placeholder="t('pipelinq', 'openid profile email')" />
				</div>
				<div class="pipelinq-form-row pipelinq-toggle-row">
					<NcCheckboxRadioSwitch
						v-model="oauthForm.idTokenForwarding"
						:aria-label="t('pipelinq', 'Forward idToken for OpenID Connect')">
						{{ t('pipelinq', 'Forward idToken (OpenID Connect)') }}
					</NcCheckboxRadioSwitch>
				</div>
				<NcButton type="primary" @click="saveOAuth">
					{{ t('pipelinq', 'Save OAuth Configuration') }}
				</NcButton>
			</div>
		</CnSettingsSection>

		<!-- MCP Server Administration -->
		<CnSettingsSection
			:name="t('pipelinq', 'MCP Server Administration')"
			:description="t('pipelinq', 'Configure a Model Context Protocol (MCP) server endpoint for AI assistant integrations.')">
			<div class="pipelinq-form-row">
				<label for="mcp-endpoint">{{ t('pipelinq', 'Endpoint URL') }}</label>
				<NcInputField
					id="mcp-endpoint"
					v-model="mcpForm.endpoint"
					:label="t('pipelinq', 'Endpoint URL')"
					:placeholder="t('pipelinq', 'https://mcp.example.com/pipelinq')" />
			</div>
			<div class="pipelinq-form-row">
				<label for="mcp-auth-mode">{{ t('pipelinq', 'Authentication Mode') }}</label>
				<NcSelect
					id="mcp-auth-mode"
					v-model="mcpForm.authMode"
					:options="mcpAuthModes"
					:placeholder="t('pipelinq', 'Select authentication mode')"
					label="label"
					track-by="value" />
			</div>

			<!-- API Key credentials -->
			<div v-if="mcpForm.authMode && mcpForm.authMode.value === 'apikey'" class="pipelinq-form-row">
				<label for="mcp-api-key">{{ t('pipelinq', 'API Key') }}</label>
				<NcInputField
					id="mcp-api-key"
					v-model="mcpForm.apiKey"
					type="password"
					:label="t('pipelinq', 'API Key')"
					:placeholder="settings.mcpConfig && settings.mcpConfig.hasApiKey ? '••••••••' : t('pipelinq', 'API key for MCP authentication')" />
			</div>

			<!-- OAuth 2.0 credentials for MCP -->
			<template v-if="mcpForm.authMode && mcpForm.authMode.value === 'oauth2'">
				<div class="pipelinq-form-row">
					<label for="mcp-oauth-client-id">{{ t('pipelinq', 'OAuth Client ID') }}</label>
					<NcInputField
						id="mcp-oauth-client-id"
						v-model="mcpForm.oauthClientId"
						:label="t('pipelinq', 'OAuth Client ID')"
						:placeholder="t('pipelinq', 'MCP OAuth 2.0 client identifier')" />
				</div>
				<div class="pipelinq-form-row">
					<label for="mcp-oauth-secret">{{ t('pipelinq', 'OAuth Client Secret') }}</label>
					<NcInputField
						id="mcp-oauth-secret"
						v-model="mcpForm.oauthClientSecret"
						type="password"
						:label="t('pipelinq', 'OAuth Client Secret')"
						:placeholder="settings.mcpConfig && settings.mcpConfig.hasOAuthSecret ? '••••••••' : t('pipelinq', 'MCP OAuth 2.0 client secret')" />
				</div>
			</template>

			<NcButton type="primary" @click="saveMcp">
				{{ t('pipelinq', 'Save MCP Configuration') }}
			</NcButton>
		</CnSettingsSection>

		<!-- Generate Token Dialog -->
		<NcDialog
			v-if="showGenerateTokenDialog"
			:name="t('pipelinq', 'Generate API Token')"
			:open="showGenerateTokenDialog"
			@closing="closeGenerateDialog">
			<template v-if="!generatedToken">
				<div class="pipelinq-form-row">
					<label for="new-token-label">{{ t('pipelinq', 'Token Label') }}</label>
					<NcInputField
						id="new-token-label"
						v-model="newTokenLabel"
						:label="t('pipelinq', 'Token Label')"
						:placeholder="t('pipelinq', 'e.g. ERP Integration')" />
				</div>
				<template #actions>
					<NcButton type="tertiary" @click="closeGenerateDialog">
						{{ t('pipelinq', 'Cancel') }}
					</NcButton>
					<NcButton type="primary" :disabled="!newTokenLabel" @click="generateToken">
						{{ t('pipelinq', 'Generate') }}
					</NcButton>
				</template>
			</template>
			<template v-else>
				<p>{{ t('pipelinq', 'Your new API token has been generated. Copy it now — it will not be shown again.') }}</p>
				<div class="pipelinq-token-display">
					<NcInputField
						:value="generatedToken"
						:label="t('pipelinq', 'Generated Token')"
						readonly
						type="text" />
					<NcButton
						:aria-label="t('pipelinq', 'Copy token to clipboard')"
						type="secondary"
						@click="copyToken">
						{{ t('pipelinq', 'Copy') }}
					</NcButton>
				</div>
				<template #actions>
					<NcButton type="primary" @click="closeGenerateDialog">
						{{ t('pipelinq', 'Done') }}
					</NcButton>
				</template>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	CnVersionInfoCard,
	CnRegisterMapping,
	CnSettingsSection,
	NcButton,
	NcSelect,
	NcInputField,
	NcCheckboxRadioSwitch,
	NcDialog,
} from '@conduction/nextcloud-vue'

export default {
	name: 'AdminSettings',

	components: {
		CnVersionInfoCard,
		CnRegisterMapping,
		CnSettingsSection,
		NcButton,
		NcSelect,
		NcInputField,
		NcCheckboxRadioSwitch,
		NcDialog,
	},

	data() {
		return {
			appId: 'pipelinq',
			settings: {},
			availableGroups: [],
			accessMap: {},
			activeAuthTab: 'tokens',
			oauthForm: {
				clientId: '',
				clientSecret: '',
				tokenEndpoint: '',
				authEndpoint: '',
				scopes: '',
				idTokenForwarding: false,
			},
			mcpForm: {
				endpoint: '',
				authMode: null,
				apiKey: '',
				oauthClientId: '',
				oauthClientSecret: '',
			},
			mcpAuthModes: [
				{ label: this.t('pipelinq', 'API Key'), value: 'apikey' },
				{ label: this.t('pipelinq', 'OAuth 2.0'), value: 'oauth2' },
			],
			showGenerateTokenDialog: false,
			newTokenLabel: '',
			generatedToken: null,
		}
	},

	async created() {
		await this.loadSettings()
		await this.loadGroups()
	},

	methods: {
		async loadSettings() {
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/settings'))
				this.settings = data

				// Populate access map from settings.
				this.accessMap = {}
				if (data.objectenAccess) {
					Object.entries(data.objectenAccess).forEach(([slug, groupIds]) => {
						this.accessMap[slug] = groupIds.map(id => ({ id, displayName: id }))
					})
				}

				// Populate OAuth form.
				if (data.oauthConfig) {
					this.oauthForm.clientId = data.oauthConfig.clientId || ''
					this.oauthForm.tokenEndpoint = data.oauthConfig.tokenEndpoint || ''
					this.oauthForm.authEndpoint = data.oauthConfig.authEndpoint || ''
					this.oauthForm.scopes = data.oauthConfig.scopes || ''
					this.oauthForm.idTokenForwarding = data.oauthConfig.idTokenForwarding || false
				}

				// Populate MCP form.
				if (data.mcpConfig) {
					this.mcpForm.endpoint = data.mcpConfig.endpoint || ''
					this.mcpForm.oauthClientId = data.mcpConfig.oauthClientId || ''
					const authModeVal = data.mcpConfig.authMode || 'apikey'
					this.mcpForm.authMode = this.mcpAuthModes.find(m => m.value === authModeVal) || this.mcpAuthModes[0]
				}
			} catch (error) {
				console.error('Failed to load settings', error)
			}
		},

		async loadGroups() {
			try {
				const { data } = await axios.get(generateUrl('/ocs/v1.php/cloud/groups'), {
					params: { format: 'json' },
					headers: { 'OCS-APIREQUEST': 'true' },
				})
				const groups = data?.ocs?.data?.groups || []
				this.availableGroups = groups.map(g => ({ id: g, displayName: g }))
			} catch (error) {
				console.error('Failed to load groups', error)
			}
		},

		async saveSchemaAccess(slug) {
			try {
				const groupIds = (this.accessMap[slug] || []).map(g => g.id)
				await axios.post(generateUrl('/apps/pipelinq/api/settings/objecten-access'), {
					schemaSlug: slug,
					groupIds,
				})
				OC.Notification.showTemporary(this.t('pipelinq', 'Access configuration saved.'))
			} catch (error) {
				console.error('Failed to save schema access', error)
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to save access configuration.'))
			}
		},

		async generateToken() {
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/settings/api-tokens'), {
					label: this.newTokenLabel,
				})
				this.generatedToken = data.token
				await this.loadSettings()
			} catch (error) {
				console.error('Failed to generate token', error)
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to generate token.'))
			}
		},

		async revokeToken(id) {
			try {
				await axios.delete(generateUrl('/apps/pipelinq/api/settings/api-tokens/' + encodeURIComponent(id)))
				await this.loadSettings()
				OC.Notification.showTemporary(this.t('pipelinq', 'Token revoked.'))
			} catch (error) {
				console.error('Failed to revoke token', error)
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to revoke token.'))
			}
		},

		async saveOAuth() {
			try {
				const payload = {
					clientId: this.oauthForm.clientId,
					tokenEndpoint: this.oauthForm.tokenEndpoint,
					authEndpoint: this.oauthForm.authEndpoint,
					scopes: this.oauthForm.scopes,
					idTokenForwarding: this.oauthForm.idTokenForwarding,
				}
				if (this.oauthForm.clientSecret) {
					payload.clientSecret = this.oauthForm.clientSecret
				}

				await axios.post(generateUrl('/apps/pipelinq/api/settings/oauth'), payload)
				this.oauthForm.clientSecret = ''
				await this.loadSettings()
				OC.Notification.showTemporary(this.t('pipelinq', 'OAuth configuration saved.'))
			} catch (error) {
				console.error('Failed to save OAuth config', error)
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to save OAuth configuration.'))
			}
		},

		async saveMcp() {
			try {
				const payload = {
					endpoint: this.mcpForm.endpoint,
					authMode: this.mcpForm.authMode ? this.mcpForm.authMode.value : 'apikey',
					oauthClientId: this.mcpForm.oauthClientId,
				}
				if (this.mcpForm.apiKey) {
					payload.apiKey = this.mcpForm.apiKey
				}

				if (this.mcpForm.oauthClientSecret) {
					payload.oauthClientSecret = this.mcpForm.oauthClientSecret
				}

				await axios.post(generateUrl('/apps/pipelinq/api/settings/mcp'), payload)
				this.mcpForm.apiKey = ''
				this.mcpForm.oauthClientSecret = ''
				await this.loadSettings()
				OC.Notification.showTemporary(this.t('pipelinq', 'MCP configuration saved.'))
			} catch (error) {
				console.error('Failed to save MCP config', error)
				OC.Notification.showTemporary(this.t('pipelinq', 'Failed to save MCP configuration.'))
			}
		},

		copyToken() {
			if (navigator.clipboard) {
				navigator.clipboard.writeText(this.generatedToken)
				OC.Notification.showTemporary(this.t('pipelinq', 'Token copied to clipboard.'))
			}
		},

		closeGenerateDialog() {
			this.showGenerateTokenDialog = false
			this.newTokenLabel = ''
			this.generatedToken = null
		},

		formatDate(isoString) {
			if (!isoString) return ''
			return new Date(isoString).toLocaleString()
		},
	},
}
</script>

<style scoped>
.pipelinq-admin-settings {
	max-width: 900px;
}

.pipelinq-access-table,
.pipelinq-token-table {
	width: 100%;
	border-collapse: collapse;
	margin-top: var(--default-grid-baseline);
}

.pipelinq-access-table th,
.pipelinq-access-table td,
.pipelinq-token-table th,
.pipelinq-token-table td {
	padding: calc(var(--default-grid-baseline) * 2);
	text-align: left;
	border-bottom: 1px solid var(--color-border);
}

.pipelinq-tabs {
	display: flex;
	gap: var(--default-grid-baseline);
	margin-bottom: calc(var(--default-grid-baseline) * 3);
}

.pipelinq-tabs .button-vue.active {
	background-color: var(--color-primary-element-light);
}

.pipelinq-tab-content {
	display: flex;
	flex-direction: column;
	gap: calc(var(--default-grid-baseline) * 2);
}

.pipelinq-form-row {
	display: flex;
	flex-direction: column;
	gap: var(--default-grid-baseline);
	max-width: 500px;
}

.pipelinq-toggle-row {
	flex-direction: row;
	align-items: center;
}

.pipelinq-empty-state {
	color: var(--color-text-maxcontrast);
	font-style: italic;
	padding: calc(var(--default-grid-baseline) * 2) 0;
}

.pipelinq-token-display {
	display: flex;
	gap: var(--default-grid-baseline);
	align-items: flex-end;
	margin-top: var(--default-grid-baseline);
}
</style>

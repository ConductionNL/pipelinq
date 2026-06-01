<template>
	<NcSettingsSection
		:name="t('pipelinq', 'xWiki Integratie')"
		:description="t('pipelinq', 'Configureer de xWiki kennisbank integratie voor Pipelinq')">
		<!-- xWiki app not installed warning -->
		<NcNoteCard v-if="!xwikiAppInstalled" type="warning" class="xwiki-settings__warning">
			{{ t('pipelinq', 'De xWiki Nextcloud app is niet geinstalleerd of niet ingeschakeld. Installeer de xWiki app voor volledige integratie.') }}
		</NcNoteCard>

		<!-- Connection status -->
		<div class="xwiki-settings__status">
			<span class="xwiki-settings__status-label">{{ t('pipelinq', 'Verbindingsstatus') }}:</span>
			<span v-if="statusLoading">
				<NcLoadingIcon :size="16" />
			</span>
			<span v-else-if="statusData.available" class="xwiki-settings__status-ok">
				✓ {{ t('pipelinq', 'Beschikbaar') }}
				<template v-if="statusData.version">
					(v{{ statusData.version }})
				</template>
			</span>
			<span v-else class="xwiki-settings__status-error">
				✗ {{ t('pipelinq', 'Niet bereikbaar') }}
				<template v-if="statusData.error">
					— {{ statusData.error }}
				</template>
			</span>
			<NcButton
				:disabled="statusLoading"
				type="tertiary"
				class="xwiki-settings__test-btn"
				@click="testConnection">
				<template #icon>
					<NcLoadingIcon v-if="statusLoading" :size="16" />
					<Refresh v-else :size="16" />
				</template>
				{{ t('pipelinq', 'Test verbinding') }}
			</NcButton>
		</div>

		<!-- Settings fields -->
		<div class="xwiki-settings__fields">
			<div class="xwiki-settings__field">
				<label for="xwiki-direct-url">{{ t('pipelinq', 'xWiki URL') }}</label>
				<NcTextField
					id="xwiki-direct-url"
					v-model="form.xwiki_direct_url"
					:placeholder="t('pipelinq', 'https://xwiki.example.org')"
					:label="t('pipelinq', 'xWiki URL')"
					:label-visible="false" />
				<p class="xwiki-settings__hint">
					{{ t('pipelinq', 'Directe URL naar het xWiki-instance (fallback wanneer de xWiki NC app niet beschikbaar is)') }}
				</p>
			</div>

			<div class="xwiki-settings__field">
				<label for="xwiki-default-space">{{ t('pipelinq', 'Standaard xWiki ruimte') }}</label>
				<NcTextField
					id="xwiki-default-space"
					v-model="form.xwiki_default_space"
					:placeholder="t('pipelinq', 'Kennisbank')"
					:label="t('pipelinq', 'Standaard xWiki ruimte')"
					:label-visible="false" />
				<p class="xwiki-settings__hint">
					{{ t('pipelinq', 'Standaard xWiki-ruimte die wordt gebruikt in widgets en sidebars zonder expliciete ruimteconfiguratie') }}
				</p>
			</div>

			<div class="xwiki-settings__field">
				<label for="xwiki-cache-ttl">{{ t('pipelinq', 'Cache duur (seconden)') }}</label>
				<NcTextField
					id="xwiki-cache-ttl"
					v-model="form.xwiki_cache_ttl"
					type="number"
					:placeholder="'300'"
					:label="t('pipelinq', 'Cache duur (seconden)')"
					:label-visible="false" />
				<p class="xwiki-settings__hint">
					{{ t('pipelinq', 'Hoe lang xWiki-antwoorden gecached worden (standaard 300 seconden)') }}
				</p>
			</div>
		</div>

		<!-- Save button -->
		<div class="xwiki-settings__actions">
			<NcButton
				type="primary"
				:disabled="saving"
				@click="save">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
				</template>
				{{ saving ? t('pipelinq', 'Opslaan…') : t('pipelinq', 'Opslaan') }}
			</NcButton>
			<span v-if="saveSuccess" class="xwiki-settings__save-ok">
				{{ t('pipelinq', 'Instellingen opgeslagen') }}
			</span>
		</div>
	</NcSettingsSection>
</template>

<script>
/**
 * XWikiAdminSettings — admin settings section for xWiki integration.
 *
 * Displays xWiki connection status, a "Test verbinding" button, and fields
 * for direct URL, default space, and cache TTL. Saves via SettingsController
 * which persists to IAppConfig.
 *
 * @spec openspec/changes/xwiki-integration/tasks.md#task-8.1
 */
import { NcButton, NcLoadingIcon, NcNoteCard, NcSettingsSection, NcTextField } from '@nextcloud/vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { generateUrl } from '@nextcloud/router'
import { useSettingsStore } from '../../store/modules/settings.js'
import { useXWikiStore } from '../../store/modules/xwiki.js'

export default {
	name: 'XWikiAdminSettings',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSettingsSection,
		NcTextField,
		Refresh,
	},

	data() {
		return {
			statusLoading: false,
			statusData: { available: false, version: '', error: '' },
			saving: false,
			saveSuccess: false,
			form: {
				xwiki_direct_url: '',
				xwiki_default_space: '',
				xwiki_cache_ttl: '300',
			},
			xwikiAppInstalled: true,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/xwiki-integration/tasks.md#task-8.1
		 */
		settingsStore() {
			return useSettingsStore()
		},
		xwikiStore() {
			return useXWikiStore()
		},
	},

	async mounted() {
		// Populate from settings store.
		const config = this.settingsStore.config || {}
		this.form.xwiki_direct_url = config.xwiki_direct_url ?? ''
		this.form.xwiki_default_space = config.xwiki_default_space ?? ''
		this.form.xwiki_cache_ttl = String(config.xwiki_cache_ttl ?? '300')

		await this.testConnection()
	},

	methods: {
		/**
		 * Test the xWiki connection and update status display.
		 *
		 * @return {Promise<void>}
		 */
		async testConnection() {
			this.statusLoading = true
			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/xwiki/status'),
					{
						method: 'GET',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
					},
				)
				this.statusData = await response.json()
			} catch (err) {
				this.statusData = {
					available: false,
					error: err.message ?? t('pipelinq', 'Verbindingsfout'),
				}
			} finally {
				this.statusLoading = false
			}
		},

		/**
		 * Save the xWiki settings to the backend.
		 *
		 * @return {Promise<void>}
		 */
		async save() {
			this.saving = true
			this.saveSuccess = false

			try {
				const response = await fetch(
					generateUrl('/apps/pipelinq/api/settings'),
					{
						method: 'POST',
						headers: {
							'Content-Type': 'application/json',
							requesttoken: OC.requestToken,
							'OCS-APIREQUEST': 'true',
						},
						body: JSON.stringify({
							xwiki_direct_url: this.form.xwiki_direct_url,
							xwiki_default_space: this.form.xwiki_default_space,
							xwiki_cache_ttl: this.form.xwiki_cache_ttl,
						}),
					},
				)

				if (!response.ok) {
					throw new Error(t('pipelinq', 'Opslaan mislukt ({status})', { status: response.status }))
				}

				await response.json()
				// Refresh settings store.
				await this.settingsStore.fetchSettings()
				this.saveSuccess = true
				setTimeout(() => { this.saveSuccess = false }, 3000)
			} catch (err) {
				console.error('XWikiAdminSettings save error:', err)
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.xwiki-settings__warning {
	margin-bottom: 16px;
}

.xwiki-settings__status {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 16px;
	font-size: 13px;
}

.xwiki-settings__status-label {
	font-weight: 500;
}

.xwiki-settings__status-ok {
	color: var(--color-success);
}

.xwiki-settings__status-error {
	color: var(--color-error);
}

.xwiki-settings__test-btn {
	margin-left: 4px;
}

.xwiki-settings__fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 500px;
}

.xwiki-settings__field label {
	display: block;
	font-weight: 500;
	margin-bottom: 4px;
	font-size: 13px;
}

.xwiki-settings__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin: 4px 0 0;
}

.xwiki-settings__actions {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 20px;
}

.xwiki-settings__save-ok {
	font-size: 13px;
	color: var(--color-success);
}
</style>

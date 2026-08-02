<template>
	<CnSettingsSection
		:name="t('pipelinq', 'Prospect Discovery')"
		:description="t('pipelinq', 'Configure your Ideal Customer Profile (ICP) to discover potential leads.')">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="prospect-settings__form">
			<!-- SBI Codes -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'SBI Codes') }}</label>
				<NcTextField
					:model-value="sbiCodesText"
					:placeholder="t('pipelinq', 'e.g. 62, 72 (comma-separated)')"
					@update:model-value="v => sbiCodesText = v" />
				<p class="form-help">
					{{ t('pipelinq', 'Dutch Standard Industrial Classification codes. Separate multiple codes with commas.') }}
				</p>
			</div>

			<!-- Employee Count -->
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Min Employees') }}</label>
					<NcTextField
						:model-value="String(form.employeeCountMin)"
						type="number"
						@update:model-value="v => form.employeeCountMin = Number(v)" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Max Employees') }}</label>
					<NcTextField
						:model-value="String(form.employeeCountMax)"
						type="number"
						@update:model-value="v => form.employeeCountMax = Number(v)" />
				</div>
			</div>

			<!-- Provinces -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Provinces') }}</label>
				<NcSelect
					v-model="form.provinces"
					:options="provinceOptions"
					:aria-label-combobox="t('pipelinq', 'Provinces')"
					:multiple="true"
					:keep-open="true"
					:placeholder="t('pipelinq', 'Select provinces')" />
			</div>

			<!-- Legal Forms -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Legal Forms') }}</label>
				<NcSelect
					v-model="form.legalForms"
					:options="legalFormOptions"
					:aria-label-combobox="t('pipelinq', 'Legal Forms')"
					:multiple="true"
					:keep-open="true"
					:placeholder="t('pipelinq', 'Select legal forms')" />
			</div>

			<!-- Exclude Inactive -->
			<div class="form-group form-group--checkbox">
				<input
					id="exclude-inactive"
					v-model="form.excludeInactive"
					type="checkbox">
				<label for="exclude-inactive">{{ t('pipelinq', 'Exclude inactive companies') }}</label>
			</div>

			<!-- Keywords (for OpenCorporates) -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Keywords') }}</label>
				<NcTextField
					:model-value="keywordsText"
					:placeholder="t('pipelinq', 'e.g. software, IT (comma-separated)')"
					@update:model-value="v => keywordsText = v" />
				<p class="form-help">
					{{ t('pipelinq', 'Used for OpenCorporates search. Separate with commas.') }}
				</p>
			</div>

			<!-- KVK API Key -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'KVK API Key') }}</label>
				<NcTextField
					:model-value="form.kvkApiKey"
					type="password"
					:placeholder="t('pipelinq', 'Enter your KVK API key')"
					@update:model-value="v => form.kvkApiKey = v" />
				<p class="form-help">
					{{ t('pipelinq', 'Required for prospect discovery. Get one at developers.kvk.nl.') }}
				</p>
			</div>

			<!-- OpenCorporates Toggle -->
			<div class="form-group form-group--checkbox">
				<input
					id="oc-enabled"
					v-model="form.openCorporatesEnabled"
					type="checkbox">
				<label for="oc-enabled">{{ t('pipelinq', 'Enable OpenCorporates (supplementary data source)') }}</label>
			</div>

			<!-- Save -->
			<div class="prospect-settings__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Save ICP Settings') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ProspectSettings',
	components: {
		CnSettingsSection,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},
	data() {
		return {
			loading: false,
			saving: false,
			message: '',
			messageType: 'success',
			sbiCodesText: '',
			keywordsText: '',
			form: {
				employeeCountMin: 0,
				employeeCountMax: 0,
				provinces: [],
				legalForms: [],
				excludeInactive: true,
				kvkApiKey: '',
				openCorporatesEnabled: false,
			},
			provinceOptions: [
				'Drenthe', 'Flevoland', 'Friesland', 'Gelderland',
				'Groningen', 'Limburg', 'Noord-Brabant', 'Noord-Holland',
				'Overijssel', 'Utrecht', 'Zeeland', 'Zuid-Holland',
			],
			legalFormOptions: [
				'BV', 'NV', 'VOF', 'Eenmanszaak', 'Stichting',
				'Vereniging', 'CV', 'Maatschap',
			],
		}
	},
	async mounted() {
		await this.fetchSettings()
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-65
		 */
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/prospects/settings'), {
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
				})
				if (response.ok) {
					const data = await response.json()
					this.form = {
						employeeCountMin: data.employeeCountMin || 0,
						employeeCountMax: data.employeeCountMax || 0,
						provinces: data.provinces || [],
						legalForms: data.legalForms || [],
						excludeInactive: data.excludeInactive !== false,
						kvkApiKey: data.kvkApiKey === '***configured***' ? '***configured***' : '',
						openCorporatesEnabled: data.openCorporatesEnabled || false,
					}
					this.sbiCodesText = (data.sbiCodes || []).join(', ')
					this.keywordsText = (data.keywords || []).join(', ')
				}
			} catch {
				// Settings may not exist yet
			} finally {
				this.loading = false
			}
		},
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-66
		 */
		async save() {
			this.saving = true
			this.message = ''

			const payload = {
				...this.form,
				sbiCodes: this.sbiCodesText.split(',').map(s => s.trim()).filter(Boolean),
				keywords: this.keywordsText.split(',').map(s => s.trim()).filter(Boolean),
			}

			// Don't send masked API key
			if (payload.kvkApiKey === '***configured***') {
				delete payload.kvkApiKey
			}

			try {
				const response = await fetch(generateUrl('/apps/pipelinq/api/prospects/settings'), {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify(payload),
				})

				if (response.ok) {
					this.message = t('pipelinq', 'ICP settings saved successfully')
					this.messageType = 'success'
				} else {
					this.message = t('pipelinq', 'Failed to save ICP settings')
					this.messageType = 'error'
				}
			} catch {
				this.message = t('pipelinq', 'Failed to save ICP settings')
				this.messageType = 'error'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.prospect-settings__form {
	max-width: 600px;
}

.form-group {
	margin-bottom: 16px;
}

.form-group label {
	display: block;
	margin-bottom: 4px;
	font-weight: bold;
}

.form-group--checkbox {
	display: flex;
	align-items: center;
	gap: 8px;
}

.form-group--checkbox label {
	margin-bottom: 0;
	font-weight: normal;
}

.form-row {
	display: flex;
	gap: 16px;
}

.form-row .form-group {
	flex: 1;
}

.form-help {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	margin-top: 4px;
}

.prospect-settings__actions {
	margin-top: 20px;
	margin-bottom: 12px;
}
</style>

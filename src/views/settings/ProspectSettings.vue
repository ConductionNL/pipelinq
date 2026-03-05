<template>
	<div class="prospect-settings">
		<h3>{{ t('pipelinq', 'Prospect Discovery') }}</h3>
		<p class="prospect-settings__desc">
			{{ t('pipelinq', 'Configure your Ideal Customer Profile (ICP) to discover potential leads.') }}
		</p>

		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="prospect-settings__form">
			<!-- SBI Codes -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'SBI Codes') }}</label>
				<NcTextField
					:value="sbiCodesText"
					:placeholder="t('pipelinq', 'e.g. 62, 72 (comma-separated)')"
					@update:value="v => sbiCodesText = v" />
				<p class="form-help">
					{{ t('pipelinq', 'Dutch Standard Industrial Classification codes. Separate multiple codes with commas.') }}
				</p>
			</div>

			<!-- Employee Count -->
			<div class="form-row">
				<div class="form-group">
					<label>{{ t('pipelinq', 'Min Employees') }}</label>
					<NcTextField
						:value="String(form.employeeCountMin)"
						type="number"
						@update:value="v => form.employeeCountMin = Number(v)" />
				</div>
				<div class="form-group">
					<label>{{ t('pipelinq', 'Max Employees') }}</label>
					<NcTextField
						:value="String(form.employeeCountMax)"
						type="number"
						@update:value="v => form.employeeCountMax = Number(v)" />
				</div>
			</div>

			<!-- Provinces -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Provinces') }}</label>
				<NcSelect
					v-model="form.provinces"
					:options="provinceOptions"
					:multiple="true"
					:placeholder="t('pipelinq', 'Select provinces')" />
			</div>

			<!-- Legal Forms -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'Legal Forms') }}</label>
				<NcSelect
					v-model="form.legalForms"
					:options="legalFormOptions"
					:multiple="true"
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
					:value="keywordsText"
					:placeholder="t('pipelinq', 'e.g. software, IT (comma-separated)')"
					@update:value="v => keywordsText = v" />
				<p class="form-help">
					{{ t('pipelinq', 'Used for OpenCorporates search. Separate with commas.') }}
				</p>
			</div>

			<!-- KVK API Key -->
			<div class="form-group">
				<label>{{ t('pipelinq', 'KVK API Key') }}</label>
				<NcTextField
					:value="form.kvkApiKey"
					type="password"
					:placeholder="t('pipelinq', 'Enter your KVK API key')"
					@update:value="v => form.kvkApiKey = v" />
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
				<NcButton type="primary" :disabled="saving" @click="save">
					{{ saving ? t('pipelinq', 'Saving...') : t('pipelinq', 'Save ICP Settings') }}
				</NcButton>
			</div>

			<NcNoteCard v-if="message" :type="messageType">
				{{ message }}
			</NcNoteCard>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard, NcSelect, NcTextField } from '@nextcloud/vue'

export default {
	name: 'ProspectSettings',
	components: {
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
		async fetchSettings() {
			this.loading = true
			try {
				const response = await fetch('/apps/pipelinq/api/prospects/settings', {
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
				const response = await fetch('/apps/pipelinq/api/prospects/settings', {
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
.prospect-settings {
	margin-bottom: 24px;
}

.prospect-settings__desc {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

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

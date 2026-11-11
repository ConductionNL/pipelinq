<template>
	<CnSettingsSection
		:name="t('pipelinq', 'Find prospects')"
		:description="
			t('pipelinq', 'Your ideal customer profile, to find possible leads.')
		">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else class="prospect-settings__form">
			<!-- SBI Codes -->
			<div class="form-group">
				<NcTextField
					id="prospect-sbi-codes"
					:label="t('pipelinq', 'SBI Codes')"
					:modelValue="sbiCodesText"
					:placeholder="t('pipelinq', 'e.g. 62, 72 (comma-separated)')"
					@update:modelValue="(v) => (sbiCodesText = v)" />
				<p class="form-help">
					{{
						t(
							'pipelinq',
							'Dutch Standard Industrial Classification codes. Separate multiple codes with commas.',
						)
					}}
				</p>
			</div>

			<!-- Employee Count -->
			<div class="form-row">
				<div class="form-group">
					<NcTextField
						id="prospect-employee-count-min"
						:label="t('pipelinq', 'Min Employees')"
						:modelValue="String(form.employeeCountMin)"
						type="number"
						@update:modelValue="
							(v) => (form.employeeCountMin = Number(v))
						" />
				</div>
				<div class="form-group">
					<NcTextField
						id="prospect-employee-count-max"
						:label="t('pipelinq', 'Max Employees')"
						:modelValue="String(form.employeeCountMax)"
						type="number"
						@update:modelValue="
							(v) => (form.employeeCountMax = Number(v))
						" />
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
					:keepOpen="true"
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
					:keepOpen="true"
					:placeholder="t('pipelinq', 'Select legal forms')" />
			</div>

			<!-- Exclude Inactive -->
			<div class="form-group form-group--checkbox">
				<input
					id="exclude-inactive"
					v-model="form.excludeInactive"
					type="checkbox" />
				<label for="exclude-inactive">{{
					t('pipelinq', 'Exclude inactive companies')
				}}</label>
			</div>

			<!-- Keywords (for OpenCorporates) -->
			<div class="form-group">
				<NcTextField
					id="prospect-keywords"
					:label="t('pipelinq', 'Keywords')"
					:modelValue="keywordsText"
					:placeholder="
						t('pipelinq', 'e.g. software, IT (comma-separated)')
					"
					@update:modelValue="(v) => (keywordsText = v)" />
				<p class="form-help">
					{{
						t(
							'pipelinq',
							'Used for OpenCorporates search. Separate with commas.',
						)
					}}
				</p>
			</div>

			<!-- KVK API Key -->
			<div class="form-group">
				<NcTextField
					id="prospect-kvk-api-key"
					:label="t('pipelinq', 'KVK API Key')"
					:modelValue="form.kvkApiKey"
					type="password"
					:placeholder="t('pipelinq', 'Enter your KVK API key')"
					@update:modelValue="(v) => (form.kvkApiKey = v)" />
				<p class="form-help">
					{{
						t(
							'pipelinq',
							'Required for prospect discovery. Get one at developers.kvk.nl.',
						)
					}}
				</p>
			</div>

			<!-- OpenCorporates Toggle -->
			<div class="form-group form-group--checkbox">
				<input
					id="oc-enabled"
					v-model="form.openCorporatesEnabled"
					type="checkbox" />
				<label for="oc-enabled">{{
					t(
						'pipelinq',
						'Enable OpenCorporates (supplementary data source)',
					)
				}}</label>
			</div>

			<!-- Save -->
			<div class="prospect-settings__actions">
				<NcButton variant="primary" :disabled="saving" @click="save">
					{{
						saving
							? t('pipelinq', 'Saving…')
							: t('pipelinq', 'Save ICP Settings')
					}}
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
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

/** How the server shows a KvK API key that is configured. */
const MASKED_KEY = '***configured***'

/**
 * A request to the prospect settings endpoint.
 *
 * @param {object} init The method and body, if any.
 * @return {Promise<Response>} The response.
 */
function settingsRequest(init) {
	return fetch(
		generateUrl('/apps/pipelinq/api/prospects/settings'),
		{
			...init,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: OC.requestToken,
				'OCS-APIREQUEST': 'true',
			},
		},
	)
}

/**
 * The stored profile as the form edits it; a configured KvK key stays masked.
 *
 * @param {object} data The stored profile.
 * @return {object} The form.
 */
function icpForm(data) {
	return {
		...icpFilters(data),
		excludeInactive: data.excludeInactive !== false,
		kvkApiKey:
			data.kvkApiKey === MASKED_KEY
				? MASKED_KEY
				: '',

		openCorporatesEnabled: data.openCorporatesEnabled || false,
	}
}

/**
 * @param {object} data The stored profile.
 * @return {object} The company size, provinces and legal forms, empty when unset.
 */
function icpFilters(data) {
	return {
		employeeCountMin: data.employeeCountMin || 0,
		employeeCountMax: data.employeeCountMax || 0,
		provinces: data.provinces || [],
		legalForms: data.legalForms || [],
	}
}

/**
 * @param {?Array<string>} list A stored list.
 * @return {string} The list as the form types it.
 */
function listText(list) {
	return (list || []).join(', ')
}

/**
 * @param {string} text A comma-separated list as typed.
 * @return {Array<string>} The entries, trimmed, empty ones left out.
 */
function textList(text) {
	return text
		.split(',')
		.map((s) => s.trim())
		.filter(Boolean)
}

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
				'Drenthe',
				'Flevoland',
				'Friesland',
				'Gelderland',
				'Groningen',
				'Limburg',
				'Noord-Brabant',
				'Noord-Holland',
				'Overijssel',
				'Utrecht',
				'Zeeland',
				'Zuid-Holland',
			],

			legalFormOptions: [
				'BV',
				'NV',
				'VOF',
				'Eenmanszaak',
				'Stichting',
				'Vereniging',
				'CV',
				'Maatschap',
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
				const response = await settingsRequest({})
				if (response.ok) {
					const data = await response.json()
					this.form = icpForm(data)
					this.sbiCodesText = listText(data.sbiCodes)
					this.keywordsText = listText(data.keywords)
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
				sbiCodes: textList(this.sbiCodesText),

				keywords: textList(this.keywordsText),
			}

			// Don't send masked API key
			if (payload.kvkApiKey === MASKED_KEY) {
				delete payload.kvkApiKey
			}

			try {
				const response = await settingsRequest({
					method: 'PUT',
					body: JSON.stringify(payload),
				})
				this.showSaved(response.ok)
			} catch {
				this.showSaved(false)
			} finally {
				this.saving = false
			}
		},

		/**
		 * @param {boolean} saved Whether the settings were saved.
		 * @return {void}
		 */
		showSaved(saved) {
			if (saved) {
				this.message = t('pipelinq', 'ICP settings saved successfully')
				this.messageType = 'success'
			} else {
				this.message = t('pipelinq', 'Failed to save ICP settings')
				this.messageType = 'error'
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

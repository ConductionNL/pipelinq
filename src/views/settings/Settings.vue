<template>
	<div class="pipelinq-settings">
		<h2>{{ t('pipelinq', 'Pipelinq') }}</h2>
		<p>{{ t('pipelinq', 'Settings') }}</p>

		<NcLoadingIcon v-if="loading" />

		<div v-else class="settings-form">
			<div class="form-group">
				<label>{{ t('pipelinq', 'Register') }}</label>
				<NcTextField
					:value="form.register"
					:label="t('pipelinq', 'Register')"
					@update:value="v => form.register = v" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Client schema') }}</label>
				<NcTextField
					:value="form.client_schema"
					:label="t('pipelinq', 'Client schema')"
					@update:value="v => form.client_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Request schema') }}</label>
				<NcTextField
					:value="form.request_schema"
					:label="t('pipelinq', 'Request schema')"
					@update:value="v => form.request_schema = v" />
			</div>
			<div class="form-group">
				<label>{{ t('pipelinq', 'Contact schema') }}</label>
				<NcTextField
					:value="form.contact_schema"
					:label="t('pipelinq', 'Contact schema')"
					@update:value="v => form.contact_schema = v" />
			</div>

			<NcButton type="primary" @click="save">
				{{ t('pipelinq', 'Save') }}
			</NcButton>

			<p v-if="saved" class="success-message">
				{{ t('pipelinq', 'Configuration saved') }}
			</p>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { useSettingsStore } from '../../store/modules/settings.js'

export default {
	name: 'Settings',
	components: {
		NcButton,
		NcLoadingIcon,
		NcTextField,
	},
	data() {
		return {
			form: {
				register: '',
				client_schema: '',
				request_schema: '',
				contact_schema: '',
			},
			saved: false,
		}
	},
	computed: {
		settingsStore() {
			return useSettingsStore()
		},
		loading() {
			return this.settingsStore.isLoading
		},
	},
	async mounted() {
		const config = await this.settingsStore.fetchSettings()
		if (config) {
			this.form = { ...this.form, ...config }
		}
	},
	methods: {
		async save() {
			this.saved = false
			const result = await this.settingsStore.saveSettings(this.form)
			if (result) {
				this.saved = true
				setTimeout(() => { this.saved = false }, 3000)
			}
		},
	},
}
</script>

<style scoped>
.pipelinq-settings {
	padding: 20px;
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

.success-message {
	color: var(--color-success);
	margin-top: 12px;
}
</style>

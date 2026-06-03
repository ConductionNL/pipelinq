<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl> -->
<!-- SPDX-License-Identifier: EUPL-1.2 -->
<template>
	<NcDialog :name="t('pipelinq', 'Generate API Token')"
		@closing="$emit('close')">
		<template #default>
			<div v-if="!token">
				<NcTextField v-model="label"
					:label="t('pipelinq', 'Token Label')"
					:placeholder="t('pipelinq', 'e.g. External ERP integration')" />
			</div>
			<div v-else class="token-display">
				<p>{{ t('pipelinq', 'Copy this token now — it will not be shown again.') }}</p>
				<NcTextField :value="token.token"
					:label="t('pipelinq', 'API Token')"
					readonly
					@focus="$event.target.select()" />
				<NcButton @click="copyToken">
					{{ t('pipelinq', 'Copy to clipboard') }}
				</NcButton>
			</div>
		</template>
		<template #actions>
			<NcButton v-if="!token"
				type="primary"
				:disabled="!label || loading"
				@click="generate">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="16" />
				</template>
				{{ t('pipelinq', 'Generate') }}
			</NcButton>
			<NcButton @click="$emit('close')">
				{{ token ? t('pipelinq', 'Done') : t('pipelinq', 'Cancel') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcTextField } from '@nextcloud/vue'
import { generateUrl } from '@nextcloud/router'
import axios from '@nextcloud/axios'

export default {
	name: 'GenerateTokenDialog',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcTextField,
	},
	emits: ['close', 'generated'],
	data() {
		return {
			label: '',
			token: null,
			loading: false,
		}
	},
	methods: {
		async generate() {
			this.loading = true
			try {
				const { data } = await axios.post(generateUrl('/apps/pipelinq/api/settings/api-tokens'), {
					label: this.label,
				})
				this.token = data.token
				this.$emit('generated', this.token)
			} catch (e) {
				alert(e.response?.data?.message || t('pipelinq', 'Failed to generate token.'))
			} finally {
				this.loading = false
			}
		},
		copyToken() {
			if (this.token) {
				navigator.clipboard.writeText(this.token.token)
			}
		},
	},
}
</script>

<style scoped>
.token-display {
	display: flex;
	flex-direction: column;
	gap: 8px;
}
</style>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<div class="avg-bundle">
		<NcNoteCard v-if="error" type="error">
			{{ error }}
		</NcNoteCard>

		<div v-if="!bundle">
			<NcButton type="primary" :disabled="generating" @click="generate">
				<template v-if="generating" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('pipelinq', 'Generate bundle') }}
			</NcButton>
		</div>

		<div v-else class="avg-bundle__meta">
			<NcNoteCard v-if="downloadToken" type="success">
				{{ t('pipelinq', 'Bundle ready. The one-time download link is shown once — deliver it to the data subject securely.') }}
			</NcNoteCard>

			<dl>
				<dt>{{ t('pipelinq', 'Items') }}</dt>
				<dd>{{ bundle.bevatItems }}</dd>
				<dt>{{ t('pipelinq', 'Size') }}</dt>
				<dd>{{ bundle.bestandsgrootte }}</dd>
				<dt>{{ t('pipelinq', 'Signature') }}</dt>
				<dd>{{ bundle.ondertekeningsType }}</dd>
				<dt>{{ t('pipelinq', 'Integrity hash (SHA-256)') }}</dt>
				<dd class="avg-bundle__hash">
					{{ bundle.sha256 }}
				</dd>
				<dt>{{ t('pipelinq', 'Download expires') }}</dt>
				<dd>{{ bundle.downloadVerloopt }}</dd>
			</dl>

			<div v-if="downloadToken" class="avg-bundle__token">
				<label>{{ t('pipelinq', 'One-time download token') }}</label>
				<code>{{ downloadToken }}</code>
			</div>
		</div>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import avgApi from '../../services/avgApi.js'

export default {
	name: 'BundlePreview',
	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		/** The parent request id. */
		verzoekId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			bundle: null,
			downloadToken: '',
			generating: false,
			error: '',
		}
	},
	methods: {
		/**
		 * Generate the export bundle.
		 */
		async generate() {
			this.generating = true
			this.error = ''
			try {
				const result = await avgApi.generateBundle(this.verzoekId)
				this.bundle = result.bundle
				this.downloadToken = result.downloadToken
				this.$emit('generated', result.bundle)
			} catch (e) {
				this.error = e?.response?.data?.error || this.t('pipelinq', 'Could not generate the bundle')
			} finally {
				this.generating = false
			}
		},
	},
}
</script>

<style scoped>
.avg-bundle { display: flex; flex-direction: column; gap: 12px; }
.avg-bundle__meta dl { display: grid; grid-template-columns: max-content 1fr; gap: 4px 16px; }
.avg-bundle__meta dt { font-weight: bold; }
.avg-bundle__hash { font-family: monospace; word-break: break-all; }
.avg-bundle__token code { display: block; padding: 6px; background: var(--color-background-hover); word-break: break-all; }
</style>

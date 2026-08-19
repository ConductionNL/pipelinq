<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -->
<template>
	<NcDialog
		:name="t('pipelinq', 'Export test run')"
		:open="true"
		size="normal"
		@closing="$emit('close')">
		<div class="export-test-run">
			<div v-if="busy" class="export-test-run__loading">
				<NcLoadingIcon :size="32" />
				<p>{{ t('pipelinq', 'Running test export…') }}</p>
			</div>
			<NcNoteCard v-else-if="result && result.success" type="success">
				<p>{{ t('pipelinq', 'Test run succeeded.') }}</p>
				<ul class="export-test-run__list">
					<li>
						<strong>{{ t('pipelinq', 'Sample rows') }}:</strong>
						{{ sampleRows }}
					</li>
					<li v-if="format">
						<strong>{{ t('pipelinq', 'Format') }}:</strong>
						{{ format }}
					</li>
					<li v-if="destination">
						<strong>{{ t('pipelinq', 'Destination') }}:</strong>
						{{ destination }}
					</li>
					<li v-if="downloadUrl">
						<a :href="downloadUrl" :download="downloadName" rel="noopener">
							{{ t('pipelinq', 'Download sample file') }}
						</a>
					</li>
				</ul>
			</NcNoteCard>
			<NcNoteCard v-else-if="result" type="error">
				<p>{{ t('pipelinq', 'Test run failed.') }}</p>
				<ul v-if="errors.length" class="export-test-run__list">
					<li v-for="(message, index) in errors" :key="index">
						{{ message }}
					</li>
				</ul>
			</NcNoteCard>
			<NcNoteCard v-else type="warning">
				<p>{{ t('pipelinq', 'No test result yet.') }}</p>
			</NcNoteCard>
		</div>
		<template #actions>
			<NcButton :disabled="busy" variant="tertiary" @click="rerun">
				{{ t('pipelinq', 'Run again') }}
			</NcButton>
			<NcButton variant="primary" @click="$emit('close')">
				{{ t('pipelinq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { exportApi } from '../services/exportApi.js'

export default {
	name: 'ExportTestRunModal',
	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
	},
	props: {
		jobId: {
			type: String,
			required: true,
		},
		initialResult: {
			type: Object,
			default: null,
		},
		autoRun: {
			type: Boolean,
			default: true,
		},
	},
	emits: ['close', 'completed'],
	data() {
		return {
			busy: false,
			result: this.initialResult,
		}
	},
	computed: {
		/**
		 * Sample row count surfaced from the test run result envelope.
		 *
		 * The backend service returns either `sample_rows` (snake) or
		 * `sampleRows` (camel) depending on the upstream response — accept both.
		 *
		 * @return {number} The sample row count.
		 */
		sampleRows() {
			if (this.result === null) {
				return 0
			}
			if (typeof this.result.sample_rows === 'number') {
				return this.result.sample_rows
			}
			if (typeof this.result.sampleRows === 'number') {
				return this.result.sampleRows
			}
			return 0
		},
		/**
		 * Errors collected from the test run result.
		 *
		 * @return {Array<string>} The error messages.
		 */
		errors() {
			if (this.result === null) {
				return []
			}
			const list = this.result.errors || []
			return Array.isArray(list) ? list : [String(list)]
		},
		/**
		 * Optional sample-file download URL surfaced from the result envelope.
		 *
		 * @return {string|null} The download URL.
		 */
		downloadUrl() {
			if (this.result === null) {
				return null
			}
			return this.result.download_url || this.result.downloadUrl || null
		},
		/**
		 * Filename hint for the sample download (server-provided or derived).
		 *
		 * @return {string} The filename.
		 */
		downloadName() {
			if (this.result === null) {
				return 'export-test-sample'
			}
			return this.result.filename || this.result.fileName || 'export-test-sample'
		},
		/**
		 * The format reported in the result, for context.
		 *
		 * @return {string|null} The format.
		 */
		format() {
			if (this.result === null) {
				return null
			}
			return this.result.format || null
		},
		/**
		 * The destination type or name reported in the result, for context.
		 *
		 * @return {string|null} The destination label.
		 */
		destination() {
			if (this.result === null) {
				return null
			}
			return this.result.destination || this.result.destinationType || null
		},
	},
	mounted() {
		if (this.autoRun && this.result === null) {
			this.rerun()
		}
	},
	methods: {
		/**
		 * Execute the test run and update local result.
		 */
		async rerun() {
			this.busy = true
			try {
				const data = await exportApi.testRun(this.jobId)
				this.result = data || { success: false, errors: ['No response'] }
			} catch (e) {
				this.result = { success: false, errors: [e.message || 'Test run failed'] }
			} finally {
				this.busy = false
				this.$emit('completed', this.result)
			}
		},
	},
}
</script>

<style scoped>
.export-test-run {
	min-width: 320px;
}

.export-test-run__loading {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 12px;
	padding: 24px 0;
}

.export-test-run__list {
	margin: 8px 0 0;
	padding-left: 18px;
	list-style: disc;
}

.export-test-run__list li + li {
	margin-top: 4px;
}
</style>

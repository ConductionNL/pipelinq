<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - FullExportSection — "Take your data with you" on the BI export page
  - (data-portability-full-export, task 3.1). One button exports all data into
  - one archive in the administrator's Files; the line under it says how the
  - last export went. The section asks the server for that state, which only
  - administrators get an answer to, and stays hidden for anyone else.
  -->
<template>
	<section
		v-if="visible"
		class="pipelinq-full-export"
		:aria-labelledby="headingId"
		data-testid="full-export-section">
		<h3 :id="headingId">
			{{ t('pipelinq', 'Take your data with you') }}
		</h3>
		<p>
			{{ t('pipelinq', 'Export every Pipelinq record, with its schema, into one archive in your Files. You get a notification when it is ready.') }}
		</p>
		<NcButton
			variant="secondary"
			:disabled="busy || state.state === 'queued'"
			data-testid="full-export-start"
			@click="start">
			{{ t('pipelinq', 'Export all data') }}
		</NcButton>
		<p class="pipelinq-full-export__state" role="status" data-testid="full-export-state">
			{{ stateText }}
		</p>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'

/** The endpoint of the full export. */
const URL = '/apps/pipelinq/api/export/full'

export default {
	name: 'FullExportSection',

	components: { NcButton },

	data() {
		return {
			visible: false,
			busy: false,
			state: { state: 'none' },
			headingId: 'pipelinq-full-export-heading',
		}
	},

	computed: {
		/**
		 * The state of the last export in words.
		 *
		 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
		 * @return {string} The line.
		 */
		stateText() {
			const at = this.state.at ? new Date(this.state.at).toLocaleString() : ''
			switch (this.state.state) {
			case 'queued':
				return t('pipelinq', 'The archive is being prepared. You get a notification when it is in your Files.')
			case 'ready':
				return t('pipelinq', 'Last export: {name}, in your Files since {at}.', { name: this.state.name || '', at })
			case 'failed':
				return t('pipelinq', 'The last export, on {at}, could not be built. Try again.', { at })
			default:
				return t('pipelinq', 'No export has been made yet.')
			}
		},
	},

	async mounted() {
		await this.load()
	},

	methods: {
		/**
		 * Read the state of the last export; anyone but an administrator is refused and sees nothing.
		 *
		 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
		 * @return {Promise<void>}
		 */
		async load() {
			try {
				const { data } = await axios.get(generateUrl(URL))
				this.state = data && typeof data.state === 'string' ? data : { state: 'none' }
				this.visible = true
			} catch {
				this.visible = false
			}
		},

		/**
		 * Ask for an export of all data, then show its state.
		 *
		 * @spec openspec/changes/data-portability-full-export/tasks.md#3.1
		 * @return {Promise<void>}
		 */
		async start() {
			this.busy = true
			try {
				await axios.post(generateUrl(URL))
				await this.load()
			} catch (e) {
				showError(e?.response?.data?.error || t('pipelinq', 'The export could not be started.'))
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.pipelinq-full-export {
	margin-top: calc(var(--default-grid-baseline, 4px) * 6);
	padding: calc(var(--default-grid-baseline, 4px) * 4);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.pipelinq-full-export__state {
	color: var(--color-text-maxcontrast);
	margin-top: calc(var(--default-grid-baseline, 4px) * 2);
}
</style>

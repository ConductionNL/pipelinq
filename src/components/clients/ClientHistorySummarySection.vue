<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The History summary card on ClientDetail (clients-history-summary-and-risk,
REQ-CIN-002). Summarise asks hermiq for a few sentences about the client's
last 90 days, written only from the records the viewer may read. The card says
how many records it read and when, and that the text is generated. Nothing is
stored: leaving the page drops it. Without hermiq the card stays, without the
button, and says summarising is not available.

@spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
-->
<template>
	<div class="client-history-summary" data-testid="client-history-summary">
		<NcLoadingIcon v-if="checking" :size="24" />
		<p
			v-else-if="!available"
			class="client-history-summary__muted"
			data-testid="client-history-summary-unavailable">
			{{ t('pipelinq', 'Summarising is not available: it needs the hermiq app.') }}
		</p>
		<template v-else>
			<NcButton
				:disabled="loading"
				data-testid="client-history-summary-run"
				@click="summarise">
				<template #icon>
					<NcLoadingIcon v-if="loading" :size="20" />
				</template>
				{{ result ? t('pipelinq', 'Summarise again') : t('pipelinq', 'Summarise') }}
			</NcButton>
			<p v-if="error" class="client-history-summary__error" role="alert">
				{{ error }}
			</p>
			<template v-else-if="result">
				<p
					v-if="result.summary"
					class="client-history-summary__text"
					data-testid="client-history-summary-text">
					{{ result.summary }}
				</p>
				<p v-else-if="result.recordsRead === 0" class="client-history-summary__muted">
					{{ t('pipelinq', 'Nothing you may read happened with this client in the last 90 days.') }}
				</p>
				<p v-else class="client-history-summary__muted">
					{{ t('pipelinq', 'No summary could be written. Try again later.') }}
				</p>
				<p class="client-history-summary__muted" data-testid="client-history-summary-meta">
					{{ metaLine }}
				</p>
			</template>
		</template>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import { fetchSummary, fetchSummaryAvailable } from '../../services/clientHistorySummaryApi.js'
import { sectionObjectId } from '../../utils/sectionContext.js'

export default {
	name: 'ClientHistorySummarySection',
	components: { NcButton, NcLoadingIcon },
	inject: {
		cnSectionContext: { default: null },
	},

	props: {
		/** The client id (resolved from `@objectId`). */
		clientId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			checking: false,
			available: false,
			loading: false,
			error: '',
			result: null,
		}
	},

	computed: {
		/**
		 * The client id from the prop or the section context.
		 *
		 * @return {string}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
		 */
		resolvedId() {
			return this.clientId || sectionObjectId(this.cnSectionContext)
		},

		/**
		 * How many records were read and when, and that the text is generated.
		 *
		 * @return {string}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
		 */
		metaLine() {
			if (!this.result) {
				return ''
			}
			const date = new Date(this.result.generatedAt || '')
			const when = Number.isNaN(date.getTime()) ? '-' : date.toLocaleString()
			return this.n(
				'pipelinq',
				'Generated from {count} record at {time}.',
				'Generated from {count} records at {time}.',
				this.result.recordsRead,
				{ count: this.result.recordsRead, time: when },
			)
		},
	},

	watch: {
		/**
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
		 */
		resolvedId() {
			this.result = null
			this.check()
		},
	},

	mounted() {
		this.check()
	},

	methods: {
		/**
		 * Ask whether summarising is available.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
		 */
		async check() {
			if (!this.resolvedId) {
				return
			}
			this.checking = true
			try {
				this.available = await fetchSummaryAvailable(this.resolvedId)
			} catch {
				this.available = false
			} finally {
				this.checking = false
			}
		},

		/**
		 * Write the summary now.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/clients-history-summary-and-risk/tasks.md#task-2.3
		 */
		async summarise() {
			this.loading = true
			this.error = ''
			try {
				const result = await fetchSummary(this.resolvedId)
				if (!result.available) {
					this.available = false
					return
				}
				this.result = result
			} catch {
				this.error = this.t('pipelinq', 'The summary could not be written.')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.client-history-summary__text {
	margin: 12px 0 4px;
	white-space: pre-line;
}

.client-history-summary__muted {
	color: var(--color-text-maxcontrast);
}

.client-history-summary__error {
	color: var(--color-error-text);
}
</style>

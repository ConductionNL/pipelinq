<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The satisfaction panel on the client page (customer-satisfaction-closed-loop,
customer-360 REQ per-client satisfaction panel). It shows the client's NPS,
average rating, how many responses there are, whether the last 90 days are
better or worse than the 90 before, and the three latest comments. A client
nobody has surveyed gets a sentence saying so.

@spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-360/spec.md#requirement-per-client-satisfaction-panel
-->
<template>
	<div class="client-satisfaction">
		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="client-satisfaction__error" role="alert">
			{{ error }}
		</p>
		<p
			v-else-if="panel.empty"
			class="client-satisfaction__empty"
			data-testid="client-satisfaction-empty">
			{{
				t(
					'pipelinq',
					'No satisfaction data has been collected for this client yet. It appears here once they answer a survey.',
				)
			}}
		</p>
		<template v-else>
			<dl class="client-satisfaction__figures">
				<div>
					<dt>{{ t('pipelinq', 'NPS') }}</dt>
					<dd data-testid="client-satisfaction-nps">
						{{ number(panel.nps) }}
					</dd>
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Average rating') }}</dt>
					<dd>{{ number(panel.averageRating) }}</dd>
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Responses') }}</dt>
					<dd>{{ panel.responseCount }}</dd>
				</div>
				<div>
					<dt>{{ t('pipelinq', 'Trend') }}</dt>
					<dd>{{ trendLabel }}</dd>
				</div>
			</dl>
			<h4 v-if="verbatims.length > 0">
				{{ t('pipelinq', 'Latest comments') }}
			</h4>
			<ul v-if="verbatims.length > 0" class="client-satisfaction__verbatims">
				<li v-for="(item, index) in verbatims" :key="index">
					<q>{{ item.text }}</q>
				</li>
			</ul>
		</template>
	</div>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { fetchClientSatisfaction } from '../../services/satisfactionApi.js'

export default {
	name: 'ClientSatisfactionSection',
	components: { NcLoadingIcon },
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
			loading: false,
			error: '',
			panel: { empty: true },
		}
	},

	computed: {
		/**
		 * The client id from the prop or the section context.
		 *
		 * @return {string} The id.
		 */
		resolvedId() {
			if (this.clientId) {
				return this.clientId
			}
			const ctx = this.cnSectionContext
			const bag =
				ctx && typeof ctx === 'object' && 'value' in ctx ? ctx.value : ctx
			return (bag && bag.objectId) || ''
		},

		/**
		 * Up to three latest comments.
		 *
		 * @return {Array<object>} The comments.
		 */
		verbatims() {
			return (
				Array.isArray(this.panel.verbatims) ? this.panel.verbatims : []
			).slice(0, 3)
		},

		/**
		 * The trend in words.
		 *
		 * @return {string} The label.
		 */
		trendLabel() {
			const labels = {
				up: this.t('pipelinq', 'Better than the 90 days before'),
				down: this.t('pipelinq', 'Worse than the 90 days before'),
				flat: this.t('pipelinq', 'The same as the 90 days before'),
			}
			return (
				labels[this.panel.trend]
				|| this.t('pipelinq', 'Not enough responses to compare')
			)
		},
	},

	watch: {
		resolvedId() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the panel for this client.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			if (!this.resolvedId) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.panel = await fetchClientSatisfaction(this.resolvedId)
			} catch {
				this.error = this.t(
					'pipelinq',
					'The satisfaction figures could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * A figure, or a dash when there is none.
		 *
		 * @param {number|null} value The figure.
		 * @return {string} The text.
		 * @spec exclude display formatter: a nullable number to a localised string
		 */
		number(value) {
			if (value === null || value === undefined) {
				return '-'
			}
			return Number(value).toLocaleString(undefined, {
				maximumFractionDigits: 1,
			})
		},
	},
}
</script>

<style scoped>
.client-satisfaction__figures {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 12px;
	margin: 0 0 12px;
}

.client-satisfaction__figures dt {
	color: var(--color-text-maxcontrast);
}

.client-satisfaction__figures dd {
	margin: 0;
	font-size: 1.3em;
	font-weight: bold;
}

.client-satisfaction__verbatims {
	margin: 0;
	padding-inline-start: 20px;
}

.client-satisfaction__empty {
	color: var(--color-text-maxcontrast);
}

.client-satisfaction__error {
	color: var(--color-error-text);
}
</style>

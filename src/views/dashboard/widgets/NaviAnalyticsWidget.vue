<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2026 Conduction B.V.
  -
  - Navi AI analytics widget — a conversational panel that turns natural-language
  - questions into inline charts/tables via the server-side /api/navi/query
  - endpoint. Rendering reuses the platform CnChartWidget / CnTableWidget; no
  - chart component is built here.
  -->
<template>
	<div class="navi-widget">
		<div ref="history" class="navi-history">
			<p v-if="messages.length === 0" class="navi-empty">
				{{ t('pipelinq', 'Ask Navi a question about your leads, requests or contact moments.') }}
			</p>
			<div
				v-for="(message, index) in messages"
				:key="index"
				class="navi-message"
				:class="'navi-message--' + message.role">
				<div v-if="message.role === 'user'" class="navi-bubble navi-bubble--user">
					{{ message.text }}
				</div>
				<div v-else class="navi-bubble navi-bubble--navi">
					<p v-if="message.textResponse" class="navi-text">
						{{ message.textResponse }}
					</p>
					<CnChartWidget
						v-if="message.resultType === 'chart' && message.chartData"
						:type="message.chartData.type"
						:series="chartSeries(message.chartData)"
						:labels="message.chartData.labels"
						:height="220" />
					<CnTableWidget
						v-else-if="message.resultType === 'table' && message.tableData"
						:title="message.tableData.label"
						:columns="tableColumns(message.tableData)"
						:rows="message.tableData.rows" />
					<div
						v-if="message.suggestedFollowUps && message.suggestedFollowUps.length > 0"
						class="navi-suggestions">
						<button
							v-for="(suggestion, sIndex) in message.suggestedFollowUps.slice(0, 3)"
							:key="sIndex"
							type="button"
							class="navi-chip"
							:disabled="loading"
							@click="selectSuggestion(suggestion)">
							{{ suggestion }}
						</button>
					</div>
				</div>
			</div>
			<p v-if="loading" class="navi-loading">
				{{ t('pipelinq', 'Navi is thinking…') }}
			</p>
			<p v-if="error" class="navi-error">
				{{ error }}
			</p>
		</div>
		<form class="navi-input" @submit.prevent="submitQuery()">
			<NcTextField
				:value.sync="query"
				:label="t('pipelinq', 'Ask Navi a question')"
				:disabled="loading" />
			<NcButton
				type="primary"
				native-type="submit"
				:disabled="loading || query.trim() === ''">
				{{ t('pipelinq', 'Ask') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { CnChartWidget, CnTableWidget } from '@conduction/nextcloud-vue'

export default {
	name: 'NaviAnalyticsWidget',
	components: {
		NcButton,
		NcTextField,
		CnChartWidget,
		CnTableWidget,
	},
	data() {
		return {
			query: '',
			conversationId: null,
			messages: [],
			loading: false,
			error: '',
		}
	},
	methods: {
		/**
		 * Submit the current query to the Navi backend and append the result.
		 */
		async submitQuery() {
			const text = this.query.trim()
			if (text === '' || this.loading) {
				return
			}
			this.error = ''
			this.loading = true
			this.messages.push({ role: 'user', text })
			this.query = ''
			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/navi/query'),
					{ query: text, conversationId: this.conversationId },
				)
				if (data.conversationId) {
					this.conversationId = data.conversationId
				}
				this.messages.push({
					role: 'navi',
					resultType: data.resultType,
					textResponse: data.textResponse || '',
					chartData: data.chartData || null,
					tableData: data.tableData || null,
					suggestedFollowUps: data.suggestedFollowUps || [],
				})
			} catch (err) {
				this.error = this.t('pipelinq', 'Navi could not answer that question. Please try again.')
			} finally {
				this.loading = false
				this.$nextTick(() => this.scrollToBottom())
			}
		},
		/**
		 * Pre-fill the input with a suggestion and submit it automatically.
		 *
		 * @param {string} suggestion - The follow-up question to ask.
		 */
		selectSuggestion(suggestion) {
			this.query = suggestion
			this.submitQuery()
		},
		/**
		 * Map a Navi chartData payload to the apexcharts series shape.
		 *
		 * @param {object} chartData - The chartData payload from the API.
		 * @return {Array} The chart series.
		 */
		chartSeries(chartData) {
			return [{ name: chartData.label || '', data: chartData.values || [] }]
		},
		/**
		 * Map Navi tableData columns to CnTableWidget column definitions.
		 *
		 * @param {object} tableData - The tableData payload from the API.
		 * @return {Array} Column definitions.
		 */
		tableColumns(tableData) {
			return (tableData.columns || []).map((key) => ({
				key,
				label: key === 'metric' ? this.t('pipelinq', 'Metric') : this.t('pipelinq', 'Value'),
			}))
		},
		/**
		 * Scroll the conversation history to the latest message.
		 */
		scrollToBottom() {
			const el = this.$refs.history
			if (el) {
				el.scrollTop = el.scrollHeight
			}
		},
	},
}
</script>

<style scoped>
.navi-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
	padding: 12px;
	gap: 8px;
}

.navi-history {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.navi-empty,
.navi-loading {
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	text-align: center;
	padding: 12px;
}

.navi-error {
	color: var(--color-error);
	font-size: 14px;
	padding: 8px;
}

.navi-message {
	display: flex;
}

.navi-message--user {
	justify-content: flex-end;
}

.navi-bubble {
	max-width: 90%;
	padding: 10px 12px;
	border-radius: var(--border-radius-large);
}

.navi-bubble--user {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
}

.navi-bubble--navi {
	background: var(--color-background-hover);
	width: 100%;
}

.navi-text {
	margin: 0 0 8px;
}

.navi-suggestions {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 10px;
}

.navi-chip {
	border: 1px solid var(--color-border);
	background: var(--color-main-background);
	border-radius: var(--border-radius-pill);
	padding: 4px 12px;
	font-size: 13px;
	cursor: pointer;
	color: var(--color-main-text);
}

.navi-chip:hover:not(:disabled) {
	background: var(--color-background-dark);
}

.navi-chip:disabled {
	opacity: 0.5;
	cursor: default;
}

.navi-input {
	display: flex;
	gap: 8px;
	align-items: flex-end;
}

.navi-input :deep(.input-field) {
	flex: 1;
}
</style>

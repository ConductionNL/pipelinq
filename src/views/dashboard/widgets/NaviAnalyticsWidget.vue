<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Navi AI conversational analytics widget (openspec/changes/dashboard,
  REQ-DASH-001 / REQ-DASH-003).

  Renders a chat-style interface inside the dashboard grid:
  - An input + submit row to ask natural-language questions
  - A scrollable conversation history
  - Each Navi response is rendered as a CnChartWidget (chart),
    CnDataTable (table), or plain text
  - Up to 3 suggested follow-up chips appear after each response;
    clicking one re-submits the suggestion automatically

  All API calls go through @nextcloud/axios; every await is wrapped in
  try/catch so the user always sees a visible error rather than a silent
  console message. Strings are translated via this.t('pipelinq', ...) —
  English keys, never hardcoded Dutch.
-->
<template>
	<div class="navi-widget">
		<div class="navi-widget__history" aria-live="polite">
			<div v-if="messages.length === 0" class="navi-widget__welcome">
				<p>{{ t('pipelinq', 'Ask Navi a question about your leads, requests or pipeline.') }}</p>
				<p class="navi-widget__hint">
					{{ t('pipelinq', 'Try: "How many leads are open?" or "Show requests by category".') }}
				</p>
			</div>
			<div
				v-for="(msg, idx) in messages"
				:key="idx"
				class="navi-widget__message"
				:class="'navi-widget__message--' + msg.role">
				<div class="navi-widget__bubble">
					<div v-if="msg.role === 'user'" class="navi-widget__user-text">
						{{ msg.text }}
					</div>
					<div v-else class="navi-widget__assistant">
						<p v-if="msg.text" class="navi-widget__assistant-text">
							{{ msg.text }}
						</p>
						<CnChartWidget
							v-if="msg.resultType === 'chart' && msg.chartData"
							:type="resolveChartType(msg.chartData)"
							:labels="msg.chartData.labels || []"
							:series="resolveSeries(msg.chartData)"
							:title="''"
							class="navi-widget__chart" />
						<CnDataTable
							v-else-if="msg.resultType === 'table' && msg.tableData"
							:columns="resolveTableColumns(msg.tableData)"
							:rows="resolveTableRows(msg.tableData)"
							borderless
							class="navi-widget__table" />
						<div
							v-if="msg.suggestedFollowUps && msg.suggestedFollowUps.length"
							class="navi-widget__suggestions">
							<NcButton
								v-for="(s, sIdx) in msg.suggestedFollowUps.slice(0, 3)"
								:key="sIdx"
								variant="secondary"
								@click="selectSuggestion(s)">
								{{ s }}
							</NcButton>
						</div>
					</div>
				</div>
			</div>
			<div v-if="loading" class="navi-widget__loading">
				{{ t('pipelinq', 'Navi is thinking…') }}
			</div>
			<div v-if="error" class="navi-widget__error">
				{{ error }}
			</div>
		</div>
		<form class="navi-widget__form" @submit.prevent="submitQuery">
			<NcTextField
				v-model="query"
				:label="t('pipelinq', 'Ask Navi a question')"
				:placeholder="t('pipelinq', 'e.g. How many leads were won this month?')"
				class="navi-widget__input" />
			<NcButton
				variant="primary"
				native-type="submit"
				:disabled="loading || !query.trim()">
				{{ t('pipelinq', 'Send') }}
			</NcButton>
		</form>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcTextField } from '@nextcloud/vue'
import { CnChartWidget, CnDataTable } from '@conduction/nextcloud-vue'

/**
 * NaviAnalyticsWidget — conversational analytics chat panel.
 *
 * @spec openspec/specs/dashboard/spec.md
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'NaviAnalyticsWidget',
	components: {
		NcButton,
		NcTextField,
		CnChartWidget,
		CnDataTable,
	},
	data() {
		return {
			query: '',
			conversationId: null,
			messages: [],
			loading: false,
			error: null,
		}
	},
	methods: {
		/**
		 * Post the current query to /api/navi/query and append the response
		 * to the conversation history.
		 *
		 * @spec openspec/specs/dashboard/spec.md
		 */
		async submitQuery() {
			const text = this.query.trim()
			if (!text || this.loading) {
				return
			}
			this.messages.push({ role: 'user', text })
			this.query = ''
			this.loading = true
			this.error = null

			try {
				const { data } = await axios.post(
					generateUrl('/apps/pipelinq/api/navi/query'),
					{ query: text, conversationId: this.conversationId },
				)
				if (data && data.conversationId) {
					this.conversationId = data.conversationId
				}
				this.messages.push({
					role: 'assistant',
					text: data?.textResponse || '',
					resultType: data?.resultType || 'text',
					chartData: data?.chartData || null,
					tableData: data?.tableData || null,
					suggestedFollowUps: data?.suggestedFollowUps || [],
				})
			} catch (err) {
				console.error('NaviAnalyticsWidget submit error:', err)
				this.error = this.t('pipelinq', 'Navi could not answer that question. Please try again.')
			} finally {
				this.loading = false
			}
		},
		/**
		 * Pre-fill the input with a suggestion and submit immediately.
		 *
		 * @param {string} suggestion - The follow-up text to send.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		selectSuggestion(suggestion) {
			this.query = suggestion
			this.submitQuery()
		},
		/**
		 * Normalise the chart type to one accepted by CnChartWidget.
		 *
		 * @param {object} chartData - Raw chartData payload.
		 * @return {string} A valid CnChartWidget type.
		 */
		resolveChartType(chartData) {
			const allowed = ['line', 'bar', 'donut', 'pie', 'area']
			if (chartData && allowed.includes(chartData.type)) {
				return chartData.type
			}
			return 'bar'
		},
		/**
		 * Coerce the backend "series" payload into CnChartWidget's series array.
		 *
		 * @param {object} chartData - Raw chartData payload.
		 * @return {Array} Series array safe for CnChartWidget.
		 */
		resolveSeries(chartData) {
			if (Array.isArray(chartData?.series)) {
				return chartData.series.map(s => Array.isArray(s) ? s : (s?.data || []))
			}
			return []
		},
		/**
		 * Build a CnDataTable-compatible columns spec from the backend payload.
		 *
		 * @param {object} tableData - Raw tableData payload.
		 * @return {Array} Columns array.
		 */
		resolveTableColumns(tableData) {
			if (!Array.isArray(tableData?.columns)) {
				return []
			}
			return tableData.columns.map((col, idx) => ({ key: 'col' + idx, label: col }))
		},
		/**
		 * Build CnDataTable-compatible row objects keyed by `col{idx}`.
		 *
		 * @param {object} tableData - Raw tableData payload.
		 * @return {Array} Rows array.
		 */
		resolveTableRows(tableData) {
			if (!Array.isArray(tableData?.rows)) {
				return []
			}
			return tableData.rows.map((row, idx) => {
				const out = { id: idx }
				row.forEach((cell, cIdx) => {
					out['col' + cIdx] = cell
				})
				return out
			})
		},
	},
}
</script>

<style scoped>
.navi-widget {
	display: flex;
	flex-direction: column;
	height: 100%;
	gap: 8px;
	padding: 8px;
	box-sizing: border-box;
}

.navi-widget__history {
	flex: 1;
	overflow-y: auto;
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 4px;
}

.navi-widget__welcome {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	padding: 12px;
}

.navi-widget__hint {
	margin-top: 4px;
	font-style: italic;
}

.navi-widget__message {
	display: flex;
}

.navi-widget__message--user {
	justify-content: flex-end;
}

.navi-widget__message--assistant {
	justify-content: flex-start;
}

.navi-widget__bubble {
	max-width: 80%;
	padding: 8px 12px;
	border-radius: 12px;
	background: var(--color-background-hover);
	font-size: 13px;
}

.navi-widget__message--user .navi-widget__bubble {
	background: var(--color-primary-light);
	color: var(--color-primary-text-on-primary-light, var(--color-main-text));
}

.navi-widget__user-text {
	white-space: pre-wrap;
}

.navi-widget__assistant-text {
	margin: 0 0 6px 0;
}

.navi-widget__chart,
.navi-widget__table {
	margin-top: 6px;
}

.navi-widget__suggestions {
	margin-top: 8px;
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}

.navi-widget__loading,
.navi-widget__error {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	padding: 4px 8px;
}

.navi-widget__error {
	color: var(--color-error);
}

.navi-widget__form {
	display: flex;
	gap: 6px;
	align-items: flex-end;
}

.navi-widget__input {
	flex: 1;
}
</style>

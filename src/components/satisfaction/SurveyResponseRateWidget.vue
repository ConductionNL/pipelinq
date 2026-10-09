<!--
SPDX-License-Identifier: EUPL-1.2
SPDX-FileCopyrightText: 2026 Conduction B.V.

The response rate of the satisfaction invitations, on the Operational
dashboard (customer-satisfaction-closed-loop, REQ response-rate analytics).
The rate is responses out of delivered invitations; suppressed and failed
invitations were never delivered, so they are counted beside the rate and
kept out of it. Each channel gets its own line.

@spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
-->
<template>
	<div class="survey-response-rate">
		<div class="survey-response-rate__period">
			<label for="survey-response-rate-period">{{
				t('pipelinq', 'Period')
			}}</label>
			<select
				id="survey-response-rate-period"
				v-model.number="days"
				@change="load">
				<option
					v-for="option in periods"
					:key="option.days"
					:value="option.days">
					{{ option.label }}
				</option>
			</select>
		</div>

		<NcLoadingIcon v-if="loading" :size="24" />
		<p v-else-if="error" class="survey-response-rate__error" role="alert">
			{{ error }}
		</p>
		<NcEmptyContent
			v-else-if="!hasData"
			:name="t('pipelinq', 'No survey invitations yet')"
			:description="
				t(
					'pipelinq',
					'When a dispatch rule sends satisfaction surveys, the response rate shows here.',
				)
			" />
		<template v-else>
			<p
				class="survey-response-rate__headline"
				data-testid="survey-response-rate">
				<strong>{{ percent(figures.rate) }}</strong>
				{{
					t(
						'pipelinq',
						'{responded} of {delivered} delivered invitations answered',
						{
							responded: figures.responded,
							delivered: figures.delivered,
						},
					)
				}}
			</p>
			<p class="survey-response-rate__aside">
				{{
					t(
						'pipelinq',
						'Not delivered: {suppressed} held back, {failed} failed',
						{ suppressed: figures.suppressed, failed: figures.failed },
					)
				}}
			</p>
			<table v-if="channels.length > 0" class="survey-response-rate__channels">
				<caption>
					{{
						t('pipelinq', 'Per channel')
					}}
				</caption>
				<thead>
					<tr>
						<th scope="col">{{ t('pipelinq', 'Channel') }}</th>
						<th scope="col">{{ t('pipelinq', 'Delivered') }}</th>
						<th scope="col">{{ t('pipelinq', 'Answered') }}</th>
						<th scope="col">{{ t('pipelinq', 'Rate') }}</th>
						<th scope="col">{{ t('pipelinq', 'Held back') }}</th>
						<th scope="col">{{ t('pipelinq', 'Failed') }}</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in channels" :key="row.channel">
						<th scope="row">{{ channelLabel(row.channel) }}</th>
						<td>{{ row.delivered }}</td>
						<td>{{ row.responded }}</td>
						<td>{{ percent(row.rate) }}</td>
						<td>{{ row.suppressed }}</td>
						<td>{{ row.failed }}</td>
					</tr>
				</tbody>
			</table>
		</template>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import { fetchResponseRate } from '../../services/satisfactionApi.js'

export default {
	name: 'SurveyResponseRateWidget',
	components: { NcEmptyContent, NcLoadingIcon },
	data() {
		return {
			days: 30,
			loading: false,
			error: '',
			figures: {},
		}
	},

	computed: {
		/**
		 * The periods to choose from.
		 *
		 * @return {Array<{days: number, label: string}>} The periods.
		 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
		 */
		periods() {
			return [
				{ days: 30, label: this.t('pipelinq', 'Last 30 days') },
				{ days: 90, label: this.t('pipelinq', 'Last 90 days') },
				{ days: 365, label: this.t('pipelinq', 'Last 365 days') },
				{ days: 0, label: this.t('pipelinq', 'All time') },
			]
		},

		/**
		 * Whether any invitation falls in the period.
		 *
		 * @return {boolean} True with data.
		 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
		 */
		hasData() {
			const f = this.figures
			return (f.delivered || 0) + (f.suppressed || 0) + (f.failed || 0) > 0
		},

		/**
		 * The per-channel lines.
		 *
		 * @return {Array<object>} One row per channel.
		 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
		 */
		channels() {
			const by = this.figures.byChannel || {}
			return Object.keys(by).map((channel) => ({ channel, ...by[channel] }))
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the figures for the chosen period.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.figures = await fetchResponseRate({ days: this.days })
			} catch {
				this.error = this.t(
					'pipelinq',
					'The response rate could not be loaded.',
				)
			} finally {
				this.loading = false
			}
		},

		/**
		 * A rate as a percentage.
		 *
		 * @param {number} rate The rate, 0 to 100.
		 * @return {string} The text.
		 * @spec exclude display formatter: a number to a percentage string
		 */
		percent(rate) {
			return (
				(Number(rate) || 0).toLocaleString(undefined, {
					maximumFractionDigits: 1,
				}) + '%'
			)
		},

		/**
		 * A channel's name as a person reads it.
		 *
		 * @param {string} channel The stored channel.
		 * @return {string} The label.
		 * @spec openspec/changes/customer-satisfaction-closed-loop/specs/customer-satisfaction/spec.md#requirement-response-rate-analytics
		 */
		channelLabel(channel) {
			const labels = {
				email: this.t('pipelinq', 'Email'),
				sms: this.t('pipelinq', 'SMS'),
				whatsapp: this.t('pipelinq', 'WhatsApp'),
				unknown: this.t('pipelinq', 'Unknown'),
			}
			return labels[channel] || channel
		},
	},
}
</script>

<style scoped>
.survey-response-rate {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 8px;
}

.survey-response-rate__period {
	display: flex;
	align-items: center;
	gap: 8px;
}

.survey-response-rate__headline strong {
	font-size: 1.6em;
	margin-inline-end: 8px;
}

.survey-response-rate__aside {
	color: var(--color-text-maxcontrast);
}

.survey-response-rate__channels {
	width: 100%;
	border-collapse: collapse;
}

.survey-response-rate__channels caption {
	text-align: start;
	font-weight: bold;
}

.survey-response-rate__channels th,
.survey-response-rate__channels td {
	padding: 4px 8px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.survey-response-rate__error {
	color: var(--color-error-text);
}
</style>

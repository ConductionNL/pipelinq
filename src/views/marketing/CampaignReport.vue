<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Campaign report (marketing-campaigns): reach and clicks per channel,
  submissions, leads with what each one closed on, attributed value under
  three models, and what the campaign cost.

  🔴 ONE FETCH. GET /api/campaigns/{id}/report returns the whole record and
  this page paints from it. pipelinq#1781 fixed a performance page that
  asked the server once per blast before it rendered anything; switching the
  model here re-reads nothing, because all three models are already in the
  response.

  @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
-->
<template>
	<div class="campaign-report">
		<header class="campaign-report__header">
			<h2>{{ t('pipelinq', 'Campaign report') }}</h2>
			<div class="campaign-report__pickers">
				<NcSelect
					v-model="campaign"
					class="campaign-report__picker"
					data-testid="campaign-report-picker"
					:options="campaignOptions"
					:clearable="false"
					:inputLabel="t('pipelinq', 'Campaign')"
					label="label"
					@input="fetchReport" />
				<NcSelect
					v-model="model"
					class="campaign-report__picker"
					data-testid="campaign-report-model"
					:options="modelOptions"
					:clearable="false"
					:inputLabel="t('pipelinq', 'Attribution model')"
					label="label" />
			</div>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="!report"
			class="campaign-report__empty"
			data-testid="campaign-report-empty"
			:name="t('pipelinq', 'No campaign to report on')"
			:description="
				t(
					'pipelinq',
					'Create a campaign under Marketing, group its mailings and posts into it, and its numbers appear here.',
				)
			">
			<template #icon>
				<BullhornOutline :size="20" />
			</template>
		</NcEmptyContent>

		<section v-else data-testid="campaign-report-body">
			<p class="campaign-report__meta">
				<span>{{ report.campaign.name }}</span>
				<span>{{ report.campaign.utmCampaign }}</span>
				<span>
					{{
						t('pipelinq', '{from} to {to}', {
							from: report.window.from,
							to: report.window.to,
						})
					}}
				</span>
			</p>

			<ul class="campaign-report__tiles" data-testid="campaign-report-tiles">
				<li>
					<span class="campaign-report__tile-value">{{
						tiles.clicks
					}}</span>
					<span>{{ t('pipelinq', 'Clicks') }}</span>
				</li>
				<li>
					<span class="campaign-report__tile-value">
						{{ tiles.submissions }}
					</span>
					<span>{{ t('pipelinq', 'Submissions') }}</span>
				</li>
				<li>
					<span class="campaign-report__tile-value">{{
						tiles.leads
					}}</span>
					<span>{{ t('pipelinq', 'Leads') }}</span>
				</li>
				<li>
					<span class="campaign-report__tile-value">
						{{ money(tiles.attributedValue) }}
					</span>
					<span>{{ t('pipelinq', 'Attributed value') }}</span>
				</li>
				<li>
					<span class="campaign-report__tile-value">
						{{ moneyOrAbsent(tiles.cost) }}
					</span>
					<span>{{ t('pipelinq', 'Cost') }}</span>
				</li>
			</ul>

			<h3>{{ t('pipelinq', 'Per channel') }}</h3>
			<div class="campaign-report__scroll">
				<table
					class="campaign-report__table"
					data-testid="campaign-report-channels">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Channel') }}</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Reach') }}
							</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Opened') }}
							</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Clicks') }}
							</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Visits') }}
							</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Submissions') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in channels" :key="row.channel">
							<td>{{ row.channel }}</td>
							<td class="campaign-report__num">
								{{ countOrAbsent(row.reach) }}
							</td>
							<td class="campaign-report__num">{{ row.opened }}</td>
							<td class="campaign-report__num">{{ row.clicks }}</td>
							<td class="campaign-report__num">{{ row.visits }}</td>
							<td class="campaign-report__num">
								{{ row.submissions }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<h3>{{ t('pipelinq', 'Attributed value per channel') }}</h3>
			<div class="campaign-report__scroll">
				<table
					class="campaign-report__table"
					data-testid="campaign-report-model-rows">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Channel') }}</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Attributed value') }}
							</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Share') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in modelSplit" :key="row.channel">
							<td>{{ row.channel }}</td>
							<td class="campaign-report__num">
								{{ money(row.value) }}
							</td>
							<td class="campaign-report__num">
								{{ percent(row.share) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<h3>{{ t('pipelinq', 'Leads') }}</h3>
			<div class="campaign-report__scroll">
				<table
					class="campaign-report__table"
					data-testid="campaign-report-leads">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Lead') }}</th>
							<th scope="col">{{ t('pipelinq', 'Closed on') }}</th>
							<th scope="col" class="campaign-report__num">
								{{ t('pipelinq', 'Value') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in leads" :key="row.leadId">
							<td>{{ row.title }}</td>
							<td>
								<span
									class="campaign-report__basis"
									:style="{ color: row.chip.color }"
									:title="row.chip.description">
									{{ row.chip.label }}
								</span>
							</td>
							<td class="campaign-report__num">
								{{ money(row.value) }}
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</section>

		<p v-if="error" class="campaign-report__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcSelect } from '@nextcloud/vue'
import BullhornOutline from 'vue-material-design-icons/BullhornOutline.vue'
import {
	ATTRIBUTION_MODELS,
	channelRows,
	labelForModel,
	leadRows,
	modelRows,
	summaryTiles,
} from '../../services/campaignReport.js'
import { fetchCampaignReport, fetchCampaigns } from '../../services/campaignsApi.js'

export default {
	name: 'CampaignReport',
	components: {
		BullhornOutline,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			error: '',
			campaigns: [],
			campaign: null,
			model: null,
			report: null,
		}
	},

	computed: {
		/**
		 * The campaigns to pick from.
		 *
		 * @return {Array<object>} id and label per option.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		campaignOptions() {
			return this.campaigns.map((row) => ({
				id: row.id || row['@self']?.id || row.uuid,
				label: row.name || row.utmCampaign || '',
			}))
		},

		/**
		 * The three attribution models.
		 *
		 * @return {Array<object>} model and label per option.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
		 */
		modelOptions() {
			return ATTRIBUTION_MODELS.map((model) => ({
				model,
				label: this.t('pipelinq', labelForModel(model)),
			}))
		},

		/**
		 * The headline numbers.
		 *
		 * @return {object} The tiles.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		tiles() {
			return summaryTiles(this.report)
		},

		/**
		 * The per-channel rows.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		channels() {
			return channelRows(this.report)
		},

		/**
		 * The split under the chosen model. Switching the model re-reads
		 * nothing: all three arrived in the one response.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-is-computed-at-report-time-in-three-models
		 */
		modelSplit() {
			return modelRows(this.report, this.model?.model || 'last')
		},

		/**
		 * The lead rows, each labelled with what it closed on.
		 *
		 * @return {Array<object>} The rows.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-attribution-closes-on-a-paid-invoice-or-on-a-won-lead-and-the-report-says-which
		 */
		leads() {
			return leadRows(this.report)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the campaigns, then the report for the one in the route or
		 * the first one there is.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		async load() {
			this.loading = true
			this.error = ''
			this.model = this.modelOptions[1]
			try {
				this.campaigns = await fetchCampaigns(100)
			} catch (e) {
				this.campaigns = []
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load the campaigns.')
			}

			const wanted = this.$route?.query?.campaign
			this.campaign =
				this.campaignOptions.find((option) => option.id === wanted)
				|| this.campaignOptions[0]
				|| null

			if (!this.campaign) {
				this.loading = false
				return
			}

			await this.fetchReport()
		},

		/**
		 * GET /api/campaigns/{id}/report, once.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		async fetchReport() {
			if (!this.campaign) {
				return
			}
			this.loading = true
			this.error = ''
			try {
				this.report = await fetchCampaignReport(this.campaign.id)
				const stored = this.report?.campaign?.defaultModel
				this.model =
					this.modelOptions.find((option) => option.model === stored)
					|| this.model
			} catch (e) {
				this.report = null
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load the campaign report.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {number} amount An amount in euro.
		 * @return {string} The amount, formatted.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		money(amount) {
			return `€ ${Number(amount || 0).toLocaleString(undefined, {
				minimumFractionDigits: 2,
				maximumFractionDigits: 2,
			})}`
		},

		/**
		 * An amount, or the words that say nobody recorded one. Zero would
		 * read as free, which is a different claim.
		 *
		 * @param {number|null} amount An amount, or null.
		 * @return {string} The amount or the phrase.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		moneyOrAbsent(amount) {
			if (amount === null || amount === undefined) {
				return this.t('pipelinq', 'Not recorded')
			}
			return this.money(amount)
		},

		/**
		 * A count, or the words that say nobody measured one.
		 *
		 * @param {number|null} count A count, or null.
		 * @return {string} The count or the phrase.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		countOrAbsent(count) {
			if (count === null || count === undefined) {
				return this.t('pipelinq', 'Not recorded')
			}
			return String(count)
		},

		/**
		 * @param {number} ratio A ratio between 0 and 1.
		 * @return {string} A percentage with one decimal.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		percent(ratio) {
			return `${(Number(ratio || 0) * 100).toFixed(1)}%`
		},
	},
}
</script>

<style scoped lang="scss">
.campaign-report {
	padding: 20px;
}

.campaign-report__header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.campaign-report__pickers {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
}

.campaign-report__picker {
	min-width: 220px;
}

.campaign-report__meta {
	color: var(--color-text-maxcontrast);
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.campaign-report__tiles {
	display: flex;
	gap: 24px;
	flex-wrap: wrap;
	list-style: none;
	padding: 0;
	margin: 0 0 24px;

	li {
		display: flex;
		flex-direction: column;
		min-width: 140px;
		padding: 12px 16px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		color: var(--color-text-maxcontrast);
	}
}

.campaign-report__tile-value {
	font-size: 1.5rem;
	font-weight: bold;
	color: var(--color-main-text);
}

.campaign-report__scroll {
	overflow-x: auto;
}

.campaign-report__table {
	width: 100%;
	border-collapse: collapse;
	margin-bottom: 24px;

	th,
	td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
	}

	th {
		font-weight: bold;
	}
}

.campaign-report__num {
	text-align: end;
}

.campaign-report__basis {
	font-weight: bold;
}

.campaign-report__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

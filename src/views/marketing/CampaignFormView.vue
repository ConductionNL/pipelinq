<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Campaign create and edit (marketing-campaigns). One component serves both
  routes; edit mode is the :id param, matching the SegmentNew / SegmentEdit
  convention.

  🔴 IT IS NOT A DECLARATIVE FORM, AND THAT IS THE WHOLE POINT. A campaign
  written through OpenRegister's own create dialog carries whatever
  `utmCampaign` the browser sent and stores a source outside the tenant's
  vocabulary without complaint. Minting the campaign value once, freezing it
  across a rename, and refusing an unknown source or medium all live in
  CampaignService, which only POST and PATCH /api/campaigns reach.

  @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
-->
<template>
	<div class="campaign-form">
		<header class="campaign-form__header">
			<NcButton variant="tertiary" @click="back">
				{{ t('pipelinq', 'Back to campaigns') }}
			</NcButton>
			<h2>{{ heading }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<form v-else class="campaign-form__body" @submit.prevent="save">
			<NcTextField
				v-model="form.name"
				data-testid="campaign-form-name"
				:label="t('pipelinq', 'Name')"
				:required="true" />

			<NcTextField
				v-model="form.goal"
				:label="t('pipelinq', 'Goal')"
				:placeholder="
					t(
						'pipelinq',
						'What this campaign should achieve, in one sentence',
					)
				" />

			<p v-if="form.utmCampaign" class="campaign-form__minted">
				{{
					t(
						'pipelinq',
						'Campaign value: {value}. It was minted from the name and does not change when you rename the campaign.',
						{ value: form.utmCampaign },
					)
				}}
			</p>

			<NcSelect
				v-model="source"
				data-testid="campaign-form-source"
				:options="sources"
				:inputLabel="t('pipelinq', 'Source')" />

			<NcSelect
				v-model="medium"
				data-testid="campaign-form-medium"
				:options="mediums"
				:inputLabel="t('pipelinq', 'Medium')" />

			<NcSelect
				v-model="status"
				:options="statuses"
				:clearable="false"
				:inputLabel="t('pipelinq', 'Status')" />

			<div class="campaign-form__dates">
				<NcDateTimePickerNative
					v-model="startsAt"
					type="date"
					:label="t('pipelinq', 'Starts at')" />
				<NcDateTimePickerNative
					v-model="endsAt"
					type="date"
					:label="t('pipelinq', 'Ends at')" />
			</div>

			<NcTextField
				v-model="budget"
				type="number"
				:label="t('pipelinq', 'Budget in euro')" />

			<NcTextArea
				v-model="form.articleSummary"
				:label="t('pipelinq', 'Page summary')"
				:helperText="
					t(
						'pipelinq',
						'The landing page opens with this. Portaliq refuses a page without it.',
					)
				" />

			<NcTextArea
				v-model="form.articleBody"
				:label="t('pipelinq', 'Page body')"
				:helperText="
					t('pipelinq', 'Markdown. Headings, lists and links all work.')
				" />

			<div class="campaign-form__actions">
				<NcButton
					variant="primary"
					data-testid="campaign-form-save"
					:disabled="saving || !form.name"
					@click="save">
					{{ t('pipelinq', 'Save') }}
				</NcButton>
			</div>
		</form>

		<p v-if="error" class="campaign-form__error" role="alert">{{ error }}</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDateTimePickerNative,
	NcLoadingIcon,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	fetchCampaignVocabularies,
	saveCampaign,
} from '../../services/campaignsApi.js'

export default {
	name: 'CampaignFormView',
	components: {
		NcButton,
		NcDateTimePickerNative,
		NcLoadingIcon,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	data() {
		return {
			loading: true,
			saving: false,
			error: '',
			sources: [],
			mediums: [],
			source: null,
			medium: null,
			status: 'planned',
			startsAt: null,
			endsAt: null,
			budget: '',
			form: {
				name: '',
				goal: '',
				utmCampaign: '',
				articleSummary: '',
				articleBody: '',
			},
		}
	},

	computed: {
		/**
		 * The campaign being edited, empty when creating one.
		 *
		 * @return {string} The id.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		campaignId() {
			return this.$route?.params?.id || ''
		},

		/**
		 * @return {string} What the page is called.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		heading() {
			return this.campaignId
				? this.t('pipelinq', 'Edit campaign')
				: this.t('pipelinq', 'New campaign')
		},

		/**
		 * @return {Array<string>} The statuses the schema declares.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		statuses() {
			return ['planned', 'running', 'finished', 'cancelled']
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the vocabularies, and the campaign when editing one.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		async load() {
			this.loading = true
			try {
				const vocabularies = await fetchCampaignVocabularies()
				this.sources = vocabularies.sources
				this.mediums = vocabularies.mediums
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load the campaign vocabulary.')
			}

			if (this.campaignId) {
				await this.fetchCampaign()
			}

			this.loading = false
		},

		/**
		 * Read the campaign being edited.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		async fetchCampaign() {
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/pipelinq/campaign/${this.campaignId}`,
					),
				)
				const row = data?.data || data || {}
				this.form.name = row.name || ''
				this.form.goal = row.goal || ''
				this.form.utmCampaign = row.utmCampaign || ''
				this.form.articleSummary = row.articleSummary || ''
				this.form.articleBody = row.articleBody || ''
				this.source = row.utmSource || null
				this.medium = row.utmMedium || null
				this.status = row.status || 'planned'
				this.startsAt = row.startsAt ? new Date(row.startsAt) : null
				this.endsAt = row.endsAt ? new Date(row.endsAt) : null
				this.budget =
					row.budgetEur === undefined ? '' : String(row.budgetEur)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load this campaign.')
			}
		},

		/**
		 * Save through Pipelinq, and show the reason when it refuses.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		async save() {
			this.saving = true
			this.error = ''
			const payload = {
				name: this.form.name,
				goal: this.form.goal,
				status: this.status,
				utmSource: this.source || '',
				utmMedium: this.medium || '',
				startsAt: this.isoDay(this.startsAt),
				endsAt: this.isoDay(this.endsAt),
				articleSummary: this.form.articleSummary,
				articleBody: this.form.articleBody,
			}
			if (this.budget !== '') {
				payload.budgetEur = Number(this.budget)
			}

			try {
				const result = await saveCampaign(payload, this.campaignId)
				if (result?.error) {
					this.error = this.explain(result)
					return
				}
				const id =
					result.campaign?.id
					|| result.campaign?.['@self']?.id
					|| result.campaign?.uuid
				this.$router.push({ name: 'CampaignDetail', params: { id } })
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not save the campaign.')
			} finally {
				this.saving = false
			}
		},

		/**
		 * What a refusal means, naming the value and the list it must come
		 * from. A vague "could not save" would leave the marketer guessing
		 * which of two pickers was wrong.
		 *
		 * @param {object} result The refusal.
		 * @return {string} The sentence.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		explain(result) {
			const allowed = (result.allowed || []).join(', ')
			if (result.error === 'unknown_utm_source') {
				return this.t(
					'pipelinq',
					'{value} is not one of the allowed sources: {allowed}',
					{ value: result.value, allowed },
				)
			}
			if (result.error === 'unknown_utm_medium') {
				return this.t(
					'pipelinq',
					'{value} is not one of the allowed mediums: {allowed}',
					{ value: result.value, allowed },
				)
			}
			if (result.error === 'name_required') {
				return this.t('pipelinq', 'A campaign needs a name.')
			}
			return this.t('pipelinq', 'Could not save the campaign.')
		},

		/**
		 * @param {Date|null} date A date, or null.
		 * @return {string} YYYY-MM-DD, or an empty string.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		isoDay(date) {
			if (!date) {
				return ''
			}
			return new Date(date).toISOString().slice(0, 10)
		},

		/**
		 * Back to the list.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		back() {
			this.$router.push({ name: 'Campaigns' })
		},
	},
}
</script>

<style scoped lang="scss">
.campaign-form {
	padding: 20px;
	max-width: 720px;
}

.campaign-form__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.campaign-form__body {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.campaign-form__minted {
	color: var(--color-text-maxcontrast);
}

.campaign-form__dates {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.campaign-form__actions {
	display: flex;
	gap: 12px;
}

.campaign-form__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

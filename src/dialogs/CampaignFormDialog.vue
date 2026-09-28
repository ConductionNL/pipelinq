<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  - Campaign create / edit, mounted into the `form-dialog` slot of both the
  - Campaigns index page and the CampaignDetail page. It saves through
  - POST / PATCH /api/campaigns rather than the slot's `confirm`, because only
  - CampaignService mints the campaign value, freezes it across a rename and
  - refuses a source or medium outside the tenant's vocabulary.
  -
  - @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
  -->
<template>
	<NcDialog
		v-if="show"
		:name="isEditing ? t('pipelinq', 'Edit campaign') : t('pipelinq', 'New campaign')"
		:open="true"
		size="normal"
		:closeOnClickOutside="false"
		@closing="close()">
		<div class="campaign-form">
			<NcLoadingIcon v-if="loading" :size="32" class="campaign-form__loading" />

			<template v-else>
				<NcTextField
					v-model="form.name"
					data-testid="campaign-form-name"
					:label="t('pipelinq', 'Name')"
					:required="true" />

				<NcTextField
					v-model="form.goal"
					:label="t('pipelinq', 'Goal')"
					:placeholder="t('pipelinq', 'What this campaign should achieve, in one sentence')" />

				<p v-if="form.utmCampaign" class="campaign-form__minted">
					{{
						t(
							'pipelinq',
							'Campaign value: {value}. It was minted from the name and does not change when you rename the campaign.',
							{ value: form.utmCampaign },
						)
					}}
				</p>

				<div class="campaign-form__grid">
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

					<NcTextField
						v-model="budget"
						type="number"
						:label="t('pipelinq', 'Budget in euro')" />

					<NcDateTimePickerNative
						v-model="startsAt"
						type="date"
						:label="t('pipelinq', 'Starts at')" />

					<NcDateTimePickerNative
						v-model="endsAt"
						type="date"
						:label="t('pipelinq', 'Ends at')" />
				</div>

				<NcTextArea
					v-model="form.articleSummary"
					:label="t('pipelinq', 'Page summary')"
					:helperText="t('pipelinq', 'The landing page opens with this. Portaliq refuses a page without it.')" />

				<NcTextArea
					v-model="form.articleBody"
					:label="t('pipelinq', 'Page body')"
					:helperText="t('pipelinq', 'Markdown. Headings, lists and links all work.')" />

				<NcNoteCard v-if="error" type="error" class="campaign-form__note">
					{{ error }}
				</NcNoteCard>
			</template>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="close()">
				{{ t('pipelinq', 'Cancel') }}
			</NcButton>
			<NcButton
				variant="primary"
				data-testid="campaign-form-save"
				:disabled="saving || loading || !form.name"
				@click="save">
				<template v-if="saving" #icon>
					<NcLoadingIcon :size="20" />
				</template>
				{{ t('pipelinq', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { emit } from '@nextcloud/event-bus'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDateTimePickerNative,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextArea,
	NcTextField,
} from '@nextcloud/vue'
import {
	fetchCampaignVocabularies,
	saveCampaign,
} from '../services/campaignsApi.js'

/**
 * @return {object} A blank campaign form.
 */
function blankForm() {
	return {
		name: '',
		goal: '',
		utmCampaign: '',
		articleSummary: '',
		articleBody: '',
	}
}

export default {
	name: 'CampaignFormDialog',
	components: {
		NcButton,
		NcDateTimePickerNative,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	inheritAttrs: false,

	props: {
		/** Whether the host page has the dialog open. */
		show: {
			type: Boolean,
			default: false,
		},

		/** The campaign being edited, or null to create one. */
		item: {
			type: Object,
			default: null,
		},

		/** Closes the dialog. */
		close: {
			type: Function,
			default: () => {},
		},

		/** Re-reads the index page's list; the detail page does not pass it. */
		refresh: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			loading: false,
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
			form: blankForm(),
		}
	},

	computed: {
		/**
		 * @return {string} The id of the campaign being edited, empty when creating one.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		campaignId() {
			const item = this.item || {}
			return String(item.id || item['@self']?.id || item.uuid || '')
		},

		/** @return {boolean} Whether an existing campaign is being edited. */
		isEditing() {
			return this.campaignId !== ''
		},

		/**
		 * @return {Array<string>} The statuses the schema declares.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		statuses() {
			return ['planned', 'running', 'finished', 'cancelled']
		},
	},

	watch: {
		show: {
			immediate: true,
			handler(open) {
				if (open) {
					this.load()
				}
			},
		},
	},

	methods: {
		/**
		 * Reset the form, then read the vocabularies, and the campaign when editing one.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
		 */
		async load() {
			this.form = blankForm()
			this.source = null
			this.medium = null
			this.status = 'planned'
			this.startsAt = null
			this.endsAt = null
			this.budget = ''
			this.error = ''
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

			if (this.isEditing) {
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
		 * Save through Pipelinq, and show the reason when it refuses. A new
		 * campaign opens on its detail page; an edited one re-reads the page
		 * the dialog was opened from.
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
				if (this.isEditing) {
					if (this.refresh) {
						this.refresh()
					} else {
						emit('cn:page:refresh', {})
					}
					this.close()
					return
				}
				const id =
					result.campaign?.id
					|| result.campaign?.['@self']?.id
					|| result.campaign?.uuid
				this.close()
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
	},
}
</script>

<style scoped>
.campaign-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-bottom: 8px;
}

.campaign-form__loading {
	margin: 32px auto;
}

.campaign-form__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	align-items: end;
	gap: 12px 16px;
}

.campaign-form__grid :deep(.v-select.select) {
	width: 100%;
	margin: 0;
}

.campaign-form__minted {
	margin: 0;
	color: var(--color-text-maxcontrast);
}

.campaign-form__note {
	margin: 0;
}
</style>

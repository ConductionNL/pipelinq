<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Landing page section on the campaign detail page (marketing-campaigns).
  Shows the page portaliq created for this campaign, or the action that
  asks for one, and links through to the campaign's report.

  🔴 PORTALIQ'S ERROR CODE IS SHOWN AS PORTALIQ SENT IT. Each of the five
  is fixed somewhere else: a duplicate route is a route to change here, an
  invalid form is a form definition to change in the code, an unknown
  portal is a setting. Collapsing them into "could not create the page"
  would take away the only thing that says where to go.

  @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
-->
<template>
	<section class="campaign-landing">
		<NcLoadingIcon v-if="loading" :size="24" />

		<div v-else-if="page" data-testid="campaign-landing-page">
			<p class="campaign-landing__subtitle">
				{{ t('pipelinq', 'Published by portaliq with a sign-up form') }}
			</p>
			<dl class="campaign-landing__facts">
				<dt>{{ t('pipelinq', 'Portal') }}</dt>
				<dd>{{ page.portal }}</dd>
				<dt>{{ t('pipelinq', 'Address') }}</dt>
				<dd>
					<a
						v-if="page.publicUrl"
						:href="page.publicUrl"
						target="_blank"
						rel="noopener noreferrer">
						{{ page.publicUrl }}
					</a>
					<span v-else class="campaign-landing__hint">
						{{
							t(
								'pipelinq',
								'The portal has no domain configured yet, so the page has no public address.',
							)
						}}
					</span>
				</dd>
				<template v-if="page.form">
					<dt>{{ t('pipelinq', 'Form') }}</dt>
					<dd>{{ page.form }}</dd>
				</template>
			</dl>
			<NcNoteCard type="success">
				{{
					t(
						'pipelinq',
						'Everyone who submits this form becomes a lead in this campaign.',
					)
				}}
			</NcNoteCard>
			<NcTextField
				:modelValue="page.route"
				class="campaign-landing__field"
				:label="t('pipelinq', 'Route on the portal')"
				:readonly="true" />
		</div>

		<div v-else data-testid="campaign-landing-empty">
			<p class="campaign-landing__hint">
				{{
					t(
						'pipelinq',
						'This campaign has no landing page yet. Pipelinq asks portaliq to publish one with a sign-up form.',
					)
				}}
			</p>
			<NcTextField
				v-model="route"
				class="campaign-landing__field"
				:label="t('pipelinq', 'Route on the portal')"
				:placeholder="routePlaceholder" />
			<NcButton
				variant="primary"
				data-testid="campaign-landing-create"
				:disabled="creating"
				@click="create">
				{{ t('pipelinq', 'Create landing page') }}
			</NcButton>
		</div>

		<p v-if="failure" class="campaign-landing__error" role="alert">
			{{ failure }}
		</p>

		<NcButton
			class="campaign-landing__report cn-button--tonal"
			variant="secondary"
			:href="reportHref">
			<template #icon>
				<ChartBox :size="16" />
			</template>
			{{ t('pipelinq', 'Open campaign report') }}
		</NcButton>
	</section>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon, NcNoteCard, NcTextField } from '@nextcloud/vue'
import ChartBox from 'vue-material-design-icons/ChartBox.vue'
import { createCampaignLandingPage } from '../../services/campaignsApi.js'

export default {
	name: 'CampaignLandingPageSection',
	components: {
		ChartBox,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		/** The campaign this section belongs to, from the `@objectId` token. */
		campaignId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: true,
			creating: false,
			failure: '',
			campaign: null,
			route: '',
		}
	},

	computed: {
		/**
		 * The landing page this campaign already has, or null.
		 *
		 * @return {object|null} The stored block.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
		 */
		page() {
			const block = this.campaign?.landingPage
			if (!block || !block.route) {
				return null
			}
			return block
		},

		/**
		 * The route the campaign would get if nobody types one.
		 *
		 * @return {string} The suggested route.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
		 */
		routePlaceholder() {
			return `/campagne/${this.campaign?.utmCampaign || ''}`
		},

		/**
		 * Where the campaign report opens. A path, never a hash: the app is
		 * path-routed.
		 *
		 * @return {string} The href.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-one-campaign-report-page
		 */
		reportHref() {
			return generateUrl(
				`/apps/pipelinq/reports/campaign?campaign=${encodeURIComponent(this.campaignId)}`,
			)
		},
	},

	mounted() {
		this.fetchCampaign()
	},

	methods: {
		/**
		 * Read the campaign this section is bound to.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
		 */
		async fetchCampaign() {
			this.loading = true
			try {
				const { data } = await axios.get(
					generateUrl(
						`/apps/openregister/api/objects/pipelinq/campaign/${this.campaignId}`,
					),
				)
				this.campaign = data?.data || data || null
			} catch (e) {
				this.campaign = null
				this.failure =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load this campaign.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Ask portaliq for the page, and show its answer as it came.
		 *
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
		 */
		async create() {
			this.creating = true
			this.failure = ''
			try {
				const result = await createCampaignLandingPage(this.campaignId, {
					route: this.route,
				})
				if (result?.error) {
					this.failure = this.explain(result.error)
					return
				}
				await this.fetchCampaign()
			} catch (e) {
				this.failure =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not reach portaliq.')
			} finally {
				this.creating = false
			}
		},

		/**
		 * What each of portaliq's codes means, and what to do about it.
		 * The code is named as well as explained, so a support question can
		 * quote it.
		 *
		 * @param {string} code The code portaliq answered with.
		 * @return {string} The sentence the marketer reads.
		 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-creates-its-landing-page-in-portaliq
		 */
		explain(code) {
			const sentences = {
				unknown_portal: this.t(
					'pipelinq',
					'That portal does not exist or is not published. Check the portal in the marketing settings.',
				),

				duplicate_route: this.t(
					'pipelinq',
					'Another page on this portal already uses that route. Pick a different one.',
				),

				invalid_article: this.t(
					'pipelinq',
					'The campaign needs a page summary and a page body before portaliq can publish it.',
				),

				invalid_form: this.t(
					'pipelinq',
					'Portaliq refused the sign-up form. This is a defect, not something you can fix here.',
				),

				write_failed: this.t(
					'pipelinq',
					'Portaliq could not save the page. Nothing was created, so you can try again.',
				),

				portaliq_missing: this.t(
					'pipelinq',
					'Portaliq is not installed on this instance, so there is nowhere to publish the page.',
				),

				not_found: this.t('pipelinq', 'This campaign no longer exists.'),
			}
			const sentence =
				sentences[code]
				|| this.t('pipelinq', 'Portaliq refused the request.')
			return `${sentence} (${code})`
		},
	},
}
</script>

<style scoped lang="scss">
.campaign-landing {
	padding: 8px 0;
}

.campaign-landing__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: -8px 0 12px;
}

.campaign-landing__facts {
	padding: 0;
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 6px 16px;
	margin: 0 0 12px;

	dt,
	dd {
		margin: 0;
		padding: 0;
		text-align: start;
	}

	dt {
		color: var(--color-text-maxcontrast);
	}
}

.campaign-landing__hint {
	color: var(--color-text-maxcontrast);
	margin-bottom: 8px;
}

.campaign-landing__field {
	margin-bottom: 12px;
}

.campaign-landing__error {
	color: var(--color-error);
	margin-top: 12px;
}

.campaign-landing__report {
	margin-top: 16px;
}
</style>

<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  In-body section for the declarative type:"detail" JourneyDetail page
  (marketing-integrated-campaigns, placement end). It answers the question a
  journey cannot answer any other way: who did this reach, and who did it
  refuse.

  🔴 A REFUSAL IS THE POINT OF THIS SECTION. A journey that sends to nobody
  because nobody consented looks exactly like a journey with a small
  audience, and the difference is only visible here. The declarative
  object-list widget cannot express it: the refusal reason has to be read as
  words, not as a raw enum value.

  @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
-->
<template>
	<section class="journey-runs" data-testid="journey-runs-section">
		<h3>{{ t('pipelinq', 'What this journey did') }}</h3>

		<NcLoadingIcon v-if="loading" :size="24" />

		<NcEmptyContent
			v-else-if="runs.length === 0"
			data-testid="journey-runs-empty"
			:name="t('pipelinq', 'This journey has not run yet')"
			:description="
				t(
					'pipelinq',
					'Once its trigger fires, every contact it reached and every contact it refused is listed here.',
				)
			">
			<template #icon>
				<MapMarkerPath :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<p class="journey-runs__counts" data-testid="journey-runs-counts">
				{{
					t(
						'pipelinq',
						'{sent} sent, {refused} refused, {failed} failed',
						{
							sent: counts.sent,
							refused: counts.refused,
							failed: counts.failed,
						},
					)
				}}
			</p>

			<ul class="journey-runs__list">
				<li
					v-for="run in runs"
					:key="run.id || run['@self']?.uuid || run.contactId"
					class="journey-runs__row"
					data-testid="journey-runs-row">
					<span class="journey-runs__state">{{
						stateLabel(run.state)
					}}</span>
					<span class="journey-runs__contact">{{ run.contactId }}</span>
					<span v-if="run.reason" class="journey-runs__reason">
						{{ reasonLabel(run.reason) }}
					</span>
					<span class="journey-runs__when">{{ run.occurredAt }}</span>
				</li>
			</ul>
		</template>

		<p v-if="error" class="journey-runs__error" role="alert">{{ error }}</p>
	</section>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import {
	runCounts,
	runReasonLabel,
	runStateLabel,
} from '../../services/journeyLabels.js'
import { fetchJourneyRuns } from '../../services/journeysApi.js'

export default {
	name: 'JourneyRunsSection',
	components: {
		MapMarkerPath,
		NcEmptyContent,
		NcLoadingIcon,
	},

	props: {
		/** The journey whose runs are listed. */
		journeyId: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			runs: [],
		}
	},

	computed: {
		/**
		 * How many runs ended each way.
		 *
		 * @return {object} `{sent, refused, failed}`.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
		 */
		counts() {
			return runCounts(this.runs)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read this journey's runs.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
		 */
		async load() {
			if (!this.journeyId) {
				this.loading = false
				return
			}
			try {
				this.runs = await fetchJourneyRuns(this.journeyId)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not read what this journey did.')
			}
			this.loading = false
		},

		/**
		 * The state as a word rather than an enum value.
		 *
		 * @param {string} state The stored state.
		 * @return {string} The label.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
		 */
		stateLabel(state) {
			return runStateLabel(state, this.t)
		},

		/**
		 * The refusal reason in words, so nobody has to look the code up.
		 *
		 * @param {string} reason The stored reason.
		 * @return {string} The label.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
		 */
		reasonLabel(reason) {
			return runReasonLabel(reason, this.t)
		},
	},
}
</script>

<style scoped>
.journey-runs {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 1rem 0;
}

.journey-runs__counts {
	color: var(--color-text-maxcontrast);
}

.journey-runs__list {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.journey-runs__row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.75rem;
	padding: 0.35rem 0;
	border-bottom: 1px solid var(--color-border);
}

.journey-runs__state {
	font-weight: 600;
	min-width: 7rem;
}

.journey-runs__reason {
	color: var(--color-text-maxcontrast);
}

.journey-runs__when {
	margin-inline-start: auto;
	color: var(--color-text-maxcontrast);
}

.journey-runs__error {
	color: var(--color-error-text, var(--color-error));
}
</style>

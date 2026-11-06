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
		<p v-if="showSubtitle" class="journey-runs__subtitle">
			{{ t('pipelinq', 'Every contact reached and every contact refused') }}
		</p>
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

			<div class="journey-runs__frame">
				<table class="journey-runs__table">
					<thead>
						<tr>
							<th scope="col">{{ t('pipelinq', 'Reached contact') }}</th>
							<th scope="col">{{ t('pipelinq', 'When') }}</th>
							<th scope="col">{{ t('pipelinq', 'Outcome') }}</th>
							<th scope="col">{{ t('pipelinq', 'Why') }}</th>
						</tr>
					</thead>
					<tbody>
						<tr
							v-for="run in runs"
							:key="run.id || run['@self']?.uuid || run.contactId"
							data-testid="journey-runs-row">
							<td>{{ run.contactName || run.contactId }}</td>
							<td>{{ when(run.occurredAt) }}</td>
							<td>
								<CnStatusBadge
									:label="stateLabel(run.state)"
									:variant="stateVariant(run.state)"
									size="small" />
							</td>
							<td>{{ run.reason ? reasonLabel(run.reason) : '' }}</td>
						</tr>
					</tbody>
				</table>
			</div>
		</template>

		<p v-if="error" class="journey-runs__error" role="alert">{{ error }}</p>
	</section>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
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
		CnStatusBadge,
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

		/** Whether the section draws its own line; a titled card around it draws it instead. */
		showSubtitle: {
			type: Boolean,
			default: true,
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

		/**
		 * The tone of the outcome pill.
		 *
		 * @param {string} state The stored state.
		 * @return {string} A CnStatusBadge variant.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
		 */
		stateVariant(state) {
			return { sent: 'success', 'task-created': 'success', refused: 'warning', failed: 'error' }[state] || 'default'
		},

		/**
		 * The moment of a run in the short form the lists use ("6 okt, 09:00").
		 *
		 * @param {string} value An ISO date-time.
		 * @return {string} The moment, or the value as stored when it is not a date.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-a-journey-refuses-a-send-without-consent-and-names-the-contact
		 */
		when(value) {
			const date = new Date(value)
			if (!value || Number.isNaN(date.getTime())) {
				return value || ''
			}
			return date.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
		},
	},
}
</script>

<style scoped lang="scss">
.journey-runs {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
}

.journey-runs__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin: -8px 0 4px;
}

.journey-runs__counts {
	font-weight: 600;
}

.journey-runs__frame {
	border: 1px solid var(--color-border);
	border-radius: 8px;
	background: var(--color-main-background);
	overflow-x: auto;
}

.journey-runs .journey-runs__frame .journey-runs__table {
	width: 100%;
	border-collapse: collapse;
	line-height: 1.2;

	th,
	td {
		padding: 12px;
		text-align: start;
		line-height: 1.2;
		background: var(--color-main-background);
		border-bottom: 1px solid var(--color-border);
	}

	th {
		--nldesign-component-table-header-background-color: var(--color-main-background);
		--nldesign-component-table-header-color: var(--color-text-maxcontrast);
		padding-block: 10px;
		font-weight: 600;
		color: var(--color-text-maxcontrast);
	}

	th:first-child,
	td:first-child {
		padding-inline-start: 18px;
	}

	th:last-child,
	td:last-child {
		padding-inline-end: 18px;
	}

	tbody tr:last-child td {
		border-bottom: none;
	}
}

.journey-runs__error {
	color: var(--color-error-text, var(--color-error));
}
</style>

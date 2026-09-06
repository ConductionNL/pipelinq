<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  The weekly marketing review (marketing-integrated-campaigns, ADR-112): what
  moved last week, what to try, and three topic ideas.

  🔴 ONE FETCH. GET /api/weekly-review returns the whole record and this page
  paints from it. pipelinq#1781 fixed a page that asked the server once per
  object before it rendered anything.

  🔴 A SOURCE THIS TENANT HOLDS NOTHING FOR IS SHOWN AS ABSENT, NEVER AS A
  ZERO. A quiet week and an unconnected Search Console both render as no
  line; the review names which is which, and so does this page.

  @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
-->
<template>
	<div class="weekly-review">
		<header class="weekly-review__header">
			<h2>{{ t('pipelinq', 'Weekly review') }}</h2>
			<NcButton
				variant="secondary"
				data-testid="weekly-review-refresh"
				:disabled="loading"
				@click="regenerate">
				{{ t('pipelinq', 'Compose again') }}
			</NcButton>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="!review"
			data-testid="weekly-review-empty"
			:name="t('pipelinq', 'No review yet')"
			:description="
				t(
					'pipelinq',
					'Send a mailing, publish a post or connect Search Console, and last week\'s numbers appear here.',
				)
			">
			<template #icon>
				<CalendarWeek :size="20" />
			</template>
		</NcEmptyContent>

		<section v-else data-testid="weekly-review-body">
			<p class="weekly-review__week">
				{{ t('pipelinq', 'Week of {week}', { week: review.weekStarting }) }}
			</p>

			<p
				v-if="agentAuthored"
				class="weekly-review__agent"
				data-testid="weekly-review-agent-mark">
				{{
					t('pipelinq', 'An agent wrote this summary: {agent}', {
						agent: review.agentAuthoredBy,
					})
				}}
			</p>

			<p class="weekly-review__summary">{{ review.summary }}</p>

			<h3>{{ t('pipelinq', 'What moved') }}</h3>
			<ul data-testid="weekly-review-highlights">
				<li v-for="(line, index) in review.highlights" :key="`h${index}`">
					{{ line }}
				</li>
			</ul>

			<h3>{{ t('pipelinq', 'What to try') }}</h3>
			<ul data-testid="weekly-review-suggestions">
				<li v-for="(line, index) in review.suggestions" :key="`s${index}`">
					{{ line }}
				</li>
			</ul>

			<h3>{{ t('pipelinq', 'Topic ideas') }}</h3>
			<ul data-testid="weekly-review-ideas">
				<li v-for="(line, index) in review.topicIdeas" :key="`i${index}`">
					{{ line }}
				</li>
			</ul>

			<p
				v-if="degraded.length > 0"
				class="weekly-review__degraded"
				data-testid="weekly-review-degraded">
				{{
					t(
						'pipelinq',
						'These sources hold nothing yet, so their absence here is missing data and not a zero: {sources}',
						{ sources: degraded.join(', ') },
					)
				}}
			</p>
		</section>

		<p v-if="error" class="weekly-review__error" role="alert">{{ error }}</p>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import CalendarWeek from 'vue-material-design-icons/CalendarWeek.vue'
import { degradedSources, isAgentAuthored } from '../../services/journeyLabels.js'
import {
	fetchWeeklyReview,
	generateWeeklyReview,
} from '../../services/journeysApi.js'

export default {
	name: 'WeeklyReviewView',
	components: {
		CalendarWeek,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
	},

	data() {
		return {
			loading: true,
			error: '',
			review: null,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether an agent wrote the summary (ADR-088).
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-narrative-mark-has-storage-and-a-renderer-and-no-writer-yet
		 */
		agentAuthored() {
			return isAgentAuthored(this.review)
		},

		/**
		 * @return {Array<string>} The sources this instance could not read.
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
		 */
		degraded() {
			return degradedSources(this.review)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the review, in one request.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
		 */
		async load() {
			this.loading = true
			try {
				this.review = await fetchWeeklyReview()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not read the weekly review.')
			}
			this.loading = false
		},

		/**
		 * Compose last week's numbers again.
		 *
		 * @spec openspec/changes/marketing-integrated-campaigns/specs/marketing-integrated-campaigns/spec.md#requirement-the-weekly-review-reads-four-sources-and-names-the-ones-with-nothing-in-them
		 */
		async regenerate() {
			this.loading = true
			this.error = ''
			try {
				this.review = await generateWeeklyReview()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not compose the weekly review.')
			}
			this.loading = false
		},
	},
}
</script>

<style scoped>
.weekly-review {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	padding: 1rem;
	max-width: 46rem;
}

.weekly-review__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
}

.weekly-review__week,
.weekly-review__agent,
.weekly-review__degraded {
	color: var(--color-text-maxcontrast);
}

.weekly-review__summary {
	margin-block: 0.5rem;
}

.weekly-review__error {
	color: var(--color-error-text, var(--color-error));
}
</style>

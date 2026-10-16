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
			<CnPageHeader
				:title="t('pipelinq', 'Weekly review')"
				:description="subtitle" />
			<NcButton
				variant="secondary"
				data-testid="weekly-review-refresh"
				:disabled="loading"
				@click="regenerate">
				<template #icon>
					<Refresh :size="20" />
				</template>
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

		<div v-else class="weekly-review__body" data-testid="weekly-review-body">
			<section class="weekly-review__card">
				<h3 class="weekly-review__week">
					{{ t('pipelinq', 'Week of {week}', { week: review.weekStarting }) }}
				</h3>
				<p class="weekly-review__summary">{{ review.summary }}</p>
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
			</section>

			<NcNoteCard
				v-if="degraded.length > 0"
				type="warning"
				data-testid="weekly-review-degraded">
				{{
					t(
						'pipelinq',
						'These sources hold nothing yet, so their absence here is missing data and not a zero: {sources}',
						{ sources: degraded.join(', ') },
					)
				}}
			</NcNoteCard>

			<div class="weekly-review__pair">
				<section class="weekly-review__card">
					<h3>{{ t('pipelinq', 'What moved') }}</h3>
					<ul class="weekly-review__lines" data-testid="weekly-review-highlights">
						<li v-for="(line, index) in review.highlights" :key="`h${index}`">
							{{ line }}
						</li>
					</ul>
				</section>
				<section class="weekly-review__card">
					<h3>{{ t('pipelinq', 'What to try') }}</h3>
					<ul class="weekly-review__lines" data-testid="weekly-review-suggestions">
						<li v-for="(line, index) in review.suggestions" :key="`s${index}`">
							{{ line }}
						</li>
					</ul>
				</section>
			</div>

			<section class="weekly-review__card">
				<h3>{{ t('pipelinq', 'Ideas for topics') }}</h3>
				<ul class="weekly-review__ideas" data-testid="weekly-review-ideas">
					<li v-for="(line, index) in review.topicIdeas" :key="`i${index}`">
						<CnStatusBadge :label="line" variant="info" size="small" />
					</li>
				</ul>
			</section>
		</div>

		<p v-if="error" class="weekly-review__error" role="alert">{{ error }}</p>
	</div>
</template>

<script>
import { CnPageHeader, CnStatusBadge } from '@conduction/nextcloud-vue'
import { getCanonicalLocale } from '@nextcloud/l10n'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import CalendarWeek from 'vue-material-design-icons/CalendarWeek.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import { degradedSources, isAgentAuthored } from '../../services/journeyLabels.js'
import {
	fetchWeeklyReview,
	generateWeeklyReview,
} from '../../services/journeysApi.js'

export default {
	name: 'WeeklyReviewView',
	components: {
		CalendarWeek,
		CnPageHeader,
		CnStatusBadge,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		Refresh,
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
		 * The line under the title: today's date and what the review covers.
		 *
		 * @return {string}
		 * @spec exclude presentational subtitle of the weekly review
		 */
		subtitle() {
			const today = new Intl.DateTimeFormat(getCanonicalLocale() || undefined, {
				weekday: 'long',
				day: 'numeric',
				month: 'long',
				year: 'numeric',
			}).format(new Date())
			return `${today} · ${this.t('pipelinq', 'newsletters, posts and search traffic of last week')}`
		},

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
	gap: 20px;
	padding: 24px 28px;
}

.weekly-review__header {
	display: flex;
	align-items: flex-start;
	justify-content: space-between;
	gap: 12px;
}

.weekly-review__body {
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.weekly-review__card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 20px 22px;
}

.weekly-review__card h3 {
	margin: 0 0 12px;
	font-size: 1rem;
	font-weight: 700;
}

.weekly-review__pair {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
	gap: 20px;
	align-items: start;
}

.weekly-review__summary {
	font-size: 1.05rem;
	line-height: 1.6;
	margin: 0 0 12px;
}

.weekly-review__agent {
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	margin: 0;
}

.weekly-review__lines {
	list-style: none;
	margin: 0;
	padding: 0;
}

.weekly-review__lines li {
	border-top: 1px solid var(--color-border);
	padding: 10px 0;
}

.weekly-review__ideas {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.weekly-review__error {
	color: var(--color-error-text, var(--color-error));
}
</style>

<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Competitors (marketing-search-intelligence, phase 5): what the watches saw,
  newest first, and what each watch last did.

  The page reads one endpoint. A watch that has never run and one that ran and
  found nothing look the same in a list of events, so the watches table shows
  each one's last outcome next to it: that is the difference between "they
  published nothing" and "we could not read it".

  Relevance is shown where hermiq scored it and as "not scored" where it did
  not. Rendering an unscored event as zero would sort it with the irrelevant
  ones and it would never be read.

  @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
-->
<template>
	<div class="competitors" :class="{ 'competitors--embedded': embedded }">
		<header v-if="!embedded" class="competitors__header">
			<h2>{{ t('pipelinq', 'Competitors') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<NcNoteCard
				v-if="!configured"
				type="warning"
				data-testid="competitors-unconfigured">
				{{ reason ? t('pipelinq', reason) : '' }}
				{{
					t(
						'pipelinq',
						'Set an egress source under Settings, Marketing intelligence to switch the watches on.',
					)
				}}
			</NcNoteCard>

			<NcEmptyContent
				v-if="!watches.length"
				class="competitors__empty"
				data-testid="competitors-empty"
				:name="t('pipelinq', 'No watches yet')"
				:description="
					t(
						'pipelinq',
						'Add a competitor and give it something to watch: a feed, a sitemap, a page fragment, a public fediverse timeline, or a saved search. LinkedIn and Meta are deliberately not options: neither offers a legitimate way to read another organisation\'s posts.',
					)
				">
				<template #icon>
					<AccountGroupOutline :size="20" />
				</template>
			</NcEmptyContent>

			<section
				v-else
				class="competitors__section"
				data-testid="competitors-watches">
				<h3 v-if="!embedded">{{ t('pipelinq', 'Watches') }}</h3>
				<CnDataTable
					class="competitors__table"
					data-testid="competitors-watches-table"
					framed
					filterable
					localHeaders
					:columns="watchColumns"
					:rows="watchRows"
					rowKey="rowKey">
					<template #column-kindText="{ row: watch }">
						<span class="competitors__kind">{{ watch.kindText }}</span>
						<span v-if="!embedded" class="competitors__target">{{
							watch.target
						}}</span>
					</template>
					<template #column-lastRunAt="{ row: watch }">
						{{
							watch.lastRunAt
								? formatWhen(watch.lastRunAt)
								: t('pipelinq', 'Never')
						}}
					</template>
					<template #column-outcomeText="{ row: watch }">
						<CnStatusBadge
							:variant="outcomeVariant(watch)"
							:label="outcomeLabel(watch)" />
						<span
							v-if="watch.lastReason"
							class="competitors__reason">
							{{ watch.lastReason }}
						</span>
					</template>
					<template #column-run="{ row: watch }">
						<NcButton
							variant="secondary"
							:disabled="running === idOf(watch)"
							@click="run(watch)">
							{{ t('pipelinq', 'Run now') }}
						</NcButton>
					</template>
				</CnDataTable>
			</section>

			<p
				v-if="embedded"
				class="competitors__changed"
				data-testid="competitors-changed">
				<span class="competitors__changed-label">{{ t('pipelinq', 'What changed') }}</span>
				<span>{{ latestChange }}</span>
			</p>

			<section
				v-else
				class="competitors__section"
				data-testid="competitors-events">
				<h3>{{ t('pipelinq', 'What changed') }}</h3>
				<p v-if="!events.length" class="competitors__none">
					{{
						t(
							'pipelinq',
							'Nothing has been seen yet. A watch records an item once, so a second run over unchanged sources adds nothing here.',
						)
					}}
				</p>
				<ul v-else class="competitors__events">
					<li v-for="event in events" :key="idOf(event)">
						<div class="competitors__event-head">
							<a
								:href="event.url"
								target="_blank"
								rel="noopener noreferrer"
								class="competitors__event-title">
								{{ event.title || event.url }}
							</a>
							<span class="competitors__relevance">
								{{ relevanceLabel(event) }}
							</span>
						</div>
						<p class="competitors__event-meta">
							<span>{{ competitorNameById(event.competitorId) }}</span>
							<span>{{ kindLabel(event.kind) }}</span>
							<span>{{ formatWhen(event.seenAt) }}</span>
						</p>
						<p
							v-if="event.diffSummary"
							class="competitors__event-summary">
							{{ event.diffSummary }}
						</p>
					</li>
				</ul>
			</section>
		</template>

		<p v-if="error" class="competitors__error" role="alert">{{ error }}</p>
	</div>
</template>

<script>
import { CnDataTable, CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import { formatDate } from '../../services/localeUtils.js'
import {
	enumLabel,
	WATCH_KIND_LABELS,
	WATCH_SCHEDULE_LABELS,
} from '../../utils/enumLabels.js'

export default {
	name: 'CompetitorWatches',
	components: {
		AccountGroupOutline,
		CnDataTable,
		CnStatusBadge,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		/** Drawn as a section of the search insight page: no own header (board PqZoekinzicht). */
		embedded: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			loading: true,
			error: '',
			configured: true,
			reason: '',
			competitors: [],
			watches: [],
			events: [],
			running: '',
		}
	},

	computed: {
		/**
		 * The watch columns: every data column sorts and filters the rows the
		 * section holds, as board PqZoekinzicht draws the heads; cells wrap.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/marketing/spec.md#requirement-the-search-insight-tables-are-framed-and-sort-and-filter-from-their-heads
		 */
		watchColumns() {
			const column = (key, label, extra = {}) => ({ key, label, sortable: true, cellClass: 'cn-cell--wrap', ...extra })
			return [
				column('competitorText', this.t('pipelinq', 'Competitor')),
				column('kindText', this.t('pipelinq', 'Watching')),
				column('scheduleText', this.t('pipelinq', 'How often')),
				column('lastRunAt', this.t('pipelinq', 'Last run'), { type: 'date-time' }),
				column('outcomeText', this.t('pipelinq', 'Outcome')),
				{ key: 'run', label: '', sortable: false, filterable: false },
			]
		},

		/**
		 * The watches with the words the table shows, so a head sorts and
		 * filters on what the reader sees.
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/marketing/spec.md#requirement-the-search-insight-tables-are-framed-and-sort-and-filter-from-their-heads
		 */
		watchRows() {
			return this.watches.map((watch) => ({
				...watch,
				rowKey: this.idOf(watch),
				competitorText: this.competitorName(watch),
				kindText: this.kindLabel(watch.kind),
				scheduleText: this.scheduleLabel(watch.schedule),
				outcomeText: this.outcomeLabel(watch),
			}))
		},

		/**
		 * The newest event in one sentence, as the search insight page sums
		 * up what changed.
		 *
		 * @return {string}
		 * @spec openspec/specs/marketing/spec.md
		 */
		latestChange() {
			const event = this.events[0]
			if (!event) {
				return this.t('pipelinq', 'Nothing has been seen yet.')
			}
			const params = {
				competitor: this.competitorNameById(event.competitorId),
				title: event.title || event.url,
				score: event.relevanceScore,
			}
			if (event.relevanceScore === undefined || event.relevanceScore === null || event.relevanceScore === '') {
				return this.t('pipelinq', '{competitor} published "{title}"', params)
			}
			return this.t('pipelinq', '{competitor} published "{title}", relevance {score}', params)
		},
	},

	mounted() {
		this.fetchAll()
	},

	methods: {
		/**
		 * GET /api/marketing/watch-events. One request carries the
		 * competitors, the watches and the events the page renders, so
		 * nothing fans out per watch before it paints (pipelinq#1781).
		 *
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		async fetchAll() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/marketing/watch-events')
				const { data } = await axios.get(url)
				this.showWatchEvents(data)
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load the competitor watches.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Show what the watch events endpoint answered, empty where it sent
		 * nothing.
		 *
		 * @param {object|null} data The answer.
		 */
		showWatchEvents(data) {
			this.configured = Boolean(data?.configured)
			this.reason = data?.reason || ''
			this.competitors = data?.competitors || []
			this.watches = data?.watches || []
			this.events = data?.events || []
		},

		/**
		 * Run one watch now. The scheduled run belongs to an OpenRegister
		 * flow; this is the "I just added it, does it work" path.
		 *
		 * @param {object} watch The watch.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
		 */
		async run(watch) {
			const id = this.idOf(watch)
			this.running = id
			this.error = ''
			try {
				const url = generateUrl(
					`/apps/pipelinq/api/marketing/competitor-watches/${id}/run`,
				)
				await axios.post(url, {})
				await this.fetchAll()
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'The watch could not be run.')
			} finally {
				this.running = ''
			}
		},

		/**
		 * @param {object} row An object from OpenRegister or the API.
		 * @return {string} Its id.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		idOf(row) {
			return String(row?.id || row?.['@self']?.id || row?.uuid || '')
		},

		/**
		 * @param {object} watch A watch.
		 * @return {string} The competitor's name.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		competitorName(watch) {
			return this.competitorNameById(watch?.competitorId)
		},

		/**
		 * @param {string} id A competitor id.
		 * @return {string} The name, or the id when it is unknown.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		competitorNameById(id) {
			const found = this.competitors.find(
				(competitor) => this.idOf(competitor) === String(id),
			)
			return String(found?.name || id || '')
		},

		/**
		 * @param {string} kind A stored watch kind.
		 * @return {string} Its label, or the value as stored when unknown.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		kindLabel(kind) {
			return enumLabel(WATCH_KIND_LABELS, kind, (text) =>
				this.t('pipelinq', text),
			)
		},

		/**
		 * @param {string} schedule A stored watch schedule.
		 * @return {string} Its label, or the value as stored when unknown.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		scheduleLabel(schedule) {
			return enumLabel(WATCH_SCHEDULE_LABELS, schedule, (text) =>
				this.t('pipelinq', text),
			)
		},

		/**
		 * @param {string} value An ISO timestamp.
		 * @return {string} Its date and time in the reader's locale, or the value as stored when unreadable.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		formatWhen(value) {
			if (!value || Number.isNaN(new Date(value).getTime())) {
				return value || ''
			}
			return formatDate(value, {
				day: 'numeric',
				month: 'short',
				year: 'numeric',
				hour: '2-digit',
				minute: '2-digit',
			})
		},

		/**
		 * What the last run did, in words rather than a code.
		 *
		 * @param {object} watch A watch.
		 * @return {string}
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		outcomeLabel(watch) {
			const labels = {
				ok: this.t('pipelinq', 'Read'),
				not_configured: this.t('pipelinq', 'Not configured'),
				unavailable: this.t('pipelinq', 'Could not reach it'),
				refused: this.t('pipelinq', 'Refused'),
				unparsable: this.t('pipelinq', 'Could not read it'),
			}
			return labels[watch?.lastOutcome] || this.t('pipelinq', 'Not run yet')
		},

		/**
		 * @param {object} watch A watch.
		 * @return {string} The status pill variant: success when read, error when it failed.
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		outcomeVariant(watch) {
			if (!watch?.lastOutcome) {
				return 'default'
			}
			return watch.lastOutcome === 'ok' ? 'success' : 'error'
		},

		/**
		 * The relevance of one event, or the fact that nothing scored it.
		 * An unscored event is never rendered as a zero.
		 *
		 * @param {object} event A watch event.
		 * @return {string}
		 * @spec openspec/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
		 */
		relevanceLabel(event) {
			if (
				event?.relevanceScore === undefined
				|| event?.relevanceScore === null
				|| event?.relevanceScore === ''
			) {
				return this.t('pipelinq', 'Not scored')
			}
			return this.t('pipelinq', 'Relevance {score}', {
				score: event.relevanceScore,
			})
		},
	},
}
</script>

<style scoped lang="scss">
.competitors {
	padding: 20px;
}

.competitors__header {
	margin-bottom: 16px;
}

.competitors__section {
	margin-bottom: 32px;
}

.competitors__kind {
	display: block;
	color: var(--color-text-maxcontrast);
}

.competitors__target {
	word-break: break-all;
}

.competitors__reason {
	display: block;
	color: var(--color-text-maxcontrast);
}


.competitors__none {
	color: var(--color-text-maxcontrast);
}

.competitors__events {
	list-style: none;
	padding: 0;

	li {
		padding: 12px 0;
		border-bottom: 1px solid var(--color-border);
	}
}

.competitors__event-head {
	display: flex;
	gap: 12px;
	align-items: baseline;
	flex-wrap: wrap;
}

.competitors__event-title {
	font-weight: bold;
	/* `break-word` is deprecated: the modern spelling of "break anywhere rather
	   than overflow" is overflow-wrap, and word-break keeps its own meaning. */
	overflow-wrap: anywhere;
}

.competitors__relevance,
.competitors__event-meta {
	color: var(--color-text-maxcontrast);
}

.competitors__event-meta {
	display: flex;
	gap: 12px;
	flex-wrap: wrap;
	margin: 4px 0 0;
}

.competitors__event-summary {
	margin: 4px 0 0;
}

.competitors__error {
	color: var(--color-error);
	margin-top: 12px;
}

.competitors__changed {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
	margin: 14px 0 0;
	font-size: 14px;
}

.competitors__changed-label {
	color: var(--color-text-maxcontrast);
}

/* On the search insight page the watches sit flush in the section card, as
   board PqZoekinzicht draws them. */
.competitors--embedded {
	padding: 0;

	.competitors__section {
		margin-bottom: 0;
	}
}
</style>

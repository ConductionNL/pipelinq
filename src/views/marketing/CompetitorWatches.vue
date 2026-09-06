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

  @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
-->
<template>
	<div class="competitors">
		<header class="competitors__header">
			<h2>{{ t('pipelinq', 'Competitors') }}</h2>
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<template v-else>
			<NcNoteCard
				v-if="!configured"
				type="warning"
				data-testid="competitors-unconfigured">
				{{ reason }}
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
				<h3>{{ t('pipelinq', 'Watches') }}</h3>
				<div class="competitors__scroll">
					<table class="competitors__table">
						<thead>
							<tr>
								<th scope="col">
									{{ t('pipelinq', 'Competitor') }}
								</th>
								<th scope="col">{{ t('pipelinq', 'Watching') }}</th>
								<th scope="col">{{ t('pipelinq', 'How often') }}</th>
								<th scope="col">{{ t('pipelinq', 'Last run') }}</th>
								<th scope="col">{{ t('pipelinq', 'Outcome') }}</th>
								<th scope="col" />
							</tr>
						</thead>
						<tbody>
							<tr v-for="watch in watches" :key="idOf(watch)">
								<td>{{ competitorName(watch) }}</td>
								<td>
									<span class="competitors__kind">{{
										watch.kind
									}}</span>
									<span class="competitors__target">{{
										watch.target
									}}</span>
								</td>
								<td>{{ watch.schedule }}</td>
								<td>
									{{ watch.lastRunAt || t('pipelinq', 'Never') }}
								</td>
								<td>
									<span :class="outcomeClass(watch)">
										{{ outcomeLabel(watch) }}
									</span>
									<span
										v-if="watch.lastReason"
										class="competitors__reason">
										{{ watch.lastReason }}
									</span>
								</td>
								<td>
									<NcButton
										variant="tertiary"
										:disabled="running === idOf(watch)"
										@click="run(watch)">
										{{ t('pipelinq', 'Run now') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section class="competitors__section" data-testid="competitors-events">
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
							<span>{{ event.kind }}</span>
							<span>{{ event.seenAt }}</span>
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
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'

export default {
	name: 'CompetitorWatches',
	components: {
		AccountGroupOutline,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
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

	mounted() {
		this.fetchAll()
	},

	methods: {
		/**
		 * GET /api/marketing/watch-events. One request carries the
		 * competitors, the watches and the events the page renders, so
		 * nothing fans out per watch before it paints (pipelinq#1781).
		 *
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		async fetchAll() {
			this.loading = true
			this.error = ''
			try {
				const url = generateUrl('/apps/pipelinq/api/marketing/watch-events')
				const { data } = await axios.get(url)
				this.configured = Boolean(data?.configured)
				this.reason = data?.reason || ''
				this.competitors = data?.competitors || []
				this.watches = data?.watches || []
				this.events = data?.events || []
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load the competitor watches.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Run one watch now. The scheduled run belongs to an OpenRegister
		 * flow; this is the "I just added it, does it work" path.
		 *
		 * @param {object} watch The watch.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-a-watch-event-is-written-once-per-watch-and-url
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
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		idOf(row) {
			return String(row?.id || row?.['@self']?.id || row?.uuid || '')
		},

		/**
		 * @param {object} watch A watch.
		 * @return {string} The competitor's name.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		competitorName(watch) {
			return this.competitorNameById(watch?.competitorId)
		},

		/**
		 * @param {string} id A competitor id.
		 * @return {string} The name, or the id when it is unknown.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		competitorNameById(id) {
			const found = this.competitors.find(
				(competitor) => this.idOf(competitor) === String(id),
			)
			return String(found?.name || id || '')
		},

		/**
		 * What the last run did, in words rather than a code.
		 *
		 * @param {object} watch A watch.
		 * @return {string}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
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
		 * @return {string} The class marking a failed outcome.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-the-competitors-page-shows-what-changed-and-says-what-did-not-run
		 */
		outcomeClass(watch) {
			if (!watch?.lastOutcome || watch.lastOutcome === 'ok') {
				return 'competitors__outcome'
			}
			return 'competitors__outcome competitors__outcome--failed'
		},

		/**
		 * The relevance of one event, or the fact that nothing scored it.
		 * An unscored event is never rendered as a zero.
		 *
		 * @param {object} event A watch event.
		 * @return {string}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-competitor-watches/spec.md#requirement-relevance-is-scored-by-hermiq-or-left-unscored
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

.competitors__scroll {
	overflow-x: auto;
}

.competitors__table {
	width: 100%;
	border-collapse: collapse;

	th,
	td {
		padding: 8px 12px;
		border-bottom: 1px solid var(--color-border);
		text-align: start;
		vertical-align: top;
	}

	th {
		font-weight: bold;
	}
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

.competitors__outcome--failed {
	color: var(--color-error);
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
</style>

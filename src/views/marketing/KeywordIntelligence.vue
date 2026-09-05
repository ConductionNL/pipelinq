<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V.

  Keywords (marketing-search-intelligence, phase 5): the four derivations over
  the Search Console rows phase 2 imports, and the confirmation that turns one
  proposal into a keyword target.

  ONE REQUEST SERVES THE PAGE. All four sections come out of a single
  GET /api/marketing/keyword-proposals. Fanning out per proposal before the
  page paints is the failure pipelinq#1781 removed from the blast performance
  page, and the derivations run over the same rows anyway.

  @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
-->
<template>
	<div class="keyword-intel">
		<header class="keyword-intel__header">
			<h2>{{ t('pipelinq', 'Keywords') }}</h2>
			<NcSelect
				v-model="window"
				class="keyword-intel__window"
				:options="windowOptions"
				:clearable="false"
				:inputLabel="t('pipelinq', 'Period')"
				label="label"
				@input="fetchProposals" />
		</header>

		<NcLoadingIcon v-if="loading" :size="32" />

		<NcEmptyContent
			v-else-if="isEmpty"
			class="keyword-intel__empty"
			data-testid="keyword-intel-empty"
			:name="t('pipelinq', 'No search data yet')"
			:description="emptyDescription">
			<template #icon>
				<KeyVariant :size="20" />
			</template>
		</NcEmptyContent>

		<template v-else>
			<p class="keyword-intel__meta" data-testid="keyword-intel-window">
				{{
					t(
						'pipelinq',
						'Between {from} and {to}, from {count} impressions',
						{
							from,
							to,
							count: minImpressions,
						},
					)
				}}
			</p>

			<section
				class="keyword-intel__section"
				data-testid="keyword-intel-buckets">
				<h3>{{ t('pipelinq', 'Where we rank') }}</h3>
				<div class="keyword-intel__buckets">
					<div
						v-for="bucket in buckets"
						:key="bucket.bucket"
						class="keyword-intel__bucket">
						<span class="keyword-intel__bucket-label">{{
							bucket.bucket
						}}</span>
						<strong>{{ bucket.queries }}</strong>
						<span class="keyword-intel__bucket-note">
							{{
								t(
									'pipelinq',
									'{clicks} clicks, {impressions} impressions',
									{
										clicks: bucket.clicks,
										impressions: bucket.impressions,
									},
								)
							}}
						</span>
					</div>
				</div>
			</section>

			<section
				class="keyword-intel__section"
				data-testid="keyword-intel-striking">
				<h3>{{ t('pipelinq', 'One push from page one') }}</h3>
				<p v-if="!strikingDistance.length" class="keyword-intel__none">
					{{
						t(
							'pipelinq',
							'No query sits between position 8 and 20 with enough impressions and a click-through below what that position normally earns.',
						)
					}}
				</p>
				<div v-else class="keyword-intel__scroll">
					<table class="keyword-intel__table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Query') }}</th>
								<th scope="col" class="keyword-intel__num">
									{{ t('pipelinq', 'Position') }}
								</th>
								<th scope="col" class="keyword-intel__num">
									{{ t('pipelinq', 'Impressions') }}
								</th>
								<th scope="col" class="keyword-intel__num">
									{{ t('pipelinq', 'CTR') }}
								</th>
								<th scope="col" class="keyword-intel__num">
									{{ t('pipelinq', 'Behind by') }}
								</th>
								<th scope="col" />
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in strikingDistance" :key="row.query">
								<td>{{ row.query }}</td>
								<td class="keyword-intel__num">
									{{ row.position }}
								</td>
								<td class="keyword-intel__num">
									{{ row.impressions }}
								</td>
								<td class="keyword-intel__num">
									{{ percent(row.ctr) }}
								</td>
								<td class="keyword-intel__num">
									{{ shortfall(row) }}
								</td>
								<td>
									<NcButton
										v-if="
											!isConfirmed(row.query, confirmedTerms)
										"
										variant="tertiary"
										@click="
											openConfirm(row, 'striking-distance')
										">
										{{ t('pipelinq', 'Add as target') }}
									</NcButton>
									<span v-else class="keyword-intel__taken">
										{{ t('pipelinq', 'Already a target') }}
									</span>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<section
				class="keyword-intel__section"
				data-testid="keyword-intel-cannibalisation">
				<h3>{{ t('pipelinq', 'Two pages, one query') }}</h3>
				<p v-if="!cannibalisation.length" class="keyword-intel__none">
					{{
						t(
							'pipelinq',
							'No query has two pages taking a real share of its impressions while earning less together than the better page does alone.',
						)
					}}
				</p>
				<ul v-else class="keyword-intel__findings">
					<li v-for="row in cannibalisation" :key="row.query">
						<div class="keyword-intel__finding-head">
							<strong>{{ row.query }}</strong>
							<span>
								{{
									t(
										'pipelinq',
										'Together {combined}, best page alone {best}',
										{
											combined: percent(row.combinedCtr),
											best: percent(row.bestPageCtr),
										},
									)
								}}
							</span>
							<NcButton
								v-if="!isConfirmed(row.query, confirmedTerms)"
								variant="tertiary"
								@click="openConfirm(row, 'cannibalisation')">
								{{ t('pipelinq', 'Add as target') }}
							</NcButton>
						</div>
						<ul class="keyword-intel__pages">
							<li v-for="page in row.pages" :key="page.page">
								<span class="keyword-intel__page-url">{{
									page.page
								}}</span>
								<span>
									{{
										t(
											'pipelinq',
											'position {position}, {share} of impressions',
											{
												position: page.position,
												share: pageShare(
													page,
													row.impressions,
												),
											},
										)
									}}
								</span>
							</li>
						</ul>
					</li>
				</ul>
			</section>

			<section class="keyword-intel__section" data-testid="keyword-intel-gaps">
				<h3>{{ t('pipelinq', 'Nothing of ours answers this') }}</h3>
				<NcNoteCard
					v-if="crawlNotice(crawl)"
					type="warning"
					data-testid="keyword-intel-crawl-notice">
					{{ crawlNotice(crawl) }}
					{{
						t(
							'pipelinq',
							'Set a crawl source under Settings, Marketing intelligence to switch this check on.',
						)
					}}
				</NcNoteCard>
				<p v-else-if="!gaps.length" class="keyword-intel__none">
					{{
						t(
							'pipelinq',
							'Every query with demand is answered by a page whose title or headings carry its terms.',
						)
					}}
				</p>
				<div v-else class="keyword-intel__scroll">
					<table class="keyword-intel__table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Query') }}</th>
								<th scope="col" class="keyword-intel__num">
									{{ t('pipelinq', 'Impressions') }}
								</th>
								<th scope="col" class="keyword-intel__num">
									{{ t('pipelinq', 'Position') }}
								</th>
								<th scope="col" />
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in gaps" :key="row.query">
								<td>{{ row.query }}</td>
								<td class="keyword-intel__num">
									{{ row.impressions }}
								</td>
								<td class="keyword-intel__num">
									{{ row.position }}
								</td>
								<td>
									<NcButton
										v-if="
											!isConfirmed(row.query, confirmedTerms)
										"
										variant="tertiary"
										@click="openConfirm(row, 'content-gap')">
										{{ t('pipelinq', 'Add as target') }}
									</NcButton>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</template>

		<KeywordTargetConfirmModal
			v-if="confirming"
			:proposal="confirming.proposal"
			:kind="confirming.kind"
			@close="confirming = null"
			@confirmed="onConfirmed" />

		<p v-if="error" class="keyword-intel__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcEmptyContent,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
} from '@nextcloud/vue'
import KeyVariant from 'vue-material-design-icons/KeyVariant.vue'
import KeywordTargetConfirmModal from '../../modals/KeywordTargetConfirmModal.vue'
import {
	confirmPayload,
	crawlNotice,
	isConfirmed,
	pageShare,
	percent,
	shortfall,
} from '../../services/keywordIntel.js'

const DAY = 24 * 60 * 60 * 1000

export default {
	name: 'KeywordIntelligence',
	components: {
		KeyVariant,
		KeywordTargetConfirmModal,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
	},

	data() {
		return {
			loading: true,
			error: '',
			buckets: [],
			strikingDistance: [],
			cannibalisation: [],
			gaps: [],
			crawl: { crawled: false, reason: '' },
			confirmedTerms: [],
			minImpressions: 0,
			from: '',
			to: '',
			window: null,
			confirming: null,
		}
	},

	computed: {
		/**
		 * The selectable windows.
		 *
		 * @return {Array<object>} days and label per option.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
		 */
		windowOptions() {
			return [
				{ days: 28, label: this.t('pipelinq', 'Last 28 days') },
				{ days: 90, label: this.t('pipelinq', 'Last 90 days') },
			]
		},

		/**
		 * Whether there is anything at all to show.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
		 */
		isEmpty() {
			return !this.buckets.some((bucket) => bucket.queries > 0)
		},

		/**
		 * What the empty state says. Without imported rows there is nothing
		 * to derive from, and the settings are where that is fixed.
		 *
		 * @return {string}
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
		 */
		emptyDescription() {
			return this.t(
				'pipelinq',
				'Connect a Search Console property under Settings, Marketing traffic. Once the import has run, this page shows where you rank, which queries are one push from page one, which pages compete with each other, and which questions nothing of yours answers.',
			)
		},
	},

	created() {
		this.window = this.windowOptions[0]
	},

	mounted() {
		this.fetchProposals()
	},

	methods: {
		percent,
		shortfall,
		pageShare,
		isConfirmed,
		crawlNotice,

		/**
		 * GET /api/marketing/keyword-proposals for the chosen window. One
		 * request serves every section on this page.
		 *
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
		 */
		async fetchProposals() {
			this.loading = true
			this.error = ''
			const days = this.window?.days || 28
			const today = new Date()
			const start = new Date(today.getTime() - days * DAY)
			try {
				const url = generateUrl(
					'/apps/pipelinq/api/marketing/keyword-proposals',
				)
				const { data } = await axios.get(url, {
					params: { from: this.isoDay(start), to: this.isoDay(today) },
				})
				this.buckets = data?.buckets || []
				this.strikingDistance = data?.strikingDistance || []
				this.cannibalisation = data?.cannibalisation || []
				this.gaps = data?.gaps || []
				this.crawl = data?.crawl || { crawled: false, reason: '' }
				this.confirmedTerms = data?.confirmedTerms || []
				this.minImpressions = data?.minImpressions || 0
				this.from = data?.from || ''
				this.to = data?.to || ''
			} catch (e) {
				this.buckets = []
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load keyword proposals.')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Open the confirmation dialog for one proposal.
		 *
		 * @param {object} proposal The proposal row.
		 * @param {string} kind Which derivation proposed it.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		openConfirm(proposal, kind) {
			this.confirming = { proposal, kind }
		},

		/**
		 * Record a confirmed term without re-reading the whole page: the
		 * proposals do not change because a target was created.
		 *
		 * @param {string} term The confirmed term.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		onConfirmed(term) {
			this.confirmedTerms = [
				...this.confirmedTerms,
				String(term).toLowerCase(),
			]
			this.confirming = null
		},

		/**
		 * @param {Date} date A date.
		 * @return {string} YYYY-MM-DD in UTC.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-the-keywords-page-shows-the-four-derivations-and-confirms-one-at-a-time
		 */
		isoDay(date) {
			return date.toISOString().slice(0, 10)
		},

		/**
		 * The confirmation body, kept here so the page and the modal agree.
		 *
		 * @param {object} proposal The proposal.
		 * @param {object} choice What the marketer chose.
		 * @param {string} kind The proposal kind.
		 * @return {object} The request body.
		 * @spec openspec/changes/marketing-search-intelligence/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
		 */
		payloadFor(proposal, choice, kind) {
			return confirmPayload(proposal, choice, kind)
		},
	},
}
</script>

<style scoped lang="scss">
.keyword-intel {
	padding: 20px;
}

.keyword-intel__header {
	display: flex;
	align-items: flex-end;
	justify-content: space-between;
	gap: 16px;
	flex-wrap: wrap;
	margin-bottom: 16px;
}

.keyword-intel__window {
	min-width: 200px;
}

.keyword-intel__meta {
	color: var(--color-text-maxcontrast);
	margin-bottom: 16px;
}

.keyword-intel__section {
	margin-bottom: 32px;
}

.keyword-intel__buckets {
	display: flex;
	gap: 16px;
	flex-wrap: wrap;
}

.keyword-intel__bucket {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 12px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	min-width: 160px;
}

.keyword-intel__bucket-label,
.keyword-intel__bucket-note {
	color: var(--color-text-maxcontrast);
}

.keyword-intel__none {
	color: var(--color-text-maxcontrast);
}

.keyword-intel__scroll {
	overflow-x: auto;
}

.keyword-intel__table {
	width: 100%;
	border-collapse: collapse;

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

.keyword-intel__num {
	text-align: end;
}

.keyword-intel__taken {
	color: var(--color-text-maxcontrast);
}

.keyword-intel__findings {
	list-style: none;
	padding: 0;
}

.keyword-intel__finding-head {
	display: flex;
	gap: 12px;
	align-items: center;
	flex-wrap: wrap;
}

.keyword-intel__pages {
	list-style: none;
	padding: 0 0 12px 16px;
	color: var(--color-text-maxcontrast);

	li {
		display: flex;
		gap: 12px;
		flex-wrap: wrap;
	}
}

.keyword-intel__page-url {
	word-break: break-all;
}

.keyword-intel__error {
	color: var(--color-error);
	margin-top: 12px;
}
</style>

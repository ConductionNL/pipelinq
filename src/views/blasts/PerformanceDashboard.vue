<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  PerformanceDashboard — three-tab post-send analytics surface for the
  marketing-segmentation-and-blast chain (member 08).

  Tab 1 Overview        list recent blasts with delivery KPIs (open / click /
                        unsubscribe rates) and sortable columns.
  Tab 2 A/B Testing     side-by-side variant comparison; once each arm has
                        >=500 delivered and >=24h elapsed since send, compute
                        a chi-square p-value on click rate and interpret it.
                        Below threshold display a "not-yet-available" notice.
  Tab 3 Attribution     attributed deal count + summed attributed EUR value
                        per blast, fetched from GET /api/blasts/:id/attribution.

  @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
  @spec openspec/specs/marketing-analytics/spec.md#requirement-a-b-significance-reported-in-dashboard
  @spec openspec/specs/marketing-analytics/spec.md#requirement-attribution-dashboard-sums-revenue-per-blast
-->
<template>
	<div class="performance-dashboard">
		<header class="performance-dashboard__header">
			<NcButton variant="tertiary" @click="$router.push({ name: 'Blasts' })">
				{{ t('pipelinq', 'Back to blasts') }}
			</NcButton>
			<h2>{{ t('pipelinq', 'Blast performance') }}</h2>
		</header>

		<nav class="performance-dashboard__tabs" role="tablist">
			<button
				v-for="tab in tabs"
				:key="tab.id"
				type="button"
				role="tab"
				:aria-selected="activeTab === tab.id"
				class="performance-dashboard__tab"
				:class="[
					{ 'performance-dashboard__tab--active': activeTab === tab.id },
				]"
				@click="onTabClick(tab.id)">
				{{ tab.label }}
			</button>
		</nav>

		<NcLoadingIcon v-if="loading" :size="32" />

		<section v-else class="performance-dashboard__body">
			<!-- Tab 1: Overview -->
			<section
				v-if="activeTab === 'overview'"
				class="performance-dashboard__pane">
				<p v-if="blasts.length === 0" class="performance-dashboard__empty">
					{{ t('pipelinq', 'No blasts yet.') }}
				</p>
				<table v-else class="performance-dashboard__table">
					<thead>
						<tr>
							<th
								v-for="col in overviewColumns"
								:key="col.key"
								scope="col"
								:aria-sort="ariaSort(col.key)"
								@click="onSort(col.key)">
								{{ col.label }}
								<span
									v-if="overviewSortKey === col.key"
									class="performance-dashboard__sort-indicator">
									{{ overviewSortOrder === 'asc' ? '▲' : '▼' }}
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in sortedOverviewRows" :key="row.id">
							<td>{{ row.name }}</td>
							<td>{{ row.segmentName }}</td>
							<td>
								<CnStatusBadge
									:status="row.status"
									:label="statusLabel(row.status)" />
							</td>
							<td class="performance-dashboard__num">
								{{ row.sent }}
							</td>
							<td class="performance-dashboard__num">
								{{ row.delivered }}
							</td>
							<td class="performance-dashboard__num">
								{{ formatPercent(row.openRate) }}
							</td>
							<td class="performance-dashboard__num">
								{{ formatPercent(row.clickRate) }}
							</td>
							<td class="performance-dashboard__num">
								{{ row.unsubscribed }}
							</td>
						</tr>
					</tbody>
				</table>
			</section>

			<!-- Tab 2: A/B Testing -->
			<section v-if="activeTab === 'ab'" class="performance-dashboard__pane">
				<p v-if="abPairs.length === 0" class="performance-dashboard__empty">
					{{ t('pipelinq', 'No A/B variant blasts found.') }}
				</p>
				<article
					v-for="pair in abPairs"
					:key="pair.id"
					class="performance-dashboard__ab-card">
					<h3 class="performance-dashboard__ab-title">
						{{ pair.parentName }}
					</h3>
					<div class="performance-dashboard__ab-grid">
						<div class="performance-dashboard__ab-variant">
							<h4>{{ t('pipelinq', 'Variant A') }}</h4>
							<dl>
								<div>
									<dt>{{ t('pipelinq', 'Delivered') }}</dt>
									<dd>{{ pair.a.delivered }}</dd>
								</div>
								<div>
									<dt>{{ t('pipelinq', 'Clicked') }}</dt>
									<dd>{{ pair.a.clicked }}</dd>
								</div>
								<div>
									<dt>{{ t('pipelinq', 'Click rate') }}</dt>
									<dd>{{ formatPercent(pair.a.clickRate) }}</dd>
								</div>
							</dl>
						</div>
						<div class="performance-dashboard__ab-variant">
							<h4>{{ t('pipelinq', 'Variant B') }}</h4>
							<dl>
								<div>
									<dt>{{ t('pipelinq', 'Delivered') }}</dt>
									<dd>{{ pair.b.delivered }}</dd>
								</div>
								<div>
									<dt>{{ t('pipelinq', 'Clicked') }}</dt>
									<dd>{{ pair.b.clicked }}</dd>
								</div>
								<div>
									<dt>{{ t('pipelinq', 'Click rate') }}</dt>
									<dd>{{ formatPercent(pair.b.clickRate) }}</dd>
								</div>
							</dl>
						</div>
					</div>
					<p
						v-if="!pair.eligible"
						class="performance-dashboard__ab-pending"
						role="status">
						{{
							t(
								'pipelinq',
								'Results not yet available (need >=500 delivered per variant and 24h since send).',
							)
						}}
						<br />
						{{
							t(
								'pipelinq',
								'Currently A: {a} delivered, B: {b} delivered.',
								{ a: pair.a.delivered, b: pair.b.delivered },
							)
						}}
					</p>
					<p
						v-else
						class="performance-dashboard__ab-verdict"
						:class="{
							'performance-dashboard__ab-verdict--significant':
								pair.significant,
						}"
						role="status">
						<strong>{{ pair.verdictLabel }}</strong>
						<span class="performance-dashboard__ab-pvalue">
							{{ t('pipelinq', 'p = {p}', { p: pair.pValueLabel }) }}
						</span>
					</p>
				</article>
			</section>

			<!-- Tab 3: Attribution -->
			<section
				v-if="activeTab === 'attribution'"
				class="performance-dashboard__pane">
				<p
					v-if="attributionRows.length === 0"
					class="performance-dashboard__empty">
					{{ t('pipelinq', 'No attribution data yet.') }}
				</p>
				<table v-else class="performance-dashboard__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('pipelinq', 'Blast') }}
							</th>
							<th scope="col" class="performance-dashboard__num">
								{{ t('pipelinq', 'Attributed deals') }}
							</th>
							<th scope="col" class="performance-dashboard__num">
								{{ t('pipelinq', 'Attributed value') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in attributionRows" :key="row.id">
							<td>{{ row.name }}</td>
							<td class="performance-dashboard__num">
								{{ row.dealCount }}
							</td>
							<td class="performance-dashboard__num">
								{{ formatEur(row.attributedValue) }}
							</td>
						</tr>
					</tbody>
				</table>

				<!-- Site traffic per campaign (marketing-campaign-attribution):
				     the sessions Portaliq attributed to each blast's campaign. -->
				<div
					class="performance-dashboard__traffic"
					data-testid="campaign-traffic">
					<h3>{{ t('pipelinq', 'Site traffic from this campaign') }}</h3>
					<p
						v-if="trafficConnected === null"
						class="performance-dashboard__empty"
						data-testid="campaign-traffic-loading">
						{{ t('pipelinq', 'Loading site traffic') }}
					</p>
					<p
						v-else-if="trafficConnected === false"
						class="performance-dashboard__empty"
						data-testid="campaign-traffic-unconnected">
						{{ t('pipelinq', 'Not connected to a portal.') }}
						{{
							t(
								'pipelinq',
								'Set the Portaliq portal under Settings, Marketing traffic, to see the site sessions each campaign brought in.',
							)
						}}
					</p>
					<p
						v-else-if="
							trafficConnected === true && trafficRows.length === 0
						"
						class="performance-dashboard__empty">
						{{
							t(
								'pipelinq',
								'No site sessions attributed to a blast yet.',
							)
						}}
					</p>
					<table
						v-else-if="trafficConnected === true"
						class="performance-dashboard__table"
						data-testid="campaign-traffic-table">
						<thead>
							<tr>
								<th scope="col">{{ t('pipelinq', 'Blast') }}</th>
								<th scope="col">{{ t('pipelinq', 'Campaign') }}</th>
								<th scope="col" class="performance-dashboard__num">
									{{ t('pipelinq', 'Opens') }}
								</th>
								<th scope="col" class="performance-dashboard__num">
									{{ t('pipelinq', 'Clicks') }}
								</th>
								<th scope="col" class="performance-dashboard__num">
									{{ t('pipelinq', 'Site sessions') }}
								</th>
								<th scope="col" class="performance-dashboard__num">
									{{ t('pipelinq', 'Attributed deals') }}
								</th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="row in trafficRows" :key="row.id">
								<td>{{ row.name }}</td>
								<td>{{ row.campaign }}</td>
								<td class="performance-dashboard__num">
									{{ row.opened }}
								</td>
								<td class="performance-dashboard__num">
									{{ row.clicked }}
								</td>
								<td class="performance-dashboard__num">
									{{ row.sessions }}
								</td>
								<td class="performance-dashboard__num">
									{{ row.dealCount }}
								</td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>
		</section>

		<p v-if="error" class="performance-dashboard__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnStatusBadge } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'

const OVERVIEW_LIMIT = 50
/** How many per-blast attribution reads may be in flight at once. */
const ATTRIBUTION_CONCURRENCY = 6
const AB_MIN_DELIVERED = 500
const AB_MIN_ELAPSED_MS = 24 * 60 * 60 * 1000
const P_VALUE_ALPHA = 0.05

export default {
	name: 'PerformanceDashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		CnStatusBadge,
	},

	data() {
		return {
			loading: true,
			error: '',
			activeTab: 'overview',
			blasts: [],
			segments: {},
			attributionRows: [],
			// Site traffic per campaign: null until the first performance read
			// answers, then whether a portal is connected.
			trafficConnected: null,
			trafficRows: [],
			/** Whether the Attribution tab's per-blast reads have been started. */
			attributionRequested: false,
			overviewSortKey: 'sent',
			overviewSortOrder: 'desc',
		}
	},

	computed: {
		/**
		 * Tab definitions surfaced in the role="tablist" nav.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		tabs() {
			return [
				{ id: 'overview', label: this.t('pipelinq', 'Overview') },
				{ id: 'ab', label: this.t('pipelinq', 'A/B testing') },
				{ id: 'attribution', label: this.t('pipelinq', 'Attribution') },
			]
		},

		/**
		 * Column descriptors for the Overview tab, keyed by the row property
		 * they sort against.
		 *
		 * @return {Array<{key: string, label: string}>}
		 */
		overviewColumns() {
			return [
				{ key: 'name', label: this.t('pipelinq', 'Name') },
				{ key: 'segmentName', label: this.t('pipelinq', 'Segment') },
				{ key: 'status', label: this.t('pipelinq', 'Status') },
				{ key: 'sent', label: this.t('pipelinq', 'Sent') },
				{ key: 'delivered', label: this.t('pipelinq', 'Delivered') },
				{ key: 'openRate', label: this.t('pipelinq', 'Open rate') },
				{ key: 'clickRate', label: this.t('pipelinq', 'Click rate') },
				{ key: 'unsubscribed', label: this.t('pipelinq', 'Unsubscribed') },
			]
		},

		/**
		 * Flattened Overview rows derived from the loaded blast list — pre-
		 * computes the open / click rates so the column sort can compare
		 * them as plain numbers.
		 *
		 * @return {Array<object>}
		 */
		overviewRows() {
			return this.blasts.map((blast) => {
				const totals = blast.totals || {}
				const sent = totals.sent || 0
				const delivered = totals.delivered || 0
				const opened = totals.opened || 0
				const clicked = totals.clicked || 0
				const unsubscribed = totals.unsubscribed || 0
				const openRate = delivered > 0 ? opened / delivered : 0
				const clickRate = delivered > 0 ? clicked / delivered : 0
				return {
					id: blast.id || blast.uuid || blast.slug,
					name: blast.name || '—',
					segmentName: this.segmentNameFor(blast.segmentId),
					status: blast.status || 'draft',
					sent,
					delivered,
					opened,
					clicked,
					openRate,
					clickRate,
					unsubscribed,
				}
			})
		},

		/**
		 * Overview rows after applying the column sort selected by the user.
		 *
		 * @return {Array<object>}
		 */
		sortedOverviewRows() {
			const rows = [...this.overviewRows]
			const key = this.overviewSortKey
			const dir = this.overviewSortOrder === 'asc' ? 1 : -1
			rows.sort((a, b) => {
				const av = a[key]
				const bv = b[key]
				if (typeof av === 'number' && typeof bv === 'number') {
					return (av - bv) * dir
				}
				return String(av).localeCompare(String(bv)) * dir
			})
			return rows
		},

		/**
		 * Group parent A/B blasts with their variant children and roll up
		 * the chi-square verdict for each pair.
		 *
		 * @return {Array<object>}
		 */
		abPairs() {
			const byParent = {}
			for (const blast of this.blasts) {
				const parentId = blast.abVariantOf
				if (!parentId) {
					continue
				}
				if (!byParent[parentId]) {
					byParent[parentId] = []
				}
				byParent[parentId].push(blast)
			}
			const pairs = []
			for (const [parentId, variants] of Object.entries(byParent)) {
				const parent = this.blasts.find(
					(b) => (b.id || b.uuid || b.slug) === parentId,
				)
				if (!parent) {
					continue
				}
				const variantB = variants[0]
				pairs.push(this.buildAbPair(parent, variantB))
			}
			return pairs
		},
	},

	mounted() {
		this.fetchAll()
	},

	methods: {
		/**
		 * Load blasts, segments (for name lookup) and per-blast attribution
		 * summaries in parallel. Each block degrades independently —
		 * Attribution failures don't blank the Overview tab.
		 *
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
		 */
		async fetchAll() {
			this.loading = true
			this.error = ''
			try {
				await Promise.all([this.fetchBlasts(), this.fetchSegments()])
			} catch (e) {
				this.error =
					e?.response?.data?.error
					|| this.t('pipelinq', 'Could not load performance data.')
			} finally {
				this.loading = false
			}
			// Attribution and site traffic each cost one request per blast, and
			// only the Attribution tab shows either. Loading them here held the
			// spinner for the length of the whole fan-out, so Overview and A/B
			// testing rendered nothing at all until the last answer arrived.
			// They now load when that tab is first opened, in loadAttribution().
		},

		/**
		 * Select a tab, and load what that tab needs the first time it is
		 * opened.
		 *
		 * @param {string} tabId One of `overview`, `ab`, `attribution`.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/marketing-analytics/spec.md#requirement-attribution-dashboard-sums-revenue-per-blast
		 */
		onTabClick(tabId) {
			this.activeTab = tabId
			if (tabId === 'attribution') {
				this.loadAttribution()
			}
		},

		/**
		 * Load the Attribution tab's two per-blast blocks, once.
		 *
		 * Both cost one request per blast, so they are deliberately not part
		 * of the page load: a reader on Overview or A/B testing never pays for
		 * them.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/marketing-analytics/spec.md#requirement-attribution-dashboard-sums-revenue-per-blast
		 */
		loadAttribution() {
			if (this.attributionRequested) {
				return
			}
			this.attributionRequested = true
			this.fetchAttributionRows()
			this.fetchTrafficRows()
		},

		/**
		 * GET /api/blasts — list recent blasts (limit OVERVIEW_LIMIT).
		 *
		 * @return {Promise<void>}
		 */
		async fetchBlasts() {
			const url = generateUrl('/apps/pipelinq/api/blasts')
			const { data } = await axios.get(url, {
				params: { limit: OVERVIEW_LIMIT },
			})
			this.blasts = data?.data || data?.results || []
		},

		/**
		 * GET /api/segments — build an id->name lookup for the Overview
		 * Segment column. Failure is non-fatal: rows fall back to '—'.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
		 */
		async fetchSegments() {
			try {
				const url = generateUrl('/apps/pipelinq/api/segments')
				const { data } = await axios.get(url, { params: { limit: 100 } })
				const rows = data?.data || data?.results || []
				const map = {}
				for (const s of rows) {
					const id = s.id || s.uuid || s.slug
					if (id) {
						map[id] = s.name || id
					}
				}
				this.segments = map
			} catch {
				this.segments = {}
			}
		},

		/**
		 * For every loaded blast, fetch its attribution summary from
		 * `GET /api/blasts/:id/attribution` and collect non-zero rows into
		 * the Attribution tab table.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/marketing-analytics/spec.md#requirement-attribution-dashboard-sums-revenue-per-blast
		 */
		async fetchAttributionRows() {
			// One request per blast, run a few at a time. Strictly sequential
			// it took as many round trips as there are blasts before the tab
			// could say anything; unbounded it would open fifty at once.
			const ids = this.blasts
				.map((blast) => ({
					id: blast.id || blast.uuid || blast.slug,
					name: blast.name,
				}))
				.filter((entry) => Boolean(entry.id))
			const found = new Map()

			const worker = async (queue) => {
				for (;;) {
					const entry = queue.shift()
					if (entry === undefined) {
						return
					}
					try {
						const url = generateUrl(
							`/apps/pipelinq/api/blasts/${entry.id}/attribution`,
						)
						const { data } = await axios.get(url)
						if (
							(data?.dealCount || 0) > 0
							|| (data?.attributedValue || 0) > 0
						) {
							found.set(entry.id, {
								id: entry.id,
								name: entry.name || entry.id,
								dealCount: data.dealCount || 0,
								attributedValue: data.attributedValue || 0,
							})
						}
					} catch {
						// Skip; keep the queue draining.
					}
				}
			}

			const queue = ids.slice()
			await Promise.all(
				Array.from(
					{ length: Math.min(ATTRIBUTION_CONCURRENCY, queue.length) },
					() => worker(queue),
				),
			)

			// Report in the order the blasts were listed, not the order the
			// answers happened to arrive.
			this.attributionRows = ids
				.map((entry) => found.get(entry.id))
				.filter(Boolean)
		},

		/**
		 * For every loaded blast, read `GET /api/blasts/:id/performance` and
		 * collect the site sessions Portaliq attributed to its campaign. The
		 * first answer says whether a portal is connected at all; when it is
		 * not, the loop stops there and the block says so.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/marketing-campaign-attribution/specs/marketing-campaign-attribution/spec.md#requirement-campaign-performance-joins-site-sessions-to-a-blast
		 */
		async fetchTrafficRows() {
			const rows = []
			let connected = null
			for (const blast of this.blasts) {
				const id = blast.id || blast.uuid || blast.slug
				if (!id) {
					continue
				}
				try {
					const url = generateUrl(
						`/apps/pipelinq/api/blasts/${id}/performance`,
					)
					const { data } = await axios.get(url)
					connected = Boolean(data?.connected)
					if (!connected) {
						break
					}
					if ((data?.site?.sessions || 0) > 0) {
						rows.push({
							id,
							name: blast.name || id,
							campaign: data.campaign || '',
							opened: data.email?.opened || 0,
							clicked: data.email?.clicked || 0,
							sessions: data.site?.sessions || 0,
							dealCount: data.deals?.dealCount || 0,
						})
					}
				} catch {
					// Skip; keep loop alive.
				}
			}
			this.trafficConnected = connected
			this.trafficRows = rows
		},

		/**
		 * Look up a human-readable segment name; fall back to em-dash.
		 *
		 * @param {string} segmentId The segment id.
		 * @return {string}
		 */
		segmentNameFor(segmentId) {
			if (!segmentId) {
				return '—'
			}
			return this.segments[segmentId] || segmentId
		},

		/**
		 * Toggle sort column / direction on the Overview table.
		 *
		 * @param {string} key The column key to sort on.
		 */
		onSort(key) {
			if (this.overviewSortKey === key) {
				this.overviewSortOrder =
					this.overviewSortOrder === 'asc' ? 'desc' : 'asc'
			} else {
				this.overviewSortKey = key
				this.overviewSortOrder = 'desc'
			}
		},

		/**
		 * ARIA sort attribute for a column header.
		 *
		 * @param {string} key The column key.
		 * @return {string}
		 */
		ariaSort(key) {
			if (this.overviewSortKey !== key) {
				return 'none'
			}
			return this.overviewSortOrder === 'asc' ? 'ascending' : 'descending'
		},

		/**
		 * Build the per-blast A/B comparison + significance verdict.
		 *
		 * @param {object} parent  Variant-A blast (parent).
		 * @param {object} variant Variant-B blast.
		 * @return {object}
		 */
		buildAbPair(parent, variant) {
			const a = this.variantStats(parent)
			const b = this.variantStats(variant)
			const elapsedMsA = this.elapsedSinceSend(parent)
			const elapsedMsB = this.elapsedSinceSend(variant)
			const elapsedOk =
				elapsedMsA >= AB_MIN_ELAPSED_MS && elapsedMsB >= AB_MIN_ELAPSED_MS
			const nOk =
				a.delivered >= AB_MIN_DELIVERED && b.delivered >= AB_MIN_DELIVERED
			const eligible = elapsedOk && nOk
			let pValue = null
			let significant = false
			if (eligible) {
				pValue = this.chiSquarePValue(
					a.clicked,
					a.delivered,
					b.clicked,
					b.delivered,
				)
				significant = pValue !== null && pValue < P_VALUE_ALPHA
			}
			return {
				id: parent.id || parent.uuid || parent.slug,
				parentName: parent.name || '—',
				a,
				b,
				eligible,
				significant,
				pValueLabel: pValue === null ? '—' : pValue.toFixed(3),
				verdictLabel: this.abVerdictLabel(a, b, pValue, significant),
			}
		},

		/**
		 * Extract delivered / clicked / clickRate for one variant.
		 *
		 * @param {object} blast The variant blast.
		 * @return {{delivered: number, clicked: number, clickRate: number}}
		 */
		variantStats(blast) {
			const totals = blast?.totals || {}
			const delivered = totals.delivered || 0
			const clicked = totals.clicked || 0
			return {
				delivered,
				clicked,
				clickRate: delivered > 0 ? clicked / delivered : 0,
			}
		},

		/**
		 * Milliseconds since `sentAt` (or `scheduledFor` as fallback) for a
		 * blast. Returns 0 when no timestamp is set.
		 *
		 * @param {object} blast The blast.
		 * @return {number}
		 */
		elapsedSinceSend(blast) {
			const ts = blast?.sentAt || blast?.completedAt || blast?.scheduledFor
			if (!ts) {
				return 0
			}
			const date = new Date(ts)
			if (isNaN(date.getTime())) {
				return 0
			}
			return Math.max(0, Date.now() - date.getTime())
		},

		/**
		 * Pearson chi-square p-value on a 2x2 contingency table of clicks
		 * vs non-clicks for the two variants. Uses an analytical 1-degree
		 * survival function — sf(x, 1) = erfc(sqrt(x/2)).
		 *
		 * @param {number} aClicks    Variant A clicks.
		 * @param {number} aDelivered Variant A delivered.
		 * @param {number} bClicks    Variant B clicks.
		 * @param {number} bDelivered Variant B delivered.
		 * @return {number|null} p-value or null when the table is degenerate.
		 */
		chiSquarePValue(aClicks, aDelivered, bClicks, bDelivered) {
			const aNon = aDelivered - aClicks
			const bNon = bDelivered - bClicks
			if (aDelivered <= 0 || bDelivered <= 0 || aNon < 0 || bNon < 0) {
				return null
			}
			const rowA = aDelivered
			const rowB = bDelivered
			const colClicks = aClicks + bClicks
			const colNon = aNon + bNon
			const total = rowA + rowB
			if (total <= 0 || colClicks <= 0 || colNon <= 0) {
				return null
			}
			const expectedAClicks = (rowA * colClicks) / total
			const expectedANon = (rowA * colNon) / total
			const expectedBClicks = (rowB * colClicks) / total
			const expectedBNon = (rowB * colNon) / total
			if (
				expectedAClicks <= 0
				|| expectedANon <= 0
				|| expectedBClicks <= 0
				|| expectedBNon <= 0
			) {
				return null
			}
			const chi =
				(aClicks - expectedAClicks) ** 2 / expectedAClicks
				+ (aNon - expectedANon) ** 2 / expectedANon
				+ (bClicks - expectedBClicks) ** 2 / expectedBClicks
				+ (bNon - expectedBNon) ** 2 / expectedBNon
			return this.erfc(Math.sqrt(chi / 2))
		},

		/**
		 * Abramowitz-Stegun 7.1.26 approximation of erfc(x). Good to
		 * about 1.5e-7 over x >= 0, more than enough for an A/B verdict.
		 *
		 * @param {number} x Non-negative input.
		 * @return {number} erfc(x).
		 */
		erfc(x) {
			if (x < 0) {
				return 2 - this.erfc(-x)
			}
			const t = 1 / (1 + 0.3275911 * x)
			const y =
				1
				- ((((1.061405429 * t - 1.453152027) * t + 1.421413741) * t
					- 0.284496736)
					* t
					+ 0.254829592)
					* t
					* Math.exp(-x * x)
			return 1 - y
		},

		/**
		 * Human-readable A/B verdict line.
		 *
		 * @param {object}      a           Variant A stats.
		 * @param {object}      b           Variant B stats.
		 * @param {number|null} pValue      Chi-square p-value.
		 * @param {boolean}     significant Whether p < alpha.
		 * @return {string}
		 */
		abVerdictLabel(a, b, pValue, significant) {
			if (pValue === null) {
				return this.t('pipelinq', 'Results not yet available.')
			}
			if (!significant) {
				return this.t('pipelinq', 'Not significant (p>0.05).')
			}
			// sanitize:false — DOMPurify (t()'s default) encodes the literal
			// "<" in "p<0.05" to "&lt;" since it reads as a malformed tag;
			// these are trusted static labels with no markup/vars.
			if (b.clickRate > a.clickRate) {
				return this.t(
					'pipelinq',
					'Variant B significantly higher (p<0.05).',
					undefined,
					undefined,
					{ sanitize: false },
				)
			}
			return this.t(
				'pipelinq',
				'Variant A significantly higher (p<0.05).',
				undefined,
				undefined,
				{ sanitize: false },
			)
		},

		/**
		 * Format a 0..1 fraction as a percentage with one decimal place.
		 *
		 * @param {number} fraction Open / click rate as 0..1.
		 * @return {string}
		 */
		formatPercent(fraction) {
			if (typeof fraction !== 'number' || isNaN(fraction)) {
				return '—'
			}
			return `${(fraction * 100).toFixed(1)}%`
		},

		/**
		 * Format a EUR amount as a localised thousand-separated string.
		 *
		 * @param {number} value EUR amount.
		 * @return {string}
		 */
		formatEur(value) {
			if (typeof value !== 'number' || isNaN(value)) {
				return '—'
			}
			return `EUR ${value.toLocaleString('nl-NL', { minimumFractionDigits: 0, maximumFractionDigits: 2 })}`
		},

		/**
		 * Localised status badge label for the Overview status column.
		 *
		 * @param {string} status The status key.
		 * @return {string}
		 */
		statusLabel(status) {
			const map = {
				draft: this.t('pipelinq', 'Draft'),
				scheduled: this.t('pipelinq', 'Scheduled'),
				sending: this.t('pipelinq', 'Sending'),
				sent: this.t('pipelinq', 'Sent'),
				paused: this.t('pipelinq', 'Paused'),
				failed: this.t('pipelinq', 'Failed'),
				cancelled: this.t('pipelinq', 'Cancelled'),
			}
			return map[status] || status
		},
	},
}
</script>

<style scoped>
.performance-dashboard {
	padding: 20px;
	max-width: 1240px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.performance-dashboard__header {
	display: flex;
	align-items: center;
	gap: 12px;
}

.performance-dashboard__header h2 {
	margin: 0;
}

.performance-dashboard__tabs {
	display: flex;
	gap: 0;
	border-bottom: 1px solid var(--color-border);
}

.performance-dashboard__tab {
	background: transparent;
	border: 0;
	padding: 10px 16px;
	font-weight: 500;
	color: var(--color-text-lighter);
	cursor: pointer;
	border-bottom: 2px solid transparent;
}

.performance-dashboard__tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
}

.performance-dashboard__body {
	display: flex;
	flex-direction: column;
}

.performance-dashboard__pane {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.performance-dashboard__traffic {
	margin-top: 32px;

	h3 {
		font-size: 1rem;
		font-weight: bold;
		margin-bottom: 8px;
	}
}

.performance-dashboard__empty {
	color: var(--color-text-lighter);
	margin: 0;
}

.performance-dashboard__table {
	width: 100%;
	border-collapse: collapse;
}

.performance-dashboard__table th,
.performance-dashboard__table td {
	padding: 8px 12px;
	text-align: start;
	border-bottom: 1px solid var(--color-border);
}

.performance-dashboard__table th {
	color: var(--color-text-lighter);
	font-weight: 500;
	cursor: pointer;
	user-select: none;
}

.performance-dashboard__num {
	text-align: end;
}

.performance-dashboard__sort-indicator {
	margin-inline-start: 4px;
	color: var(--color-primary-element);
}

.performance-dashboard__ab-card {
	display: flex;
	flex-direction: column;
	gap: 8px;
	padding: 16px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.performance-dashboard__ab-title {
	margin: 0;
}

.performance-dashboard__ab-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
	gap: 12px;
}

.performance-dashboard__ab-variant {
	background: var(--color-background-darker);
	padding: 12px;
	border-radius: var(--border-radius);
}

.performance-dashboard__ab-variant h4 {
	margin: 0 0 8px 0;
}

.performance-dashboard__ab-variant dl {
	display: grid;
	grid-template-columns: 1fr auto;
	row-gap: 4px;
	column-gap: 12px;
	margin: 0;
}

.performance-dashboard__ab-variant dt {
	color: var(--color-text-lighter);
}

.performance-dashboard__ab-variant dd {
	margin: 0;
	font-weight: 600;
}

.performance-dashboard__ab-pending {
	color: var(--color-text-lighter);
	margin: 0;
}

.performance-dashboard__ab-verdict {
	margin: 0;
	color: var(--color-main-text);
	display: flex;
	gap: 12px;
	align-items: baseline;
}

.performance-dashboard__ab-verdict--significant {
	color: var(--color-success);
}

.performance-dashboard__ab-pvalue {
	color: var(--color-text-lighter);
	font-size: 0.9em;
}

.performance-dashboard__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}
</style>

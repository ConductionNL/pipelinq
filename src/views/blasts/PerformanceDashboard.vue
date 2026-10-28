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
	<div class="performance-dashboard" :class="lookClass">
		<router-link
			class="performance-dashboard__back"
			:to="{ name: 'Blasts' }">
			<ArrowLeft :size="16" />
			{{ t('pipelinq', 'Back to blasts') }}
		</router-link>
		<CnPageHeader
			:title="t('pipelinq', 'Results of your sends')"
			:description="t('pipelinq', '{shown} of {total} newsletters · opened and clicked from our own measurements · newest first', {
				shown: overviewRows.length,
				total: blasts.length,
			})">
			<template #extra>
				<div class="performance-dashboard__header-actions">
					<NcButton variant="secondary" :disabled="overviewRows.length === 0" @click="downloadOverview">
						<template #icon>
							<Download :size="18" />
						</template>
						{{ t('pipelinq', 'Download') }}
					</NcButton>
					<CnBuildiqEditButton />
				</div>
			</template>
		</CnPageHeader>

		<CnTabs
			:ariaLabel="t('pipelinq', 'Results of your sends')"
			contentClass="performance-dashboard__body"
			@update:activeIndex="onTabChange">
			<template #nav-end>
				<CnActionsMenu
					:showRefresh="true"
					:refreshing="loading"
					variant="secondary"
					:title="t('pipelinq', 'Results of your sends')"
					surface="page:blast-performance"
					testidBase="performance-dashboard"
					@refresh="refresh" />
			</template>
			<NcLoadingIcon v-if="loading" :size="32" />

			<!-- Tab 1: Overview -->
			<CnTab :title="tabs[0].label" :active="activeTab === 'overview'">
				<section
					v-if="!loading && activeTab === 'overview'"
					class="performance-dashboard__pane performance-dashboard__pane--flush">
					<p
						v-if="blasts.length === 0"
						class="performance-dashboard__empty">
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
								<td class="performance-dashboard__name">
									{{ row.name }}
								</td>
								<td>{{ row.segmentName }}</td>
								<td>
									<CnStatusBadge
										:label="statusLabel(row.status)"
										:colorKey="row.status"
										:colorMap="statusColors" />
								</td>
								<td>{{ row.sent }}</td>
								<td class="performance-dashboard__strong">
									{{ formatPercent(row.openRate) }}
								</td>
								<td class="performance-dashboard__strong">
									{{ formatPercent(row.clickRate) }}
								</td>
								<td>{{ row.unsubscribed }}</td>
							</tr>
						</tbody>
					</table>
				</section>
			</CnTab>

			<!-- Tab 2: A/B testing -->
			<CnTab :title="tabs[1].label" :active="activeTab === 'ab'">
				<section
					v-if="!loading && activeTab === 'ab'"
					class="performance-dashboard__pane">
					<p
						v-if="abPairs.length === 0"
						class="performance-dashboard__empty">
						{{ t('pipelinq', 'No A/B variant blasts found.') }}
					</p>
					<template v-for="(pair, index) in abPairs" :key="pair.id">
						<article
							v-if="index === 0"
							class="performance-dashboard__ab-card">
							<div class="performance-dashboard__ab-head">
								<h2 class="performance-dashboard__section-title">
									{{ pair.parentName }}
								</h2>
								<span class="performance-dashboard__muted">
									{{ t('pipelinq', 'Variant A {a}%, variant B {b}% · {count} recipients', { a: pair.split.a, b: pair.split.b, count: pair.recipients }) }}
								</span>
							</div>
							<div class="performance-dashboard__ab-grid">
								<div
									v-for="variant in pair.variants"
									:key="variant.key"
									class="performance-dashboard__ab-variant"
									:class="{ 'performance-dashboard__ab-variant--winner': variant.winner }">
									<div class="performance-dashboard__ab-variant-head">
										<h3 class="performance-dashboard__ab-variant-title">
											{{ variant.label }}
										</h3>
										<CnStatusBadge
											v-if="variant.winner"
											variant="success"
											:label="t('pipelinq', 'Higher click rate')" />
									</div>
									<span v-if="variant.stats.subject" class="performance-dashboard__ab-subject">
										{{ t('pipelinq', 'Subject: {subject}', { subject: variant.stats.subject }) }}
									</span>
									<dl>
										<div>
											<dt>{{ t('pipelinq', 'Delivered') }}</dt>
											<dd>{{ variant.stats.delivered }}</dd>
										</div>
										<div>
											<dt>{{ t('pipelinq', 'Clicked') }}</dt>
											<dd>{{ variant.stats.clicked }}</dd>
										</div>
										<div>
											<dt>{{ t('pipelinq', 'Click rate') }}</dt>
											<dd class="performance-dashboard__ab-rate">
												{{ formatPercent(variant.stats.clickRate) }}
											</dd>
										</div>
									</dl>
								</div>
							</div>
							<p
								v-if="!pair.eligible"
								class="performance-dashboard__ab-pending"
								role="status">
								{{ t('pipelinq', 'Results not yet available (need >=500 delivered per variant and 24h since send).') }}
								<br>
								{{ t('pipelinq', 'Currently A: {a} delivered, B: {b} delivered.', { a: pair.a.delivered, b: pair.b.delivered }) }}
							</p>
							<p
								v-else
								class="performance-dashboard__ab-verdict"
								:class="{ 'performance-dashboard__ab-verdict--significant': pair.significant }"
								role="status">
								<strong class="performance-dashboard__ab-pvalue">
									{{ t('pipelinq', 'p = {p}', { p: pair.pValueLabel }) }}
								</strong>
								<span>{{ pair.verdictLabel }}</span>
							</p>
						</article>
						<div
							v-else
							class="performance-dashboard__ab-summary performance-dashboard__ab-card">
							<span class="performance-dashboard__strong">{{ pair.parentName }}</span>
							<span class="performance-dashboard__muted">
								{{ t('pipelinq', 'Variant A {a} click rate, variant B {b} click rate', { a: formatPercent(pair.a.clickRate), b: formatPercent(pair.b.clickRate) }) }}
							</span>
							<span
								class="performance-dashboard__muted performance-dashboard__small"
								:class="pair.eligible ? 'performance-dashboard__ab-verdict' : 'performance-dashboard__ab-pending'">
								{{ pair.eligible ? pair.verdictLabel : t('pipelinq', 'Results not yet available.') }}
							</span>
						</div>
					</template>
				</section>
			</CnTab>

			<!-- Tab 3: Attribution -->
			<CnTab :title="tabs[2].label" :active="activeTab === 'attribution'">
				<section
					v-if="!loading && activeTab === 'attribution'"
					class="performance-dashboard__pane">
					<h2 class="performance-dashboard__section-title">
						{{ t('pipelinq', 'Attribution to deals') }}
					</h2>
					<p
						v-if="attributionRows.length === 0"
						class="performance-dashboard__empty">
						{{ t('pipelinq', 'No attribution data yet.') }}
					</p>
					<div v-else class="performance-dashboard__table-card">
						<table class="performance-dashboard__table">
							<thead>
								<tr>
									<th scope="col">
										{{ t('pipelinq', 'Blast') }}
									</th>
									<th scope="col">
										{{ t('pipelinq', 'Attributed deals') }}
									</th>
									<th scope="col">
										{{ t('pipelinq', 'Attributed value') }}
									</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="row in attributionRows" :key="row.id">
									<td>{{ row.name }}</td>
									<td>{{ row.dealCount }}</td>
									<td>{{ formatEur(row.attributedValue) }}</td>
								</tr>
							</tbody>
						</table>
					</div>

					<!-- Site traffic per campaign (marketing-campaign-attribution):
					     the sessions Portaliq attributed to each blast's campaign. -->
					<div
						class="performance-dashboard__traffic"
						data-testid="campaign-traffic">
						<div class="performance-dashboard__ab-head">
							<h3 class="performance-dashboard__subsection-title">
								{{ t('pipelinq', 'Site traffic from this campaign') }}
							</h3>
							<span class="performance-dashboard__muted performance-dashboard__small">
								{{ t('pipelinq', 'Portal sessions that came in through a link in the mail') }}
							</span>
						</div>
						<p
							v-if="trafficState === 'loading'"
							class="performance-dashboard__empty"
							data-testid="campaign-traffic-loading">
							{{ t('pipelinq', 'Loading site traffic') }}
						</p>
						<p
							v-else-if="trafficState === 'no-blasts'"
							class="performance-dashboard__empty"
							data-testid="campaign-traffic-no-blasts">
							{{ t('pipelinq', 'No blasts yet.') }}
							{{ t('pipelinq', 'Send a blast, and the sessions its campaign brought in appear here.') }}
						</p>
						<p
							v-else-if="trafficState === 'unreadable'"
							class="performance-dashboard__empty"
							data-testid="campaign-traffic-unreadable">
							{{ t('pipelinq', 'Site traffic could not be read.') }}
							{{ t('pipelinq', 'Every performance request failed. Reload the page, and check the server log if it keeps happening.') }}
						</p>
						<p
							v-else-if="trafficConnected === false"
							class="performance-dashboard__empty"
							data-testid="campaign-traffic-unconnected">
							{{ t('pipelinq', 'Not connected to a portal.') }}
							{{ t('pipelinq', 'Set the Portaliq portal under Settings, Marketing traffic, to see the site sessions each campaign brought in.') }}
						</p>
						<p
							v-else-if="trafficConnected === true && trafficRows.length === 0"
							class="performance-dashboard__empty">
							{{ t('pipelinq', 'No site sessions attributed to a blast yet.') }}
						</p>
						<div
							v-else-if="trafficConnected === true"
							class="performance-dashboard__table-card">
							<table
								class="performance-dashboard__table"
								data-testid="campaign-traffic-table">
								<thead>
									<tr>
										<th scope="col">
											{{ t('pipelinq', 'Campaign') }}
										</th>
										<th scope="col">
											{{ t('pipelinq', 'Opens') }}
										</th>
										<th scope="col">
											{{ t('pipelinq', 'Clicks') }}
										</th>
										<th scope="col">
											{{ t('pipelinq', 'Site sessions') }}
										</th>
									</tr>
								</thead>
								<tbody>
									<tr v-for="row in trafficRows" :key="row.id">
										<td :title="row.campaign">
											{{ row.name }}
										</td>
										<td>{{ row.opened }}</td>
										<td>{{ row.clicked }}</td>
										<td>{{ row.sessions }}</td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</section>
			</CnTab>
		</CnTabs>

		<p v-if="error" class="performance-dashboard__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import { CnActionsMenu, CnBuildiqEditButton, CnPageHeader, CnStatusBadge, CnTab, CnTabs, useLook } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Download from 'vue-material-design-icons/Download.vue'
import {
	chiSquarePValue,
	higherVariant,
	variantSplit,
	variantStats,
	wholePercent,
} from '../../services/abTest.js'
import { readTrafficRows, trafficOutcome } from '../../services/campaignTraffic.js'

const OVERVIEW_LIMIT = 50
/** How many per-blast attribution or traffic reads may be in flight at once. */
const ATTRIBUTION_CONCURRENCY = 6
const AB_MIN_DELIVERED = 500
const AB_MIN_ELAPSED_MS = 24 * 60 * 60 * 1000
const P_VALUE_ALPHA = 0.05

export default {
	name: 'PerformanceDashboard',
	components: {
		CnActionsMenu,
		CnBuildiqEditButton,
		CnPageHeader,
		ArrowLeft,
		Download,
		NcButton,
		NcLoadingIcon,
		CnStatusBadge,
		CnTab,
		CnTabs,
	},

	setup() {
		const { lookClass } = useLook()
		return { lookClass }
	},

	data() {
		return {
			loading: true,
			error: '',
			activeTab: 'overview',
			blasts: [],
			segments: {},
			templateSubjects: {},
			attributionRows: [],
			// Site traffic per campaign: null until the first performance read
			// answers, then whether a portal is connected.
			trafficConnected: null,
			trafficRows: [],
			// WHY THIS EXISTS RATHER THAN `trafficConnected === null`. That one
			// value used to carry three different meanings — still loading,
			// nothing to ask, and every ask failed — and the template read all
			// three as "loading". An instance with no blasts therefore showed a
			// spinner that never stopped, because `fetchTrafficRows` iterates
			// the blast list and there was nothing to iterate: no request was
			// ever sent, so nothing ever set the flag.
			//
			// Measured on the development run of 2026-09-06: the page fetched
			// `GET /api/blasts?limit=50`, got 200 with an empty list, made ZERO
			// `/performance` calls, and sat on "Loading site traffic" until the
			// e2e assertion gave up 60 s later.
			//
			// 'loading' | 'no-blasts' | 'unreadable' | 'answered'
			trafficState: 'loading',
			/** Whether the Attribution tab's per-blast reads have been started. */
			attributionRequested: false,
			/**
			 * The in-flight page load, so a tab that needs the blast list can
			 * wait for it rather than race it.
			 *
			 * @type {Promise<void>|null}
			 */
			pageLoad: null,
			overviewSortKey: 'sent',
			overviewSortOrder: 'desc',
		}
	},

	computed: {
		/**
		 * Status pill colour per blast status (CnStatusBadge colorMap).
		 *
		 * @return {object}
		 */
		statusColors() {
			return {
				draft: 'default',
				scheduled: 'primary',
				sending: 'purple',
				sent: 'success',
				paused: 'warning',
				failed: 'error',
				cancelled: 'default',
			}
		},

		/**
		 * Tab definitions, in the order the CnTab panels are declared.
		 *
		 * @return {Array<{id: string, label: string}>}
		 */
		tabs() {
			return [
				{ id: 'overview', label: this.t('pipelinq', 'Overview') },
				{ id: 'ab', label: this.t('pipelinq', 'A/B testing') },
				{ id: 'attribution', label: this.t('pipelinq', 'Attribution to deals') },
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
				pairs.push({ ...this.buildAbPair(parent, variantB), sortKey: this.sendStamp(parent) })
			}
			// Newest test first: it is the one drawn in full.
			return pairs.sort((x, y) => y.sortKey - x.sortKey)
		},
	},

	/**
	 * Start the page load, and HOLD it.
	 *
	 * The Attribution tab's fan-out iterates the blast list this fetches, so
	 * it has to be able to wait for it rather than race it. Firing and
	 * forgetting is what let the tab settle on "No blasts yet" about an
	 * instance with plenty; see loadAttribution().
	 *
	 * @return {void}
	 *
	 * @spec openspec/specs/marketing-analytics/spec.md#requirement-attribution-dashboard-sums-revenue-per-blast
	 */
	mounted() {
		this.pageLoad = this.fetchAll()
	},

	methods: {
		/**
		 * Save the overview as a CSV file, the columns as the table shows them.
		 *
		 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
		 */
		downloadOverview() {
			const keys = ['name', 'segmentName', 'status', 'sent', 'openRate', 'clickRate', 'unsubscribed']
			const cell = (value) => {
				const text = value === null || value === undefined ? '' : String(value)
				return /[";\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
			}
			const lines = [keys.join(';'), ...this.sortedOverviewRows.map((row) => keys.map((key) => cell(row[key])).join(';'))]
			const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = 'blast-performance.csv'
			link.click()
			URL.revokeObjectURL(link.href)
		},

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
				await this.fetchTemplateSubjects()
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
		 * CnTabs reports the selected tab by index, for clicks and keyboard alike.
		 *
		 * @param {number} index The selected tab's position.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/marketing-analytics/spec.md#requirement-attribution-dashboard-sums-revenue-per-blast
		 */
		onTabChange(index) {
			const tab = this.tabs[index]
			if (tab) {
				this.onTabClick(tab.id)
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
		async loadAttribution() {
			if (this.attributionRequested) {
				return
			}
			this.attributionRequested = true

			// 🔴 THE LIST HAS TO BE THERE FIRST. Both fan-outs iterate
			// `this.blasts`, and a reader who reaches the Attribution tab
			// before the page load lands iterates nothing: zero requests go
			// out, and the blocks settle on "No blasts yet" about an instance
			// that has plenty. `attributionRequested` then latches, so the
			// wrong answer is the final one.
			//
			// Measured on the development run of 2026-09-07: the page read
			// `GET /api/blasts?limit=50` and got a full list, made ZERO
			// `/performance` calls, and reported no blasts. It is a race, so
			// it fails the way races do, which is why the earlier fix for the
			// spinner did not surface it.
			await this.pageLoad

			await Promise.all([this.fetchAttributionRows(), this.fetchTrafficRows()])
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
		 * GET /api/templates/:id for each template an A/B blast sent, keeping
		 * its subject. Failure is non-fatal: the variant card then shows no
		 * subject line.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/newsletter-results-board/specs/marketing/spec.md
		 */
		async fetchTemplateSubjects() {
			const subjects = {}
			await Promise.all(abTemplateIds(this.blasts).map(async (id) => {
				try {
					const { data } = await axios.get(generateUrl('/apps/pipelinq/api/templates/{id}', { id }))
					if (data?.subject) {
						subjects[id] = data.subject
					}
				} catch {
					// No subject for this variant.
				}
			}))
			this.templateSubjects = subjects
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
			// Counted, not inferred: "no blast to ask about" and "every ask
			// failed" need different words, and neither may leave the spinner up.
			const { rows, connected, asked, answered } = await readTrafficRows(
				this.blasts,
				async (id) => {
					const url = generateUrl(`/apps/pipelinq/api/blasts/${id}/performance`)
					const { data } = await axios.get(url)
					return data
				},
				ATTRIBUTION_CONCURRENCY,
			)

			this.trafficConnected = connected
			this.trafficRows = rows

			this.trafficState = trafficOutcome({ asked, answered })
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
			const a = variantStats(parent, this.templateSubjects)
			const b = variantStats(variant, this.templateSubjects)
			const elapsedOk = this.elapsedSinceSend(parent) >= AB_MIN_ELAPSED_MS
				&& this.elapsedSinceSend(variant) >= AB_MIN_ELAPSED_MS
			const nOk = a.delivered >= AB_MIN_DELIVERED && b.delivered >= AB_MIN_DELIVERED
			const eligible = elapsedOk && nOk
			let pValue = null
			let significant = false
			if (eligible) {
				pValue = chiSquarePValue(a.clicked, a.delivered, b.clicked, b.delivered)
				significant = pValue !== null && pValue < P_VALUE_ALPHA
			}
			const winner = eligible ? higherVariant(a, b) : null
			const sent = (parent.totals?.sent || 0) + (variant.totals?.sent || 0)
			return {
				id: parent.id || parent.uuid || parent.slug,
				parentName: parent.name || '—',
				a,
				b,
				variants: [
					{ key: 'a', label: this.t('pipelinq', 'Variant A'), stats: a, winner: winner === 'a' },
					{ key: 'b', label: this.t('pipelinq', 'Variant B'), stats: b, winner: winner === 'b' },
				],

				split: variantSplit(a, b),
				recipients: sent || a.delivered + b.delivered,
				eligible,
				significant,
				pValueLabel: pValue === null ? '—' : pValue.toFixed(2),
				verdictLabel: this.abVerdictLabel(a, b, pValue, significant),
			}
		},

		/**
		 * Reload the page's data, and the Attribution tab's when it was opened.
		 *
		 * @return {Promise<void>}
		 */
		async refresh() {
			const reloadAttribution = this.attributionRequested
			this.attributionRequested = false
			this.pageLoad = this.fetchAll()
			await this.pageLoad
			if (reloadAttribution) {
				await this.loadAttribution()
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
		 * When a blast was sent (or created), as epoch milliseconds; 0 when unknown.
		 *
		 * @param {object} blast The blast.
		 * @return {number}
		 */
		sendStamp(blast) {
			const ts = blast?.sentAt || blast?.completedAt || blast?.scheduledFor || blast?.['@self']?.created || blast?.created
			const time = ts ? new Date(ts).getTime() : 0
			return isNaN(time) ? 0 : time
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
		 * Format a 0..1 fraction as a whole percentage, as the boards print rates.
		 *
		 * @param {number} fraction Open / click rate as 0..1.
		 * @return {string}
		 */
		formatPercent(fraction) {
			return wholePercent(fraction)
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
	box-sizing: border-box;
	width: 100%;
	max-width: 1240px;
	padding: 24px 28px;
	display: flex;
	flex-direction: column;
	gap: 20px;
}

.performance-dashboard__back {
	align-self: flex-start;
	display: inline-flex;
	align-items: center;
	gap: 6px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-primary-element-text-dark, var(--color-primary-element));
	text-decoration: none;
}

.performance-dashboard__back:hover {
	text-decoration: underline;
}

.performance-dashboard__header-actions {
	display: flex;
	flex-wrap: wrap;
	align-items: flex-start;
	gap: 10px;
}

.performance-dashboard__pane {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 20px 22px;
}

.performance-dashboard__pane--flush {
	padding: 8px 0 4px;
	overflow-x: auto;
}

.performance-dashboard__section-title {
	margin: 0;
	font-size: 17px;
	font-weight: 700;
	color: var(--color-main-text);
}

.performance-dashboard__subsection-title {
	margin: 0;
	font-size: 15px;
	font-weight: 700;
	color: var(--color-main-text);
}

.performance-dashboard__muted {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.performance-dashboard__small {
	font-size: 13px;
}

.performance-dashboard__strong {
	font-weight: 600;
}

.performance-dashboard__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.performance-dashboard__table-card {
	border: 1px solid var(--color-border);
	border-radius: 10px;
	overflow-x: auto;
}

.performance-dashboard__table {
	width: 100%;
	border-collapse: collapse;
	font-size: 14px;
}

.performance-dashboard__table th,
.performance-dashboard__table td {
	padding: 12px;
	text-align: start;
	vertical-align: middle;
	border-bottom: 1px solid var(--color-border);
}

.performance-dashboard__table th {
	padding-block: 10px;
	color: var(--color-text-maxcontrast);
	font-weight: 600;
}

.performance-dashboard__table th:first-child,
.performance-dashboard__table td:first-child {
	padding-inline-start: 18px;
}

.performance-dashboard__table tbody tr:last-child td {
	border-bottom: 0;
}

.performance-dashboard__name {
	font-size: 15px;
	font-weight: 600;
}

/* Only the Overview headers sort; they carry aria-sort. */
.performance-dashboard__table th[aria-sort] {
	cursor: pointer;
	user-select: none;
}

.performance-dashboard__sort-indicator {
	margin-inline-start: 4px;
	color: var(--color-primary-element);
}

.performance-dashboard__ab-card {
	display: flex;
	flex-direction: column;
	gap: 14px;
}

.performance-dashboard__ab-head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: baseline;
	gap: 6px 16px;
}

.performance-dashboard__ab-grid {
	display: flex;
	flex-wrap: wrap;
	gap: 16px;
}

.performance-dashboard__ab-variant {
	flex: 1 1 260px;
	display: flex;
	flex-direction: column;
	gap: 10px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: 10px;
}

.performance-dashboard__ab-variant--winner {
	border-color: var(--color-success-text, var(--color-success));
}

.performance-dashboard__ab-variant-head {
	display: flex;
	flex-wrap: wrap;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
}

.performance-dashboard__ab-variant-title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
}

.performance-dashboard__ab-subject {
	font-size: 14px;
	color: var(--color-main-text);
}

.performance-dashboard__ab-variant dl {
	display: grid;
	grid-template-columns: repeat(3, minmax(0, 1fr));
	gap: 12px;
	margin: 0;
}

.performance-dashboard__ab-variant dl > div {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.performance-dashboard__ab-variant dt {
	font-size: 13px;
	text-align: start;
	color: var(--color-text-maxcontrast);
}

.performance-dashboard__ab-variant dd {
	margin: 0;
	font-size: 18px;
	font-weight: 700;
}

.performance-dashboard__ab-variant dd.performance-dashboard__ab-rate {
	font-size: 24px;
}

.performance-dashboard__ab-pending {
	color: var(--color-text-maxcontrast);
	margin: 0;
}

.performance-dashboard__ab-verdict {
	margin: 0;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 12px;
	font-size: 14px;
}

.performance-dashboard__ab-verdict--significant span {
	color: var(--color-success-text, var(--color-success));
	font-weight: 600;
}

.performance-dashboard__ab-pvalue {
	font-size: 15px;
}

.performance-dashboard__ab-summary {
	flex-direction: row;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 6px 16px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}

.performance-dashboard__traffic {
	display: flex;
	flex-direction: column;
	gap: 14px;
	margin-top: 6px;
}

/* Board look (PqNieuwsbriefPrestaties, PqNieuwsbriefABTest,
   PqNieuwsbriefToeschrijving): the header is padded 4px 0 8px and the page's
   20px gap follows, its buttons are 40px, the open tab's panel is a white
   card, and tables and cards stand on that card's ground with their text on a
   1.2 line. */
.cn-look-board :deep(.cn-page-header) {
	padding-block: 4px 8px;
	margin-bottom: 0;
}

.cn-look-board .performance-dashboard__header-actions :deep(.button-vue) {
	min-height: 40px;
	block-size: 40px;
}

.cn-look-board :deep(.performance-dashboard__body) {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: 0 12px 12px 12px;
}

.cn-look-board .performance-dashboard__table th {
	background: transparent;
}

.cn-look-board .performance-dashboard__table :is(th, td) {
	line-height: 1.2;
}

.cn-look-board .performance-dashboard__ab-variant-title {
	line-height: 1.2;
}

.performance-dashboard__error {
	color: var(--color-error);
	font-weight: 600;
	margin: 0;
}
</style>

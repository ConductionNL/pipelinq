<template>
	<div>
		<CnDashboardPage
			:title="t('pipelinq', 'Dashboard')"
			:widgets="widgetDefs"
			:layout="dashboardLayout"
			:loading="loading && !hasData"
			:empty-label="t('pipelinq', 'No widgets configured')"
			:unavailable-label="t('pipelinq', 'Widget not available')"
			@layout-change="onLayoutChange">
			<!-- Header actions: quick action buttons -->
			<template #header-actions>
				<NcButton type="primary" @click="showLeadDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'New Lead') }}
				</NcButton>
				<NcButton @click="showRequestDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'New Request') }}
				</NcButton>
				<NcButton @click="showClientDialog = true">
					<template #icon>
						<Plus :size="20" />
					</template>
					{{ t('pipelinq', 'New Client') }}
				</NcButton>
				<NcButton :disabled="loading"
					:aria-label="t('pipelinq', 'Refresh dashboard')"
					@click="fetchAll">
					<template #icon>
						<Refresh :size="20" :class="{ 'icon-spinning': loading }" />
					</template>
				</NcButton>
			</template>

			<!-- Open Leads count widget -->
			<template #widget-count-open-leads>
				<CnStatsBlock
					:title="t('pipelinq', 'Open Leads')"
					:count="kpi.openLeads"
					:count-label="t('pipelinq', 'leads')"
					:icon="TrendingUp"
					variant="primary"
					horizontal
					:route="{ name: 'Leads', query: { status: 'open' } }" />
			</template>

			<!-- Open Requests count widget -->
			<template #widget-count-open-requests>
				<CnStatsBlock
					:title="t('pipelinq', 'Open Requests')"
					:count="kpi.openRequests"
					:count-label="t('pipelinq', 'requests')"
					:icon="FileDocument"
					variant="primary"
					horizontal
					:route="{ name: 'Requests', query: { status: 'open' } }" />
			</template>

			<!-- Pipeline Value count widget -->
			<template #widget-count-pipeline-value>
				<CnStatsBlock
					:title="t('pipelinq', 'Pipeline Value')"
					:count="kpi.pipelineValue"
					:count-label="'EUR'"
					:icon="CurrencyEur"
					variant="success"
					horizontal
					:route="{ name: 'Pipeline' }" />
			</template>

			<!-- Overdue count widget -->
			<template #widget-count-overdue>
				<CnStatsBlock
					:title="t('pipelinq', 'Overdue')"
					:count="kpi.overdueItems"
					:count-label="t('pipelinq', 'overdue')"
					:icon="AlertCircle"
					:variant="kpi.overdueItems > 0 ? 'error' : 'default'"
					horizontal
					:route="{ name: 'Leads', query: { overdue: 'true' } }" />
			</template>

			<!-- Deals by Stage widget -->
			<template #widget-deals-by-stage>
				<div class="status-widget-content">
					<div v-if="allRequests.length === 0" class="chart-empty">
						{{ t('pipelinq', 'No requests yet') }}
					</div>
					<div v-else class="status-chart">
						<div
							v-for="status in statusChartData"
							:key="status.key"
							class="status-bar-row">
							<span class="status-bar-label">{{ status.label }}</span>
							<div class="status-bar-track">
								<div
									class="status-bar-fill"
									:style="{ width: status.pct + '%', background: status.color }" />
							</div>
							<span class="status-bar-count">{{ status.count }}</span>
						</div>
					</div>
				</div>
			</template>

			<!-- My Work widget -->
			<template #widget-my-work>
				<div class="my-work-widget-content">
					<div v-if="myWorkItems.length === 0" class="chart-empty">
						{{ t('pipelinq', 'No items assigned to you') }}
					</div>
					<div v-else class="my-work-list">
						<div
							v-for="item in myWorkItems"
							:key="item.id"
							class="my-work-item"
							:class="{ 'my-work-item--overdue': item.isOverdue }"
							@click="openItem(item)">
							<span class="entity-badge" :class="'badge--' + item.entityType">
								{{ item.entityType === 'lead' ? 'LEAD' : 'REQ' }}
							</span>
							<span class="my-work-title">{{ item.title }}</span>
							<span class="my-work-stage">{{ item.stageOrStatus }}</span>
							<span v-if="item.dueDate" class="my-work-due" :class="{ overdue: item.isOverdue }">
								{{ formatDate(item.dueDate) }}
							</span>
						</div>
						<NcButton
							v-if="myWorkTotal > 5"
							type="tertiary"
							class="view-all-link"
							@click="$router.push({ name: 'MyWork' })">
							{{ t('pipelinq', 'View all ({count})', { count: myWorkTotal }) }}
						</NcButton>
					</div>
				</div>
			</template>

			<!-- Client Overview widget -->
			<template #widget-client-overview>
				<div class="client-overview-content">
					<div v-if="allClients.length === 0" class="chart-empty">
						{{ t('pipelinq', 'No clients yet') }}
					</div>
					<div v-else class="client-list">
						<div
							v-for="client in recentClients"
							:key="client.id"
							class="client-item"
							@click="$router.push({ name: 'ClientDetail', params: { id: client.id } })">
							<span class="client-name">{{ client.name || client.title || t('pipelinq', 'Unnamed') }}</span>
							<span class="client-info">{{ [client.email, client.city].filter(Boolean).join(' · ') }}</span>
						</div>
						<NcButton
							v-if="allClients.length > 5"
							type="tertiary"
							class="view-all-link"
							@click="$router.push({ name: 'ClientList' })">
							{{ t('pipelinq', 'View all clients ({count})', { count: allClients.length }) }}
						</NcButton>
					</div>
				</div>
			</template>

			<!-- Top Products widget -->
			<template #widget-top-products>
				<ProductRevenue />
			</template>

			<!-- Prospect Discovery widget -->
			<template #widget-prospect-discovery>
				<ProspectWidget />
			</template>

			<!-- Empty state override with welcome message -->
			<template #empty>
				<div v-if="isEmpty" class="welcome-message">
					<p>{{ t('pipelinq', 'Welcome to Pipelinq! Get started by creating your first client, lead, or request using the buttons above.') }}</p>
				</div>
			</template>
		</CnDashboardPage>

		<!-- Error display -->
		<div v-if="error" class="dashboard-error">
			<p>{{ error }}</p>
			<NcButton @click="fetchAll">
				{{ t('pipelinq', 'Retry') }}
			</NcButton>
		</div>

		<!-- Create Dialogs -->
		<LeadCreateDialog
			v-if="showLeadDialog"
			@created="onLeadCreated"
			@close="showLeadDialog = false" />

		<RequestCreateDialog
			v-if="showRequestDialog"
			@created="onRequestCreated"
			@close="showRequestDialog = false" />

		<ClientCreateDialog
			v-if="showClientDialog"
			@created="onClientCreated"
			@close="showClientDialog = false" />
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnDashboardPage, CnStatsBlock } from '@conduction/nextcloud-vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { useObjectStore } from '../store/modules/object.js'
import ProductRevenue from '../components/ProductRevenue.vue'
import ProspectWidget from '../components/ProspectWidget.vue'
import LeadCreateDialog from './leads/LeadCreateDialog.vue'
import RequestCreateDialog from './requests/RequestCreateDialog.vue'
import ClientCreateDialog from './clients/ClientCreateDialog.vue'
import {
	getStatusLabel,
	getStatusColor,
} from '../services/requestStatus.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

/**
 * Default dashboard layout — 4 count tiles across the top row (3 cols each),
 * then deals-by-stage and my-work share the second row,
 * client-overview spans full width on third row.
 */
const DEFAULT_LAYOUT = [
	{ id: 1, widgetId: 'count-open-leads', gridX: 0, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 2, widgetId: 'count-open-requests', gridX: 3, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 3, widgetId: 'count-pipeline-value', gridX: 6, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 4, widgetId: 'count-overdue', gridX: 9, gridY: 0, gridWidth: 3, gridHeight: 2, showTitle: false },
	{ id: 5, widgetId: 'deals-by-stage', gridX: 0, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 6, widgetId: 'my-work', gridX: 6, gridY: 2, gridWidth: 6, gridHeight: 4 },
	{ id: 7, widgetId: 'client-overview', gridX: 0, gridY: 6, gridWidth: 12, gridHeight: 3 },
	{ id: 8, widgetId: 'top-products', gridX: 0, gridY: 9, gridWidth: 6, gridHeight: 4 },
	{ id: 9, widgetId: 'prospect-discovery', gridX: 6, gridY: 9, gridWidth: 6, gridHeight: 4 },
]

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		CnDashboardPage,
		CnStatsBlock,
		Plus,
		Refresh,
		ProductRevenue,
		ProspectWidget,
		LeadCreateDialog,
		RequestCreateDialog,
		ClientCreateDialog,
	},
	data() {
		return {
			// Icon components for CnStatsBlock :icon prop
			TrendingUp,
			FileDocument,
			CurrencyEur,
			AlertCircle,
			loading: false,
			showLeadDialog: false,
			showRequestDialog: false,
			showClientDialog: false,
			error: null,
			refreshTimer: null,
			allLeads: [],
			allRequests: [],
			allPipelines: [],
			allClients: [],
			myLeads: [],
			myRequests: [],
			dashboardLayout: [...DEFAULT_LAYOUT],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		currentUser() {
			return OC.currentUser
		},
		hasData() {
			return this.allLeads.length > 0
				|| this.allRequests.length > 0
				|| this.allClients.length > 0
		},

		widgetDefs() {
			return [
				{ id: 'count-open-leads', title: t('pipelinq', 'Open Leads'), type: 'custom' },
				{ id: 'count-open-requests', title: t('pipelinq', 'Open Requests'), type: 'custom' },
				{ id: 'count-pipeline-value', title: t('pipelinq', 'Pipeline Value'), type: 'custom' },
				{ id: 'count-overdue', title: t('pipelinq', 'Overdue'), type: 'custom' },
				{ id: 'deals-by-stage', title: t('pipelinq', 'Requests by Status'), type: 'custom' },
				{ id: 'my-work', title: t('pipelinq', 'My Work'), type: 'custom' },
				{ id: 'client-overview', title: t('pipelinq', 'Client Overview'), type: 'custom' },
				{ id: 'top-products', title: t('pipelinq', 'Top Products by Pipeline Value'), type: 'custom' },
				{ id: 'prospect-discovery', title: t('pipelinq', 'Prospect Discovery'), type: 'custom' },
			]
		},

		// --- KPI computations ---
		closedStageNames() {
			const names = new Set()
			for (const p of this.allPipelines) {
				if (p.stages) {
					for (const s of p.stages) {
						if (s.isClosed) names.add(s.name)
					}
				}
			}
			return names
		},
		openLeads() {
			return this.allLeads.filter(l => !this.closedStageNames.has(l.stage))
		},
		kpi() {
			const openLeads = this.openLeads.length
			const openRequests = this.allRequests.filter(
				r => r.status === 'new' || r.status === 'in_progress',
			).length
			const pipelineValue = this.openLeads.reduce(
				(sum, l) => sum + (Number(l.value) || 0), 0,
			)

			const now = new Date()
			const thirtyDaysAgo = new Date(now.getTime() - 30 * 24 * 60 * 60 * 1000)

			const overdueLeads = this.openLeads.filter(l => {
				if (!l.expectedCloseDate) return false
				return new Date(l.expectedCloseDate) < now
			}).length

			const overdueRequests = this.allRequests.filter(r => {
				if (r.status !== 'new' && r.status !== 'in_progress') return false
				if (!r.requestedAt) return false
				return new Date(r.requestedAt) < thirtyDaysAgo
			}).length

			return {
				openLeads,
				openRequests,
				pipelineValue,
				overdueItems: overdueLeads + overdueRequests,
			}
		},

		// --- Status chart ---
		statusChartData() {
			const counts = {}
			for (const r of this.allRequests) {
				const s = r.status || 'new'
				counts[s] = (counts[s] || 0) + 1
			}
			const max = Math.max(...Object.values(counts), 1)
			const statuses = ['new', 'in_progress', 'completed', 'rejected', 'converted']
			return statuses
				.filter(s => counts[s] > 0)
				.map(s => ({
					key: s,
					label: getStatusLabel(s),
					color: getStatusColor(s),
					count: counts[s],
					pct: (counts[s] / max) * 100,
				}))
		},

		// --- My Work ---
		myWorkAll() {
			const now = new Date()
			const items = []

			for (const l of this.myLeads) {
				if (this.closedStageNames.has(l.stage)) continue
				const due = l.expectedCloseDate ? new Date(l.expectedCloseDate) : null
				items.push({
					id: l.id,
					entityType: 'lead',
					title: l.title,
					stageOrStatus: l.stage || '-',
					priority: l.priority || 'normal',
					dueDate: l.expectedCloseDate,
					isOverdue: due ? due < now : false,
				})
			}

			for (const r of this.myRequests) {
				if (r.status === 'completed' || r.status === 'rejected' || r.status === 'converted') continue
				const due = r.requestedAt ? new Date(r.requestedAt) : null
				items.push({
					id: r.id,
					entityType: 'request',
					title: r.title,
					stageOrStatus: getStatusLabel(r.status),
					priority: r.priority || 'normal',
					dueDate: r.requestedAt,
					isOverdue: due ? (now - due) > 30 * 24 * 60 * 60 * 1000 : false,
				})
			}

			// Sort: overdue first, then priority, then due date
			items.sort((a, b) => {
				if (a.isOverdue !== b.isOverdue) return a.isOverdue ? -1 : 1
				const pa = PRIORITY_ORDER[a.priority] ?? 2
				const pb = PRIORITY_ORDER[b.priority] ?? 2
				if (pa !== pb) return pa - pb
				if (a.dueDate && b.dueDate) return new Date(a.dueDate) - new Date(b.dueDate)
				if (a.dueDate) return -1
				if (b.dueDate) return 1
				return 0
			})

			return items
		},
		myWorkTotal() {
			return this.myWorkAll.length
		},
		myWorkItems() {
			return this.myWorkAll.slice(0, 5)
		},

		// --- Client overview ---
		recentClients() {
			return this.allClients.slice(0, 5)
		},

		isEmpty() {
			return this.allLeads.length === 0
				&& this.allRequests.length === 0
				&& this.allClients.length === 0
				&& !this.loading
				&& !this.error
		},
	},
	mounted() {
		this.fetchAll()
		this.refreshTimer = setInterval(() => {
			this.fetchAll()
		}, 5 * 60 * 1000)
	},
	beforeDestroy() {
		if (this.refreshTimer) {
			clearInterval(this.refreshTimer)
			this.refreshTimer = null
		}
	},
	methods: {
		async fetchAll() {
			this.loading = true
			this.error = null

			try {
				const store = this.objectStore
				const config = store.objectTypeRegistry

				const promises = []

				if (config.lead) {
					promises.push(
						this.fetchRaw('lead', { _limit: 500 }).then(items => { this.allLeads = items }),
					)
				}

				if (config.request) {
					promises.push(
						this.fetchRaw('request', { _limit: 500 }).then(items => { this.allRequests = items }),
					)
				}

				if (config.pipeline) {
					promises.push(
						this.fetchRaw('pipeline', { _limit: 100 }).then(items => { this.allPipelines = items }),
					)
				}

				if (config.client) {
					promises.push(
						this.fetchRaw('client', { _limit: 500 }).then(items => { this.allClients = items }),
					)
				}

				if (config.lead && this.currentUser) {
					promises.push(
						this.fetchRaw('lead', { assignee: this.currentUser, _limit: 200 }).then(items => { this.myLeads = items }),
					)
				}

				if (config.request && this.currentUser) {
					promises.push(
						this.fetchRaw('request', { assignee: this.currentUser, _limit: 200 }).then(items => { this.myRequests = items }),
					)
				}

				await Promise.all(promises)
			} catch (err) {
				this.error = err.message || t('pipelinq', 'Failed to load dashboard data')
				console.error('Dashboard fetch error:', err)
			} finally {
				this.loading = false
			}
		},

		async fetchRaw(type, params = {}) {
			const config = this.objectStore.objectTypeRegistry[type]
			if (!config) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = '/apps/openregister/api/objects/' + config.register + '/' + config.schema
				+ (queryParams.toString() ? '?' + queryParams.toString() : '')

			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})

			if (!response.ok) throw new Error('Failed to fetch ' + type)
			const data = await response.json()
			return data.results || data || []
		},

		onLayoutChange(newLayout) {
			this.dashboardLayout = newLayout
		},

		formatCurrency(value) {
			if (!value) return 'EUR 0'
			return 'EUR ' + Number(value).toLocaleString('nl-NL')
		},

		formatDate(dateStr) {
			if (!dateStr) return ''
			try {
				return new Date(dateStr).toLocaleDateString('nl-NL', { month: 'short', day: 'numeric' })
			} catch {
				return dateStr
			}
		},

		onLeadCreated(leadId) {
			this.showLeadDialog = false
			this.$router.push({ name: 'LeadDetail', params: { id: leadId } })
		},

		onRequestCreated(requestId) {
			this.showRequestDialog = false
			this.$router.push({ name: 'RequestDetail', params: { id: requestId } })
		},

		onClientCreated(clientId) {
			this.showClientDialog = false
			this.$router.push({ name: 'ClientDetail', params: { id: clientId } })
		},

		openItem(item) {
			if (item.entityType === 'lead') {
				this.$router.push({ name: 'LeadDetail', params: { id: item.id } })
			} else {
				this.$router.push({ name: 'RequestDetail', params: { id: item.id } })
			}
		},
	},
}
</script>

<style scoped>
/* Status chart widget */
.status-widget-content {
	padding: 12px;
	height: 100%;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

.status-chart {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.status-bar-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.status-bar-label {
	width: 110px;
	font-size: 13px;
	text-align: right;
	flex-shrink: 0;
}

.status-bar-track {
	flex: 1;
	height: 22px;
	background: var(--color-background-dark);
	border-radius: 4px;
	overflow: hidden;
}

.status-bar-fill {
	height: 100%;
	border-radius: 4px;
	min-width: 2px;
	transition: width 0.3s ease;
}

.status-bar-count {
	width: 30px;
	font-size: 13px;
	font-weight: 600;
	text-align: right;
	flex-shrink: 0;
}

/* My Work widget */
.my-work-widget-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.my-work-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.my-work-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 12px;
	cursor: pointer;
}

.my-work-item:hover {
	background: var(--color-background-hover);
}

.my-work-item--overdue {
	background: rgba(233, 50, 45, 0.04);
}

.entity-badge {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 4px;
	font-size: 10px;
	font-weight: 700;
	letter-spacing: 0.5px;
	flex-shrink: 0;
}

.badge--lead {
	background: #dbeafe;
	color: #1d4ed8;
	border: 1px solid #93c5fd;
}

.badge--request {
	background: #ffedd5;
	color: #c2410c;
	border: 1px solid #fdba74;
}

.my-work-title {
	flex: 1;
	font-size: 13px;
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.my-work-stage {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.my-work-due.overdue {
	color: var(--color-error);
	font-weight: 600;
}

.view-all-link {
	margin-top: 4px;
	padding-left: 12px;
}

/* Client overview widget */
.client-overview-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.client-list {
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.client-item {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 8px 12px;
	cursor: pointer;
}

.client-item:hover {
	background: var(--color-background-hover);
}

.client-name {
	font-size: 13px;
	font-weight: 500;
	flex: 1;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.client-info {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

/* Welcome / empty / error */
.welcome-message {
	text-align: center;
	padding: 40px 20px;
	color: var(--color-text-maxcontrast);
	font-size: 15px;
}

.dashboard-error {
	text-align: center;
	padding: 20px;
	color: var(--color-error);
}

.dashboard-error p {
	margin-bottom: 12px;
}

/* Refresh button spinning animation */
.icon-spinning {
	animation: spin 1s linear infinite;
}

@keyframes spin {
	from { transform: rotate(0deg); }
	to { transform: rotate(360deg); }
}
</style>

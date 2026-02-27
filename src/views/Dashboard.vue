<template>
	<div class="pipelinq-dashboard">
		<!-- Header with quick actions -->
		<div class="dashboard-header">
			<h2>{{ t('pipelinq', 'Dashboard') }}</h2>
			<div class="quick-actions">
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
			</div>
		</div>

		<NcLoadingIcon v-if="loading" />

		<template v-else>
			<!-- KPI Cards -->
			<div class="kpi-row">
				<div class="kpi-card">
					<div class="kpi-icon">
						<TrendingUp :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpi.openLeads }}</span>
						<span class="kpi-label">{{ t('pipelinq', 'Open Leads') }}</span>
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-icon">
						<FileDocument :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpi.openRequests }}</span>
						<span class="kpi-label">{{ t('pipelinq', 'Open Requests') }}</span>
					</div>
				</div>
				<div class="kpi-card">
					<div class="kpi-icon kpi-icon--value">
						<CurrencyEur :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ formatCurrency(kpi.pipelineValue) }}</span>
						<span class="kpi-label">{{ t('pipelinq', 'Pipeline Value') }}</span>
					</div>
				</div>
				<div class="kpi-card" :class="{ 'kpi-card--warning': kpi.overdueItems > 0 }">
					<div class="kpi-icon" :class="{ 'kpi-icon--warning': kpi.overdueItems > 0 }">
						<AlertCircle :size="24" />
					</div>
					<div class="kpi-content">
						<span class="kpi-value">{{ kpi.overdueItems }}</span>
						<span class="kpi-label">{{ t('pipelinq', 'Overdue') }}</span>
					</div>
				</div>
			</div>

			<!-- Charts row -->
			<div class="charts-row">
				<!-- Requests by Status -->
				<div class="chart-card">
					<h3>{{ t('pipelinq', 'Requests by Status') }}</h3>
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

				<!-- My Work Preview -->
				<div class="chart-card">
					<h3>
						{{ t('pipelinq', 'My Work') }}
						<span v-if="myWorkTotal > 0" class="my-work-count">({{ myWorkTotal }})</span>
					</h3>
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
							@click="$emit('navigate', 'my-work')">
							{{ t('pipelinq', 'View all ({count})', { count: myWorkTotal }) }}
						</NcButton>
					</div>
				</div>
			</div>

			<!-- Welcome message for fresh installs -->
			<div v-if="isEmpty" class="welcome-message">
				<p>{{ t('pipelinq', 'Welcome to Pipelinq! Get started by creating your first client, lead, or request using the buttons above.') }}</p>
			</div>

			<!-- Error display -->
			<div v-if="error" class="dashboard-error">
				<p>{{ error }}</p>
				<NcButton @click="fetchAll">
					{{ t('pipelinq', 'Retry') }}
				</NcButton>
			</div>
		</template>

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
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import TrendingUp from 'vue-material-design-icons/TrendingUp.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import CurrencyEur from 'vue-material-design-icons/CurrencyEur.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import { useObjectStore } from '../store/modules/object.js'
import LeadCreateDialog from './leads/LeadCreateDialog.vue'
import RequestCreateDialog from './requests/RequestCreateDialog.vue'
import ClientCreateDialog from './clients/ClientCreateDialog.vue'
import {
	getStatusLabel,
	getStatusColor,
} from '../services/requestStatus.js'

const PRIORITY_ORDER = { urgent: 0, high: 1, normal: 2, low: 3 }

export default {
	name: 'Dashboard',
	components: {
		NcButton,
		NcLoadingIcon,
		Plus,
		Refresh,
		TrendingUp,
		FileDocument,
		CurrencyEur,
		AlertCircle,
		LeadCreateDialog,
		RequestCreateDialog,
		ClientCreateDialog,
	},
	data() {
		return {
			loading: false,
			showLeadDialog: false,
			showRequestDialog: false,
			showClientDialog: false,
			error: null,
			refreshTimer: null,
			allLeads: [],
			allRequests: [],
			allPipelines: [],
			myLeads: [],
			myRequests: [],
		}
	},
	computed: {
		objectStore() {
			return useObjectStore()
		},
		currentUser() {
			return OC.currentUser
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

		isEmpty() {
			return this.allLeads.length === 0
				&& this.allRequests.length === 0
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

				// Fetch all leads
				if (config.lead) {
					promises.push(
						this.fetchRaw('lead', { _limit: 500 }).then(items => { this.allLeads = items }),
					)
				}

				// Fetch all requests
				if (config.request) {
					promises.push(
						this.fetchRaw('request', { _limit: 500 }).then(items => { this.allRequests = items }),
					)
				}

				// Fetch all pipelines
				if (config.pipeline) {
					promises.push(
						this.fetchRaw('pipeline', { _limit: 100 }).then(items => { this.allPipelines = items }),
					)
				}

				// Fetch my leads
				if (config.lead && this.currentUser) {
					promises.push(
						this.fetchRaw('lead', { assignee: this.currentUser, _limit: 200 }).then(items => { this.myLeads = items }),
					)
				}

				// Fetch my requests
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

			const url = `/apps/openregister/api/objects/${config.register}/${config.schema}`
				+ (queryParams.toString() ? '?' + queryParams.toString() : '')

			const response = await fetch(url, {
				headers: {
					'Content-Type': 'application/json',
					requesttoken: OC.requestToken,
					'OCS-APIREQUEST': 'true',
				},
			})

			if (!response.ok) throw new Error(`Failed to fetch ${type}`)
			const data = await response.json()
			return data.results || data || []
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
			this.$emit('navigate', 'lead-detail', leadId)
		},

		onRequestCreated(requestId) {
			this.showRequestDialog = false
			this.$emit('navigate', 'request-detail', requestId)
		},

		onClientCreated(clientId) {
			this.showClientDialog = false
			this.$emit('navigate', 'client-detail', clientId)
		},

		openItem(item) {
			if (item.entityType === 'lead') {
				this.$emit('navigate', 'lead-detail', item.id)
			} else {
				this.$emit('navigate', 'request-detail', item.id)
			}
		},
	},
}
</script>

<style scoped>
.pipelinq-dashboard {
	padding: 20px;
	max-width: 1200px;
}

/* Header */
.dashboard-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-bottom: 24px;
	flex-wrap: wrap;
	gap: 12px;
}

.quick-actions {
	display: flex;
	gap: 8px;
	flex-wrap: wrap;
}

/* KPI Cards */
.kpi-row {
	display: grid;
	grid-template-columns: repeat(4, 1fr);
	gap: 16px;
	margin-bottom: 24px;
}

@media (max-width: 900px) {
	.kpi-row {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (max-width: 500px) {
	.kpi-row {
		grid-template-columns: 1fr;
	}
}

.kpi-card {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 16px;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.kpi-card--warning {
	border-color: var(--color-warning);
	background: var(--color-warning-hover, rgba(233, 163, 0, 0.05));
}

.kpi-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 44px;
	height: 44px;
	border-radius: 50%;
	background: var(--color-primary-element-light, rgba(0, 130, 201, 0.1));
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.kpi-icon--value {
	background: rgba(70, 186, 97, 0.1);
	color: #46ba61;
}

.kpi-icon--warning {
	background: rgba(233, 50, 45, 0.1);
	color: var(--color-error);
}

.kpi-content {
	display: flex;
	flex-direction: column;
}

.kpi-value {
	font-size: 24px;
	font-weight: 700;
	line-height: 1.2;
}

.kpi-label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

/* Charts row */
.charts-row {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 16px;
	margin-bottom: 24px;
}

@media (max-width: 700px) {
	.charts-row {
		grid-template-columns: 1fr;
	}
}

.chart-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 16px;
}

.chart-card h3 {
	margin: 0 0 12px;
	font-size: 15px;
	font-weight: 600;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
}

/* Status bar chart */
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

/* My Work list */
.my-work-count {
	font-weight: 400;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.my-work-list {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.my-work-item {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px;
	border-radius: var(--border-radius);
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
	align-self: flex-start;
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

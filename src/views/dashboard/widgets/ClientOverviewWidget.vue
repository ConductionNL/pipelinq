<template>
	<div class="client-overview-content">
		<div v-if="!loaded" class="chart-empty">
			{{ t('pipelinq', 'Loading…') }}
		</div>
		<div v-else-if="clients.length === 0" class="chart-empty">
			{{ t('pipelinq', 'No clients yet') }}
		</div>
		<div v-else class="client-list">
			<div
				v-for="client in recent"
				:key="client.id"
				class="client-item"
				@click="$router.push({ name: 'ClientDetail', params: { id: client.id } })">
				<span class="client-name">{{ client.name || client.title || t('pipelinq', 'Unnamed') }}</span>
				<span class="client-info">{{ [client.email, client.city].filter(Boolean).join(' · ') }}</span>
			</div>
			<NcButton
				v-if="clients.length > 5"
				type="tertiary"
				class="view-all-link"
				@click="$router.push({ name: 'Clients' })">
				{{ t('pipelinq', 'View all clients ({count})', { count: clients.length }) }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { getClients } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'ClientOverviewWidget',
	components: {
		NcButton,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			clients: [],
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-6
		 */
		recent() {
			return this.clients.slice(0, 5)
		},
	},
	methods: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-5
		 */
		async load() {
			try {
				this.clients = await getClients()
			} catch (err) {
				console.error('ClientOverviewWidget fetch error:', err)
			} finally {
				this.loaded = true
			}
		},
	},
}
</script>

<style scoped>
.client-overview-content {
	padding: 4px 0;
	height: 100%;
	overflow: auto;
}

.chart-empty {
	padding: 24px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
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

.view-all-link {
	margin-top: 4px;
	padding-left: 12px;
}
</style>

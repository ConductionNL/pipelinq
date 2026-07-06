<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable :rows="rows"
		:columns="columns"
		:loading="!loaded"
		:loading-text="t('pipelinq', 'Loading…')"
		hide-header
		borderless
		:empty-text="t('pipelinq', 'No clients yet')"
		@row-click="open">
		<template #footer>
			<NcButton
				v-if="clients.length > 5"
				type="tertiary"
				class="view-all-link"
				@click="$router.push({ name: 'Clients' })">
				{{ t('pipelinq', 'View all clients ({count})', { count: clients.length }) }}
			</NcButton>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { NcButton } from '@nextcloud/vue'
import { getClients } from '../../../services/dashboardData.js'
import dashboardRefreshMixin from './dashboardRefreshMixin.js'

export default {
	name: 'ClientOverviewWidget',
	components: {
		CnDataTable,
		NcButton,
	},
	mixins: [dashboardRefreshMixin],
	data() {
		return {
			loaded: false,
			clients: [],
			columns: [
				{ key: 'mainText', cellClass: 'cn-cell--strong' },
				{ key: 'subText', cellClass: 'cn-cell--muted cn-cell--end' },
			],
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-dashboard-ui/tasks.md#task-6
		 */
		recent() {
			return this.clients.slice(0, 5)
		},
		/**
		 * The five most recent clients shaped as CnDataTable list rows.
		 *
		 * @return {Array<object>} Shaped rows.
		 */
		rows() {
			return this.recent.map((client) => ({
				id: client.id,
				mainText: client.name || client.title || t('pipelinq', 'Unnamed'),
				subText: [client.email, client.city].filter(Boolean).join(' · '),
			}))
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
		/**
		 * Navigate to the client detail page.
		 *
		 * @param {object} row - The clicked row.
		 */
		open(row) {
			this.$router.push({ name: 'ClientDetail', params: { id: row.id } })
		},
	},
}
</script>

<style scoped>
.view-all-link {
	margin-top: 4px;
}
</style>

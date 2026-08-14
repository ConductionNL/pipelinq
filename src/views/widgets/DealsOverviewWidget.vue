<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('pipelinq', 'No leads found')"
		@rowClick="onShow">
		<template #footer>
			<a
				class="cn-data-table__view-all"
				role="button"
				tabindex="0"
				@click.prevent="onViewAll"
				@keydown.enter.prevent="onViewAll"
				@keydown.space.prevent="onViewAll">
				{{ t('pipelinq', 'View all') }} →
			</a>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { formatCurrency } from '../../services/localeUtils.js'
import { initializeStores } from '../../store/store.js'
import { toText } from '../../utils/widgetText.js'
import { LIST_COLUMNS, navigateTo } from './listTable.js'

export default {
	name: 'DealsOverviewWidget',
	components: {
		CnDataTable,
	},

	props: {
		title: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			loading: false,
			leads: [],
			clients: [],
			columns: LIST_COLUMNS,
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-28
		 */
		clientMap() {
			const map = {}
			for (const c of this.clients) {
				map[c.id] = c
			}
			return map
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-31
		 */
		items() {
			return this.leads.map((lead) => {
				const client =
					this.clientMap[lead.client] || this.clientMap[lead.clientId]
				const clientName = client
					? toText(client.name) || toText(client.title)
					: ''
				const value = lead.value ? formatCurrency(lead.value) : ''
				const subParts = [clientName, value, toText(lead.stage)].filter(
					Boolean,
				)

				return {
					id: lead.id,
					mainText: toText(lead.title) || t('pipelinq', 'Untitled lead'),
					subText: subParts.join(' · '),
				}
			})
		},
	},

	async mounted() {
		await this.fetchData()
	},

	methods: {
		/**
		 * Navigate to the clicked lead in the same tab.
		 *
		 * @param {object} item The clicked row (a shaped lead item).
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-32
		 */
		onShow(item) {
			navigateTo(generateUrl('/apps/pipelinq/leads/' + item.id))
		},

		/**
		 * Navigate to the full leads list.
		 *
		 * @return {void}
		 */
		onViewAll() {
			navigateTo(generateUrl('/apps/pipelinq/leads'))
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-29
		 */
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				const config = objectStore.objectTypeRegistry

				if (config.lead) {
					this.leads = await this.fetchRaw(config, 'lead', {
						_limit: 20,
						_order: 'created_at:desc',
					})
				}
				if (config.client) {
					this.clients = await this.fetchRaw(config, 'client', {
						_limit: 500,
					})
				}
			} catch (err) {
				console.error('DealsOverviewWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},

		/**
		 * @param {object} config The object-type registry (register/schema per type).
		 * @param {string} type The object type to fetch.
		 * @param {object} params Query parameters.
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-30
		 */
		async fetchRaw(config, type, params = {}) {
			const typeConfig = config[type]
			if (!typeConfig) return []

			const queryParams = new URLSearchParams()
			for (const [key, value] of Object.entries(params)) {
				if (value === undefined || value === null || value === '') continue
				queryParams.set(key, value)
			}

			const url = generateUrl(
				'/apps/openregister/api/objects/'
					+ typeConfig.register
					+ '/'
					+ typeConfig.schema
					+ (queryParams.toString() ? '?' + queryParams.toString() : ''),
			)

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
	},
}
</script>

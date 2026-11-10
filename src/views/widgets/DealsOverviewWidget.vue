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
			<a class="cn-data-table__view-all" :href="leadsUrl">
				{{ t('pipelinq', 'View all') }} →
			</a>
		</template>
	</CnDataTable>
</template>

<script>
import { CnDataTable } from '@conduction/nextcloud-vue'
import { generateUrl } from '@nextcloud/router'
import { formatCurrency } from '../../services/localeUtils.js'
import { fetchRegisteredObjects } from '../../services/objectFetch.js'
import { toText } from '../../utils/widgetText.js'
import { navigateTo } from './listTable.js'
import listWidgetMixin from './listWidgetMixin.js'

export default {
	name: 'DealsOverviewWidget',
	components: {
		CnDataTable,
	},

	mixins: [listWidgetMixin],

	data() {
		return {
			leads: [],
			clients: [],
			leadsUrl: generateUrl('/apps/pipelinq/leads'),
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

	methods: {
		/**
		 * Navigate to the clicked lead.
		 *
		 * @param {object} item The clicked row (a shaped lead item).
		 * @param {MouseEvent} [event] The row click.
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-32
		 */
		onShow(item, event) {
			navigateTo(generateUrl('/apps/pipelinq/leads/' + item.id), event)
		},

		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-29
		 */
		async fetchData() {
			await this.loadWidget('DealsOverviewWidget', async (config) => {
				if (config.lead) {
					this.leads = await fetchRegisteredObjects(config, {
						type: 'lead',
						params: {
							_limit: 20,
							_order: 'created_at:desc',
						},
					})
				}
				if (config.client) {
					this.clients = await fetchRegisteredObjects(config, {
						type: 'client',
						params: {
							_limit: 500,
						},
					})
				}
			})
		},

	},
}
</script>

<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hideHeader
		borderless
		:emptyText="t('pipelinq', 'No leads assigned to you')"
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
import { formatDate } from '../../services/localeUtils.js'
import { fetchRegisteredObjects } from '../../services/objectFetch.js'
import { toText } from '../../utils/widgetText.js'
import { navigateTo } from './listTable.js'
import listWidgetMixin from './listWidgetMixin.js'

export default {
	name: 'MyLeadsWidget',
	components: {
		CnDataTable,
	},

	mixins: [listWidgetMixin],

	data() {
		return {
			leads: [],
			leadsUrl: generateUrl('/apps/pipelinq/leads'),
		}
	},

	computed: {
		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-45
		 */
		items() {
			const now = new Date()
			return this.leads.map((lead) => {
				const isOverdue =
					lead.expectedCloseDate && new Date(lead.expectedCloseDate) < now
				const priorityLabel = lead.priority
					? t('pipelinq', lead.priority)
					: ''
				const dueStr = lead.expectedCloseDate
					? formatDate(lead.expectedCloseDate)
					: ''
				const subParts = [
					priorityLabel,
					toText(lead.stage),
					dueStr ? (isOverdue ? '⚠ ' + dueStr : dueStr) : '',
				].filter(Boolean)

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
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-46
		 */
		onShow(item, event) {
			navigateTo(generateUrl('/apps/pipelinq/leads/' + item.id), event)
		},

		/**
		 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-widgets-ui/tasks.md#task-43
		 */
		async fetchData() {
			await this.loadWidget('MyLeadsWidget', async (config) => {
				if (config.lead && window.OC?.getCurrentUser?.()?.uid) {
					this.leads = await fetchRegisteredObjects(config, {
						type: 'lead',
						params: {
							assignee: window.OC?.getCurrentUser?.()?.uid,
							_limit: 20,
						},
					})
				}
			})
		},

	},
}
</script>

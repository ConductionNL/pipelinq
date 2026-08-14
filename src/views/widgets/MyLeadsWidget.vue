<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<template>
	<CnDataTable
		:rows="items"
		:columns="columns"
		:loading="loading"
		hide-header
		borderless
		:empty-text="t('pipelinq', 'No leads assigned to you')"
		@row-click="onShow">
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
import { formatDate } from '../../services/localeUtils.js'
import { initializeStores } from '../../store/store.js'
import { toText } from '../../utils/widgetText.js'
import { LIST_COLUMNS, navigateTo } from './listTable.js'

export default {
	name: 'MyLeadsWidget',
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
			columns: LIST_COLUMNS,
		}
	},
	computed: {
		/**
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-45
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
	async mounted() {
		await this.fetchData()
	},
	methods: {
		/**
		 * Navigate to the clicked lead in the same tab.
		 *
		 * @param {object} item The clicked row (a shaped lead item).
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-46
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
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-43
		 */
		async fetchData() {
			this.loading = true
			try {
				const { objectStore } = await initializeStores()
				const config = objectStore.objectTypeRegistry

				if (config.lead && OC.currentUser) {
					this.leads = await this.fetchRaw(config, 'lead', {
						assignee: OC.currentUser,
						_limit: 20,
					})
				}
			} catch (err) {
				console.error('MyLeadsWidget fetch error:', err)
			} finally {
				this.loading = false
			}
		},
		/**
		 * @param {object} config The object-type registry (register/schema per type).
		 * @param {string} type The object type to fetch.
		 * @param {object} params Query parameters.
		 * @spec openspec/changes/reverse-2026-05-26-fe-widgets-ui/tasks.md#task-44
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

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Export destinations')"
			:description="
				t(
					'pipelinq',
					'Data-warehouse and object-storage targets for BI exports',
				)
			"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sortKey="sortKey"
			:sortOrder="sortOrder"
			:includeColumns="visibleColumns"
			:emptyTitle="t('pipelinq', 'No destinations yet')"
			:emptyActionLabel="t('pipelinq', 'New destination')"
			@add="createNew"
			@emptyAction="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openDestination"
			@pageChanged="onPageChange">
			<template #row-actions="{ row }">
				<NcButton
					variant="tertiary"
					:disabled="busyId === row.id"
					@click.stop="testConnection(row)">
					{{ t('pipelinq', 'Test connection') }}
				</NcButton>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import { inject } from 'vue'
import { exportApi } from '../../services/exportApi.js'
import { useObjectStore } from '../../store/modules/object.js'

export default {
	name: 'ExportDestinations',
	components: {
		CnIndexPage,
		NcButton,
	},

	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('exportDestination', { sidebarState, objectStore })
	},

	data() {
		return {
			busyId: null,
			refreshing: false,
		}
	},

	computed: {
		/**
		 * Columns shown on the list, in order.
		 *
		 * @return {Array<string>} The column keys.
		 */
		visibleColumns() {
			return [
				'name',
				'type',
				'connectorSourceId',
				'validationStatus',
				'lastValidatedAt',
			]
		},
	},

	methods: {
		/**
		 * Refresh handler for the Actions-menu Refresh item. Drives the
		 * CnIndexPage `:refreshing` spinner around the underlying fetch.
		 *
		 * @spec exclude presentational refresh-button spinner wiring — no business logic
		 */
		async onRefresh() {
			this.refreshing = true
			try {
				await this.refresh()
			} finally {
				this.refreshing = false
			}
		},

		/**
		 * Navigate to a destination's edit form.
		 *
		 * @param {object} row The clicked row.
		 */
		openDestination(row) {
			this.$router.push({
				name: 'ExportDestinationDetail',
				params: { id: row.id },
			})
		},

		/**
		 * Start a new destination.
		 */
		createNew() {
			this.$router.push({ name: 'ExportDestinationNew' })
		},

		/**
		 * Test connectivity to a destination.
		 *
		 * @param {object} row The destination row.
		 */
		async testConnection(row) {
			this.busyId = row.id
			try {
				const result = await exportApi.testDestination(row.id)
				if (result.valid) {
					showSuccess(this.t('pipelinq', 'Connection valid'))
				} else {
					showError(this.t('pipelinq', 'Connection failed'))
				}
				await this.refresh()
			} catch {
				showError(this.t('pipelinq', 'Connection test failed'))
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

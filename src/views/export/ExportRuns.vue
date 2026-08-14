<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'Export runs')"
			:description="
				t('pipelinq', 'History of scheduled and manual export runs')
			"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No export runs yet')"
			:show-add="false"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openRun"
			@page-changed="onPageChange">
			<template #row-actions="{ row }">
				<NcButton
					v-if="canRetry(row)"
					variant="tertiary"
					:disabled="busyId === row.id"
					@click.stop="retry(row)">
					{{ t('pipelinq', 'Retry') }}
				</NcButton>
			</template>
		</CnIndexPage>
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { exportApi } from '../../services/exportApi.js'

export default {
	name: 'ExportRuns',
	components: {
		CnIndexPage,
		NcButton,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('exportRun', {
			sidebarState,
			objectStore,
			defaultSort: { key: 'startedAt', order: 'desc' },
		})
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
				'jobId',
				'status',
				'rowCount',
				'byteCount',
				'startedAt',
				'endedAt',
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
		 * Whether a run can be retried.
		 *
		 * @param {object} row The run row.
		 * @return {boolean} True for failed / partial / skipped runs.
		 */
		canRetry(row) {
			return ['failed', 'partial', 'skipped_overlap'].includes(row.status)
		},
		/**
		 * Navigate to a run's detail.
		 *
		 * @param {object} row The clicked row.
		 */
		openRun(row) {
			this.$router.push({ name: 'ExportRunDetail', params: { id: row.id } })
		},
		/**
		 * Retry a failed run.
		 *
		 * @param {object} row The run row.
		 */
		async retry(row) {
			this.busyId = row.id
			try {
				await exportApi.retryRun(row.id)
				showSuccess(this.t('pipelinq', 'A new run has been queued'))
				await this.refresh()
			} catch (e) {
				showError(e.message || this.t('pipelinq', 'Could not retry the run'))
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			:title="t('pipelinq', 'BI export jobs')"
			:description="t('pipelinq', 'Scheduled exports of pipelinq data to a data warehouse or BI tool')"
			:schema="schema"
			:objects="objects"
			:pagination="pagination"
			:loading="loading"
			:refreshing="refreshing"
			:sort-key="sortKey"
			:sort-order="sortOrder"
			:include-columns="visibleColumns"
			:empty-title="t('pipelinq', 'No export jobs yet')"
			:empty-action-label="t('pipelinq', 'New export job')"
			@add="createNew"
			@empty-action="createNew"
			@refresh="onRefresh"
			@sort="onSort"
			@view="openJob"
			@page-changed="onPageChange">
			<template #row-actions="{ row }">
				<NcButton type="tertiary" :disabled="busyId === row.id" @click.stop="testRun(row)">
					{{ t('pipelinq', 'Test run') }}
				</NcButton>
				<NcButton type="tertiary" :disabled="busyId === row.id" @click.stop="toggleEnabled(row)">
					{{ row.enabled ? t('pipelinq', 'Disable') : t('pipelinq', 'Enable') }}
				</NcButton>
			</template>
		</CnIndexPage>
		<ExportTestRunModal
			v-if="testRunJobId"
			:job-id="testRunJobId"
			@close="testRunJobId = null" />
	</div>
</template>

<script>
import { inject } from 'vue'
import { NcButton } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { CnIndexPage, useListView } from '@conduction/nextcloud-vue'
import { useObjectStore } from '../../store/modules/object.js'
import { exportApi } from '../../services/exportApi.js'
import ExportTestRunModal from '../../modals/ExportTestRunModal.vue'

export default {
	name: 'ExportJobs',
	components: {
		CnIndexPage,
		NcButton,
		ExportTestRunModal,
	},
	setup() {
		const sidebarState = inject('sidebarState', null)
		const objectStore = useObjectStore()
		return useListView('exportJob', { sidebarState, objectStore })
	},
	data() {
		return {
			busyId: null,
			testRunJobId: null,
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
			return ['name', 'destinationId', 'format', 'mode', 'scheduleCron', 'enabled']
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
		 * Navigate to a job's detail/edit form.
		 *
		 * @param {object} row The clicked row.
		 */
		openJob(row) {
			this.$router.push({ name: 'ExportJobDetail', params: { id: row.id } })
		},
		/**
		 * Start a new job.
		 */
		createNew() {
			this.$router.push({ name: 'ExportJobNew' })
		},
		/**
		 * Open the test-run modal for a job. The modal auto-executes the run.
		 *
		 * @param {object} row The job row.
		 */
		testRun(row) {
			this.testRunJobId = row.id
		},
		/**
		 * Enable or disable a job.
		 *
		 * @param {object} row The job row.
		 */
		async toggleEnabled(row) {
			this.busyId = row.id
			try {
				if (row.enabled) {
					await exportApi.disableJob(row.id)
					showSuccess(this.t('pipelinq', 'Job disabled'))
				} else {
					await exportApi.enableJob(row.id)
					showSuccess(this.t('pipelinq', 'Job enabled'))
				}
				await this.refresh()
			} catch (e) {
				showError(this.t('pipelinq', 'Could not change job status'))
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

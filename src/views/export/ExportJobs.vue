<!--
  - SPDX-License-Identifier: EUPL-1.2
  - SPDX-FileCopyrightText: 2024 Conduction B.V.
-->
<template>
	<div>
		<CnIndexPage
			ref="index"
			:title="t('pipelinq', 'BI export')"
			:register="register"
			:schema="schema"
			:columns="columns"
			:quickFilters="quickFilters"
			:showTitle="true"
			:pageTabs="pageTabs"
			:filterMenu="true"
			:showTitleIcon="false"
			:headerFilters="false"
			rowTitle="plain"
			:searchPlaceholder="t('pipelinq', 'Filter by job name')"
			:headerButtons="headerButtons"
			:countText="
				t(
					'pipelinq',
					'{shown} of {total} export jobs · scheduled exports of pipelinq data to a data warehouse or BI tool',
				)
			"
			:footerNote="
				t(
					'pipelinq',
					'A new job stays off until a test run has succeeded. Keys stay with the destination\'s source, never with the job.',
				)
			"
			:emptyText="t('pipelinq', 'No export jobs yet')"
			:actions="rowActions"
			:showEditAction="false"
			:showCopyAction="false"
			:showDeleteAction="false"
			rowClickToView
			@add="createNew"
			@rowClick="openJob"
			@view="openJob" />
		<ExportTestRunModal
			v-if="testRunJobId"
			:jobId="testRunJobId"
			@close="testRunJobId = null" />
	</div>
</template>

<script>
import { CnIndexPage, openRowTarget } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import ExportTestRunModal from '../../modals/ExportTestRunModal.vue'
import { exportApi } from '../../services/exportApi.js'
import { exportJobSecondaryLabels, exportPageTabs } from './exportPageTabs.js'
import { exportSchedule, lastRunLine } from './exportRunText.js'

/** A destination's type as its product name, under the destination's name. */
const DESTINATION_TYPES = {
	s3: 'Amazon S3',
	azure_data_lake: 'Azure Data Lake',
	gcs: 'Google Cloud Storage',
	bigquery: 'BigQuery',
	snowflake: 'Snowflake',
	sftp: 'SFTP',
	postgres: 'PostgreSQL',
}

/**
 * The schedule as written, under its words; nothing when the words are the
 * expression itself.
 *
 * @param {object} row The job row.
 * @return {string} The expression, or ''.
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-reads-its-schedule-in-words
 */
function scheduleSource(row) {
	const written = typeof row?.scheduleCron === 'string' ? row.scheduleCron : ''
	return written !== '' && exportSchedule(written) !== written ? written : ''
}

export default {
	name: 'ExportJobs',
	components: {
		CnIndexPage,
		ExportTestRunModal,
	},

	data() {
		return {
			register: 'pipelinq',
			schema: 'exportJob',
			testRunJobId: null,
			rowActions: [
				{
					id: 'test-run',
					label: t('pipelinq', 'Test run'),
					icon: 'PlayOutline',
					handler: (row) => this.testRun(row),
				},
			],

			columns: [
				{
					key: 'name',
					label: t('pipelinq', 'Job'),
					secondary: '{format} · {mode}',
					secondaryLabels: exportJobSecondaryLabels(t),
					cellClass: 'cn-cell--strong',
				},
				{ key: 'sourceSchemas', label: t('pipelinq', 'Dataset'), widget: 'badge' },
				{
					key: 'destinationId',
					label: t('pipelinq', 'Destination'),
					widget: 'fkResolve',
					widgetProps: {
						register: 'pipelinq',
						schema: 'exportDestination',
						labelField: 'name',
						secondaryTemplate: '{type}',
						secondaryLabels: { type: DESTINATION_TYPES },
					},
				},
				{
					key: 'scheduleCron',
					label: t('pipelinq', 'Schedule'),
					formatter: 'exportSchedule',
					secondary: (row) => scheduleSource(row),
				},
				{
					key: 'lastRunStatus',
					label: t('pipelinq', 'Last run'),
					widget: 'badge',
					widgetProps: { colorMap: { succeeded: 'success', partial: 'warning', failed: 'error' } },
					secondary: (row) => lastRunLine(row),
				},
				{
					key: 'nextRunAt',
					hidden: true,
					label: t('pipelinq', 'Next run'),
					formatter: 'exportMoment',
					emptyValue: t('pipelinq', 'Not scheduled'),
				},
				{
					key: 'enabled',
					label: t('pipelinq', 'Enabled'),
					widget: 'switch',
					widgetProps: {
						checked: (row) => row.enabled === true,
						onToggle: (row, on) => this.setEnabled(row, on),
						ariaLabel: (row) => t('pipelinq', 'Run {name} on its schedule', { name: row.name }),
					},
				},
			],

			quickFilters: [
				{ label: t('pipelinq', 'All'), filter: {}, default: true, showCount: true },
				{ label: t('pipelinq', 'Enabled'), filter: { enabled: true }, showCount: true },
				{ label: t('pipelinq', 'Last run failed'), filter: { lastRunStatus: 'failed' }, showCount: true },
			],
		}
	},

	computed: {
		/**
		 * The tab strip over the BI export lists (board PqBiExport).
		 *
		 * @return {Array<object>}
		 * @spec openspec/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
		 */
		pageTabs() {
			return exportPageTabs(t)
		},

		/**
		 * The header buttons in the board's order: Download and New export job
		 * beside the title, Actions at the end of the tab strip with the list
		 * actions the header takes out of the toolbar.
		 *
		 * @return {Array<object>}
		 * @spec exclude presentational header buttons of the export job list
		 */
		headerButtons() {
			return [
				{ action: 'export', label: t('pipelinq', 'Download'), icon: 'Download' },
				{
					action: 'actions-menu',
					placement: 'tabs',
					items: ['refresh', 'separator', 'copy-selected', 'delete-selected'],
				},
				{
					action: 'add',
					variant: 'primary',
					icon: 'Plus',
					label: t('pipelinq', 'New export job'),
				},
			]
		},
	},

	methods: {
		/**
		 * Navigate to a job's detail/edit form.
		 *
		 * @param {object} row The clicked row.
		 * @param {MouseEvent} [event] The row click; a modified or middle click opens a new tab.
		 * @spec exclude row navigation to the job's detail page; no requirement covers the export job list
		 */
		openJob(row, event) {
			openRowTarget(
				event,
				{ name: 'ExportJobDetail', params: { id: row.id } },
				this.$router,
			)
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
		 * Switch a job on or off from its row. Rejects when the server refuses,
		 * so the switch returns to where it was.
		 *
		 * @param {object} row The job row.
		 * @param {boolean} on Whether to enable it.
		 * @return {Promise<void>}
		 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-upload-with-retries-req-bie-008
		 */
		async setEnabled(row, on) {
			try {
				if (on) {
					await exportApi.enableJob(row.id)
					showSuccess(this.t('pipelinq', 'Job enabled'))
				} else {
					await exportApi.disableJob(row.id)
					showSuccess(this.t('pipelinq', 'Job disabled'))
				}
				await this.$refs.index?.onRefreshEvent()
			} catch (error) {
				showError(this.t('pipelinq', 'Could not change job status'))
				throw error
			}
		},
	},
}
</script>

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
			rowClickToView
			@add="createNew"
			@rowClick="openJob"
			@view="openJob">
			<template #row-actions="{ row }">
				<NcButton
					variant="tertiary"
					:disabled="busyId === row.id"
					@click.stop="testRun(row)">
					{{ t('pipelinq', 'Test run') }}
				</NcButton>
				<NcButton
					variant="tertiary"
					:disabled="busyId === row.id"
					@click.stop="toggleEnabled(row)">
					{{
						row.enabled
							? t('pipelinq', 'Disable')
							: t('pipelinq', 'Enable')
					}}
				</NcButton>
			</template>
		</CnIndexPage>
		<ExportTestRunModal
			v-if="testRunJobId"
			:jobId="testRunJobId"
			@close="testRunJobId = null" />
	</div>
</template>

<script>
import { CnIndexPage, openRowTarget } from '@conduction/nextcloud-vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { NcButton } from '@nextcloud/vue'
import ExportTestRunModal from '../../modals/ExportTestRunModal.vue'
import { exportApi } from '../../services/exportApi.js'
import { exportJobSecondaryLabels, exportPageTabs } from './exportPageTabs.js'

export default {
	name: 'ExportJobs',
	components: {
		CnIndexPage,
		NcButton,
		ExportTestRunModal,
	},

	data() {
		return {
			register: 'pipelinq',
			schema: 'exportJob',
			busyId: null,
			testRunJobId: null,
			columns: [
				{
					key: 'name',
					label: t('pipelinq', 'Job'),
					secondary: '{format} · {mode}',
					secondaryLabels: exportJobSecondaryLabels(t),
					cellClass: 'cn-cell--strong',
				},
				{ key: 'sourceSchemas', label: t('pipelinq', 'Dataset') },
				{
					key: 'destinationId',
					label: t('pipelinq', 'Destination'),
					widget: 'fkResolve',
					widgetProps: {
						register: 'pipelinq',
						schema: 'exportDestination',
						labelField: 'name',
					},
				},
				{ key: 'scheduleCron', label: t('pipelinq', 'Schedule') },
				{ key: 'enabled', label: t('pipelinq', 'Enabled') },
			],

			quickFilters: [
				{ label: t('pipelinq', 'All'), filter: {}, default: true, showCount: true },
				{ label: t('pipelinq', 'Enabled'), filter: { enabled: true }, showCount: true },
			],
		}
	},

	computed: {
		/**
		 * The tab strip over the BI export lists (board PqBiExport).
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
		 */
		pageTabs() {
			return exportPageTabs(t)
		},

		/**
		 * The header buttons in the board's order: Download, Actions, New export job.
		 *
		 * @return {Array<object>}
		 * @spec exclude presentational header buttons of the export job list
		 */
		headerButtons() {
			return [
				{ action: 'export', label: t('pipelinq', 'Download'), icon: 'Download' },
				{ action: 'actions-menu' },
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
		 * Enable or disable a job.
		 *
		 * @param {object} row The job row.
		 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-upload-with-retries-req-bie-008
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
				await this.$refs.index?.onRefreshEvent()
			} catch {
				showError(this.t('pipelinq', 'Could not change job status'))
			} finally {
				this.busyId = null
			}
		},
	},
}
</script>

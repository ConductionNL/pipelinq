<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->
<!--
  Report export card on the operational dashboard (openspec/specs/dashboard,
  REQ-DASH-020 / REQ-DASH-021), as the PqOperationeel board draws it: one line
  of explanation and a CSV and a PDF button. Both export the dashboard's
  figures for the period chosen at the top of the page: CSV as the overview
  figures with the period before, PDF through the browser's print dialog.
-->
<template>
	<div class="report-export">
		<span class="report-export__text">
			{{ t('pipelinq', 'Export these figures for the chosen period.') }}
		</span>
		<div class="report-export__buttons">
			<NcButton
				variant="secondary"
				class="report-export__button"
				:disabled="busy"
				@click="downloadCsv">
				<template #icon>
					<TrayArrowDown :size="16" />
				</template>
				CSV
			</NcButton>
			<NcButton
				variant="secondary"
				class="report-export__button"
				@click="printPdf">
				<template #icon>
					<TrayArrowDown :size="16" />
				</template>
				PDF
			</NcButton>
		</div>
		<p v-if="error" class="report-export__error" role="alert">
			{{ error }}
		</p>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import { unref } from 'vue'
import TrayArrowDown from 'vue-material-design-icons/TrayArrowDown.vue'
import { rangeToPeriod } from '../../../services/analyticsPeriod.js'
import { downloadCsv as saveCsv } from '../../../services/csvExport.js'

/**
 * The overview figures a CSV export holds, by their key in
 * GET /api/analytics/overview.
 */
const FIGURES = ['leadConversionRate', 'avgRequestResolutionTime', 'contactMomentVolume', 'customerSatisfactionScore']

/**
 * The overview payload as CSV rows: one row per figure, this period and the
 * period before.
 *
 * @param {object} overview The /api/analytics/overview payload.
 * @param {(key: string) => string} label Maps a figure key to its readable name.
 * @return {Array<Array<(string|number)>>} The rows: figure, this period, the period before.
 * @spec openspec/specs/dashboard/spec.md
 */
export function overviewRows(overview, label) {
	const previous = (overview && overview.previousPeriod) || {}
	return FIGURES.map((key) => [label(key), overview?.[key] ?? '', previous[key] ?? ''])
}

/**
 * ReportExportPanel: the dashboard's figures as a CSV or a printed PDF.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
export default {
	name: 'ReportExportPanel',
	components: {
		NcButton,
		TrayArrowDown,
	},

	inject: {
		cnDashboardDateRange: { default: null },
	},

	data() {
		return {
			busy: false,
			error: null,
		}
	},

	computed: {
		/**
		 * The analytics period of the dashboard's chosen range.
		 *
		 * @return {string} week | month | quarter | year.
		 * @spec openspec/specs/dashboard/spec.md
		 */
		period() {
			return rangeToPeriod(unref(this.cnDashboardDateRange) || null)
		},
	},

	methods: {
		/**
		 * Read the overview for the chosen period and save it as a CSV file.
		 *
		 * @spec openspec/specs/dashboard/spec.md
		 */
		async downloadCsv() {
			this.busy = true
			this.error = null
			try {
				const { data } = await axios.get(generateUrl('/apps/pipelinq/api/analytics/overview'), { params: { period: this.period } })
				const names = {
					leadConversionRate: this.t('pipelinq', 'Lead conversion'),
					avgRequestResolutionTime: this.t('pipelinq', 'Average request turnaround'),
					contactMomentVolume: this.t('pipelinq', 'Contacts'),
					customerSatisfactionScore: this.t('pipelinq', 'Customer satisfaction'),
				}
				const columns = [this.t('pipelinq', 'Figure'), this.t('pipelinq', 'This period'), this.t('pipelinq', 'The period before')]
				const rows = overviewRows(data, (key) => names[key] || key)
					.map((row) => Object.fromEntries(columns.map((column, i) => [column, row[i]])))
				saveCsv(`pipelinq-report-${this.period}.csv`, rows, columns)
			} catch {
				this.error = this.t('pipelinq', 'The report could not be exported.')
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the browser's print dialog, where the page is saved as a PDF.
		 *
		 * @spec openspec/specs/dashboard/spec.md
		 */
		printPdf() {
			window.print()
		},
	},
}
</script>

<style scoped>
.report-export {
	display: flex;
	flex-direction: column;
	gap: 14px;
	padding: 0 var(--cn-board-dashboard-card-inset, 22px) 20px;
}

.report-export__text {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.report-export__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.report-export__button.button-vue.button-vue {
	--button-padding: 14px;
	min-height: 34px;
	height: 34px;
}

.report-export__error {
	margin: 0;
	color: var(--color-error-text);
}
</style>

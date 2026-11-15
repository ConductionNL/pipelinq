/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What the three BI export lists share: the tab strip over jobs,
 * destinations and runs (board PqBiExport), and the words for a job's format
 * and mode.
 *
 * @module views/export/exportPageTabs
 */

/**
 * The tab strip above each BI export list, each tab counting its list.
 *
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {Array<{id: string, label: string, route: string, countFrom: {register: string, schema: string}}>} The tabs.
 * @spec openspec/specs/bi-export-board/spec.md#requirement-the-bi-export-lists-share-one-tab-strip
 */
export function exportPageTabs(t) {
	return [
		{ id: 'jobs', label: t('pipelinq', 'Jobs'), route: 'ExportJobs', countFrom: { register: 'pipelinq', schema: 'exportJob' } },
		{ id: 'destinations', label: t('pipelinq', 'Destinations'), route: 'ExportDestinations', countFrom: { register: 'pipelinq', schema: 'exportDestination' } },
		{ id: 'runs', label: t('pipelinq', 'Runs'), route: 'ExportRuns', countFrom: { register: 'pipelinq', schema: 'exportRun' } },
	]
}

/**
 * The words for a job's format and mode on its row ("Parquet · incrementeel").
 *
 * @param {(app: string, text: string) => string} t The translate function.
 * @return {{format: object, mode: object}} Labels by stored value.
 * @spec openspec/specs/bi-export-board/spec.md#requirement-a-job-reads-its-format-and-mode-in-words
 */
export function exportJobSecondaryLabels(t) {
	return {
		format: { csv: 'CSV', parquet: 'Parquet', jsonl: 'JSONL' },
		mode: { full: t('pipelinq', 'full'), incremental: t('pipelinq', 'incremental') },
	}
}

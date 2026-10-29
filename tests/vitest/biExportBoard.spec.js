/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BI export pages as board PqBiExport draws them: one tab strip over the
 * jobs, the destinations and the runs, each with its count; the Filter
 * button on the jobs list; a job's format and mode in words under its name
 * ("Parquet · incrementeel").
 *
 * @spec openspec/changes/bi-export-board/specs/bi-export-board/spec.md
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import en from '../../l10n/en.json'
import nl from '../../l10n/nl.json'
import register from '../../lib/Settings/register.d/40-bi-export.json'
import manifest from '../../src/manifest.json'
import { exportJobSecondaryLabels, exportPageTabs } from '../../src/views/export/exportPageTabs.js'

const identity = (_app, text) => text
const view = (name) => readFileSync(resolve(__dirname, '../../src/views/export', name), 'utf8')
const schemas = register.components.schemas

describe('the BI export pages (pl2 PqBiExport)', () => {
	const tabs = exportPageTabs(identity)

	it('share one tab strip: jobs, destinations, runs, each counted', () => {
		expect(tabs.map((tab) => [tab.label, tab.route, tab.countFrom.schema])).toEqual([
			['Jobs', 'ExportJobs', 'exportJob'],
			['Destinations', 'ExportDestinations', 'exportDestination'],
			['Runs', 'ExportRuns', 'exportRun'],
		])
		const pages = manifest.pages.map((page) => page.id)
		for (const tab of tabs) {
			expect(pages, tab.route).toContain(tab.route)
			expect(schemas[tab.countFrom.schema], tab.countFrom.schema).toBeDefined()
			expect(tab.countFrom.register).toBe('pipelinq')
		}
		for (const name of ['ExportJobs.vue', 'ExportDestinations.vue', 'ExportRuns.vue']) {
			expect(view(name), name).toContain(':pageTabs="pageTabs"')
		}
	})

	it('draws Acties at the end of the tab strip, with the list actions the header took over (pl4 PqBiExport 12/13)', () => {
		const job = view('ExportJobs.vue')
		expect(job).toMatch(/\{\s*action: 'actions-menu',\s*placement: 'tabs',\s*items: \['refresh', 'separator', 'copy-selected', 'delete-selected'\],?\s*\}/)
	})

	it('offers the Filter button on the jobs list', () => {
		expect(view('ExportJobs.vue')).toContain(':filterMenu="true"')
	})

	it('writes a job\'s format and mode in words, for every value the schema allows', () => {
		const labels = exportJobSecondaryLabels(identity)
		expect(Object.keys(labels.format).sort()).toEqual([...schemas.exportJob.properties.format.enum].sort())
		expect(Object.keys(labels.mode).sort()).toEqual([...schemas.exportJob.properties.mode.enum].sort())
		expect(view('ExportJobs.vue')).toContain('secondaryLabels: exportJobSecondaryLabels(t)')
	})

	it('shows each job\'s last run and next run from fields the job keeps, and filters on a failed last run', () => {
		const job = view('ExportJobs.vue')
		const fields = schemas.exportJob.properties
		const fragment = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/register.d/42-bi-export-job-runs.json'), 'utf8')).components.schemas.exportJob
		expect(fragment.version).toBe('1.1.0')
		for (const key of ['lastRunStatus', 'lastRunAt', 'lastRunRows', 'lastRunFiles', 'lastRunError', 'nextRunAt']) {
			expect(fragment.properties[key], key).toBeDefined()
			expect(fields[key], key).toBeUndefined()
		}
		expect(job).toContain("key: 'lastRunStatus'")
		expect(job).toContain('secondary: (row) => lastRunLine(row)')
		expect(job).toContain("key: 'nextRunAt'")
		expect(job).toContain("formatter: 'exportMoment'")
		expect(job).toContain("filter: { lastRunStatus: 'failed' }")
	})

	it('draws Enabled as a switch named per job, and drops the Enable/Disable button', () => {
		const job = view('ExportJobs.vue')
		expect(job).toContain("widget: 'switch'")
		expect(job).toContain("ariaLabel: (row) => t('pipelinq', 'Run {name} on its schedule', { name: row.name })")
		expect(job).not.toContain("t('pipelinq', 'Disable')")
	})

	it('reads the schedule in words, the datasets as chips and the destination with its type', () => {
		const job = view('ExportJobs.vue')
		expect(job).toContain("formatter: 'exportSchedule'")
		expect(job).toContain('secondary: (row) => scheduleSource(row)')
		expect(job).toMatch(/key: 'sourceSchemas',[^}]*widget: 'badge'/)
		expect(job).toContain("secondaryTemplate: '{type}'")
		const types = schemas.exportDestination.properties.type.enum
		const labels = job.slice(job.indexOf('DESTINATION_TYPES'))
		for (const type of types) {
			expect(labels, type).toContain(`${type}:`)
		}
	})

	it('has its words in English and Dutch', () => {
		const words = ['Jobs', 'Destinations', 'Runs', 'incremental', 'full']
		for (const word of words) {
			expect(en.translations[word], word).toBeTruthy()
		}
		expect(words.map((word) => nl.translations[word])).toEqual(['Taken', 'Bestemmingen', 'Uitvoeringen', 'incrementeel', 'volledig'])
	})
})

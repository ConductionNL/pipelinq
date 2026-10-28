/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The operational dashboard's own cards as the PqOperationeel board draws
 * them. The dashboard card body is flush, so each pipelinq widget carries the
 * card's 22px side inset; fields are 40px; the knowledge base card has its
 * title; the export card is a line and a CSV and PDF button; the comparison
 * tiles say what the period before was; the category ring names each category
 * with its count. Read as text.
 *
 * @spec openspec/specs/dashboard/spec.md
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const read = (path) => readFileSync(resolve(__dirname, '../../', path), 'utf8')
const manifest = JSON.parse(read('src/manifest.json'))
const page = manifest.pages.find((p) => p.id === 'OperationalDashboard')
const widget = (id) => page.config.widgets.find((w) => w.id === id)
const layout = (id) => page.config.layout.find((l) => l.widgetId === id)

const INSET = 'padding: 0 var(--cn-board-dashboard-card-inset, 22px)'

describe('the operational dashboard cards', () => {
	it('inset every pipelinq widget by the card inset', () => {
		for (const file of [
			'src/views/dashboard/widgets/MyWorkWidget.vue',
			'src/views/dashboard/widgets/NaviAnalyticsWidget.vue',
			'src/views/dashboard/widgets/ReportExportPanel.vue',
			'src/components/satisfaction/SurveyResponseRateWidget.vue',
		]) {
			expect(read(file), file).toContain(INSET)
		}
		expect(read('src/components/xwiki/XWikiWidget.vue')).toMatch(/\.xwiki-widget--bare \{[^}]*padding: 0 var\(--cn-board-dashboard-card-inset, 22px\) 20px/)
	})

	it('draws my work rows without underlines and marks them as ruled rows', () => {
		const src = read('src/views/dashboard/widgets/MyWorkWidget.vue')
		expect(src).toContain('class="my-work cn-board-ruled-rows"')
		expect(src).toContain('text-decoration: none !important')
	})

	it('lifts the question and search fields to 40px past Nextcloud\'s input height', () => {
		expect(read('src/views/dashboard/widgets/NaviAnalyticsWidget.vue')).toMatch(/\.navi-widget__input \{[^}]*min-height: 40px/)
		expect(read('src/components/xwiki/XWikiWidget.vue')).toMatch(/\.xwiki-widget__search-bar-input \{[^}]*min-height: 40px/)
	})

	it('asks Navi with a tonal pill', () => {
		const src = read('src/views/dashboard/widgets/NaviAnalyticsWidget.vue')
		expect(src).toMatch(/\.navi-widget__ask\.button-vue\.button-vue \{[^}]*background-color: var\(--color-primary-element-light\)/)
		expect(src).toMatch(/\.navi-widget__ask\.button-vue\.button-vue \{[^}]*border: 0/)
	})

	it('titles the knowledge base card', () => {
		expect(layout('xwiki-knowledge').showTitle).not.toBe(false)
	})

	it('puts the survey period label above a 40px select and says so when there is nothing', () => {
		const src = read('src/components/satisfaction/SurveyResponseRateWidget.vue')
		expect(src).toMatch(/\.survey-response-rate__period \{[^}]*flex-direction: column/)
		expect(src).toMatch(/\.survey-response-rate__period select \{[^}]*height: 40px/)
		expect(src).not.toContain('NcEmptyContent')
		expect(src).toContain('class="survey-response-rate__empty"')
	})

	it('exports with a line and a CSV and a PDF button', () => {
		const src = read('src/views/dashboard/widgets/ReportExportPanel.vue')
		expect(src).toContain("t('pipelinq', 'Export these figures for the chosen period.')")
		expect(src).toMatch(/@click="downloadCsv">[\s\S]*CSV/)
		expect(src).toMatch(/@click="printPdf">[\s\S]*PDF/)
		expect(src).not.toContain('report-export__chevron')
	})

	it('says what the period before was under each comparison tile', () => {
		expect(widget('lead-conversion').content.caption).toBe('was {previousPeriod.leadConversionRate|tile} in the period before')
		expect(widget('avg-resolution').content.caption).toBe('was {previousPeriod.avgRequestResolutionTime|tile}')
		expect(widget('contact-volume').content.caption).toBe('contact moments, was {previousPeriod.contactMomentVolume|tile}')
		for (const id of ['lead-conversion', 'avg-resolution', 'contact-volume']) {
			expect(widget(id).content.previousField, id).toBeUndefined()
		}
	})

	it('draws the category ring with its total and a legend with counts on the right', () => {
		const content = widget('requests-by-category').content
		expect(content.legendPosition).toBe('right')
		expect(content.legendValues).toBe(true)
		expect(content.options.dataLabels.enabled).toBe(false)
		expect(content.options.plotOptions.pie.donut.labels.total.show).toBe(true)
	})

	it('writes client names in the table weight', () => {
		const name = widget('client-overview').content.columns.find((c) => c.key === 'name')
		expect(name.cellClass).toBeUndefined()
	})
})

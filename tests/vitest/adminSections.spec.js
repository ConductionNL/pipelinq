/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The admin page's declared settings sections: what each one reads and
 * saves, that every text it shows has an English source and a Dutch
 * translation, and that the page mounts each one behind the right guard.
 *
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-the-admin-forms-are-declared-sections
 * @spec openspec/changes/pl-abstract-admin-sections/specs/admin-settings/spec.md#requirement-no-admin-surface-is-an-in-app-route
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import en from '../../l10n/en.json'
import nl from '../../l10n/nl.json'
import sections from '../../src/views/settings/adminSections.json'

const settings = readFileSync(resolve(__dirname, '../../src/views/settings/Settings.vue'), 'utf8')

/**
 * Every text a section shows: its name, description and each field's
 * label, hint, placeholder, pattern message, status texts and option labels.
 *
 * @param {object} section A declared section.
 * @return {Array<string>} The texts.
 */
function textsOf(section) {
	const texts = [section.name, section.description]
	for (const field of section.fields) {
		texts.push(field.label, field.hint, field.patternMessage, field.text, field.successText, field.failureText)
		texts.push(...Object.values(field.textMap || {}))
		if (field.type !== 'select') {
			texts.push(field.placeholder)
		}
		for (const option of field.options || []) {
			if (option && typeof option === 'object') {
				texts.push(option.label, option.description)
			}
		}
	}
	return texts.filter((text) => typeof text === 'string' && text !== '' && !/^[a-z0-9.@:/-]+$/.test(text))
}

describe('the declared admin sections', () => {
	it('has an English source and a Dutch translation for every text', () => {
		for (const [id, section] of Object.entries(sections)) {
			for (const text of textsOf(section)) {
				expect(en.translations[text], `${id}: ${text}`).toBe(text)
				expect(nl.translations[text], `${id}: ${text} (nl)`).toBeTruthy()
			}
		}
	})

	it('reads and saves the app settings, except the provision action; the BRP monitor lives in integriq', () => {
		for (const id of ['leadManagement', 'shillinq', 'exportConfiguration', 'marketingIntel']) {
			expect(sections[id].valuesPath, id).toBe('config')
			expect(sections[id].url, id).toBeUndefined()
		}
		expect(sections.provisionData.fields.map((field) => field.type)).toEqual(['action'])
		expect(sections.provisionData.fields[0].url).toBe('/apps/pipelinq/api/setup/action/provision-register')
		expect(sections.brpMonitor).toBeUndefined()
	})

	it('keeps the checks the hand-built forms had', () => {
		const stale = sections.leadManagement.fields[0]
		expect(stale).toMatchObject({ key: 'lead_stale_threshold_days', type: 'number', min: 1, integer: true, default: 14 })
		const exportFields = Object.fromEntries(sections.exportConfiguration.fields.map((field) => [field.key, field]))
		expect(exportFields['export.retention_days']).toMatchObject({ min: 1, default: 365 })
		expect(exportFields['export.at_risk_warning_hours']).toMatchObject({ min: 1, default: 24 })
		expect(exportFields['export.default_compression'].options).toEqual(['none', 'gzip', 'snappy', 'zstd'])
		const threshold = exportFields.export_failure_threshold
		expect(threshold).toMatchObject({ type: 'number', min: 1, max: 10, integer: true, required: true, default: 3, patternMessage: 'Enter a number from 1 to 10' })
		const inRange = new RegExp(threshold.pattern)
		expect(['1', '3', '10', String(1), String(10)].every((value) => inRange.test(value))).toBe(true)
		expect(['0', '11', '-1', '2.5', '', 'three'].some((value) => inRange.test(value))).toBe(false)
		const email = new RegExp(exportFields['export.failure_notification_email'].pattern)
		expect(email.test('alerts@example.com')).toBe(true)
		expect(email.test('alerts')).toBe(false)
	})

	it('stores the switches as the text the server reads', () => {
		const switches = [...sections.shillinq.fields, ...sections.marketingIntel.fields].filter((field) => field.type === 'switch')
		expect(switches.map((field) => field.key)).toEqual(['shillinq_time_intake_enabled', 'competitor.relevance'])
		for (const field of switches) {
			expect([field.trueValue, field.falseValue]).toEqual(['true', 'false'])
		}
	})

	it('mounts every section on the admin page behind its guard', () => {
		const guards = {
			leadManagement: 'isAdmin',
			provisionData: 'isAdmin',
			exportConfiguration: 'isAdmin',
			shillinq: 'isAdmin && shillinqInstalled',
			marketingIntel: 'isAdmin',
		}
		expect(Object.keys(guards).sort()).toEqual(Object.keys(sections).sort())
		for (const [id, guard] of Object.entries(guards)) {
			expect(settings, id).toMatch(new RegExp(`<CnSettingsFieldsSection\\s+v-if="${guard.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}"\\s+v-bind="sections\\.${id}"`))
		}
	})
})

describe('the admin surfaces that were in-app routes', () => {
	const manifest = readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8')
	const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

	it('has no BRP monitor or sync settings page, and no unreferenced pipeline manager key', () => {
		expect(manifest).not.toMatch(/"route": "\/admin\/brp-monitor"|"route": "\/sync-settings"/)
		expect(registry).not.toMatch(/BrpMonitorView|SyncSettingsView|PipelineManagerView/)
	})
})

describe('the PqBeheer labels as the board draws them', () => {
	it('saves the lead management section with "Lead-instellingen opslaan"', () => {
		expect(sections.leadManagement.saveLabel).toBe('Save lead settings')
		expect(nl.translations['Save lead settings']).toBe('Lead-instellingen opslaan')
	})

	it('calls an agent profile a medewerkerprofiel in Dutch, as the card title does', () => {
		const dutch = Object.entries(nl.translations).filter(([key]) => /agent profile/i.test(key)).map(([, value]) => value)
		expect(dutch.length).toBeGreaterThan(0)
		for (const value of dutch) {
			expect(value).not.toMatch(/agentprofiel/i)
			expect(value).toMatch(/medewerkerprofiel/i)
		}
	})
})

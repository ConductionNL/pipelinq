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

	it('reads and saves the app settings, except the sections with an endpoint of their own', () => {
		for (const id of ['leadManagement', 'shillinq', 'exportConfiguration', 'marketingIntel', 'menuStructure', 'vatRates', 'xwiki']) {
			expect(sections[id].valuesPath, id).toBe('config')
			expect(sections[id].url, id).toBeUndefined()
		}
		expect(sections.provisionData.fields.map((field) => field.type)).toEqual(['action'])
		expect(sections.provisionData.fields[0].url).toBe('/apps/pipelinq/api/setup/action/provision-register')
		expect(sections.brpMonitor.url).toBe('/apps/pipelinq/api/brp/monitor')
		expect(sections.brpMonitor.fields.every((field) => field.type === 'status')).toBe(true)
	})

	// The settings write answers success for a key it does not know, so a
	// declared key the server does not store would say "Saved." and keep nothing.
	it('saves only keys the settings write stores', () => {
		const service = readFileSync(resolve(__dirname, '../../lib/Service/SettingsService.php'), 'utf8')
		const stored = new Set([...service.matchAll(/^\t\t'([a-z0-9_.]+)'(?: =>|,)/gm)].map((match) => match[1]))
		for (const id of ['leadManagement', 'shillinq', 'exportConfiguration', 'marketingIntel', 'menuStructure', 'vatRates', 'xwiki']) {
			for (const field of sections[id].fields) {
				if (['heading', 'status', 'action'].includes(field.type)) {
					continue
				}
				const key = field.path ? field.path.split('.')[0] : field.key
				expect(stored.has(key), `${id}: ${key}`).toBe(true)
			}
		}
	})

	it('keeps the checks the hand-built forms had', () => {
		const stale = sections.leadManagement.fields[0]
		expect(stale).toMatchObject({ key: 'lead_stale_threshold_days', type: 'number', min: 1, integer: true, default: 14 })
		const exportFields = Object.fromEntries(sections.exportConfiguration.fields.map((field) => [field.key, field]))
		expect(exportFields['export.retention_days']).toMatchObject({ min: 1, default: 365 })
		expect(exportFields['export.at_risk_warning_hours']).toMatchObject({ min: 1, default: 24 })
		expect(exportFields['export.default_compression'].options).toEqual(['none', 'gzip', 'snappy', 'zstd'])
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

	it('names every certificate state of the BRP monitor in its tone', () => {
		const certificate = sections.brpMonitor.fields.find((field) => field.key === 'cert.status')
		expect(Object.keys(certificate.textMap)).toEqual(['ok', 'warning', 'critical', '*'])
		expect(certificate.toneMap).toEqual({ ok: 'success', warning: 'warning', critical: 'error' })
		const none = sections.brpMonitor.fields.find((field) => field.key === 'noCertificate')
		expect(none.visibleWhen).toEqual({ key: 'cert', empty: true })
		const empty = sections.brpMonitor.fields.find((field) => field.key === 'noLookups')
		expect(empty.visibleWhen).toEqual({ key: 'report', empty: true })
	})

	it('mounts every section on the admin page behind its guard', () => {
		const guards = {
			leadManagement: 'isAdmin',
			provisionData: 'isAdmin',
			exportConfiguration: 'isAdmin',
			shillinq: 'isAdmin && shillinqInstalled',
			marketingIntel: 'isAdmin',
			brpMonitor: 'isAdmin',
			menuStructure: 'isAdmin',
			vatRates: 'isAdmin',
			forecast: 'isConfigured',
			prospect: 'isConfigured',
			xwiki: 'isAdmin',
			posCustomer: 'isAdmin && isConfigured',
		}
		expect([...Object.keys(guards), 'cti'].sort()).toEqual(Object.keys(sections).sort())
		for (const [id, guard] of Object.entries(guards)) {
			expect(settings, id).toMatch(new RegExp(`<CnSettingsFieldsSection\\s+v-if="${guard.replace(/[&]/g, '\\$&')}"\\s+v-bind="sections\\.${id}"`))
		}
	})
})

describe('the sections with an endpoint of their own', () => {
	it('edits one key per VAT class inside the vat_rates setting, with the Dutch defaults', () => {
		expect(sections.vatRates.fields.map((field) => [field.path, field.default])).toEqual([
			['vat_rates.high', 21], ['vat_rates.low', 9], ['vat_rates.zero', 0], ['vat_rates.exempt', 0],
		])
	})

	it('reads the prospect settings where the server serves them', () => {
		const routes = readFileSync(resolve(__dirname, '../../appinfo/routes.php'), 'utf8')
		expect(routes).toContain("'url' => '/api/prospect-settings', 'verb' => 'GET'")
		expect(routes).toContain("'url' => '/api/prospect-settings', 'verb' => 'PUT'")
		expect(sections.prospect.url).toBe('/apps/pipelinq/api/prospect-settings')
		expect(sections.prospect.fields.find((field) => field.key === 'kvkApiKey').type).toBe('secret')
	})

	it('saves the POS customer lookup on its own endpoint, with at least one search field', () => {
		expect(sections.posCustomer).toMatchObject({ url: '/apps/pipelinq/api/admin/pos-customer-settings', valuesPath: 'settings', saveMethod: 'POST' })
		expect(sections.posCustomer.fields[0]).toMatchObject({ key: 'customerSearchFields', type: 'checkboxes', required: true })
	})

	it('saves the CTI configuration whole and tests the connection with the server\'s verdict', () => {
		expect(sections.cti).toMatchObject({ url: '/apps/pipelinq/api/cti/config', saveAll: true })
		const test = sections.cti.fields.find((field) => field.type === 'action')
		expect(test).toMatchObject({ method: 'GET', url: '/apps/pipelinq/api/cti/test-connection', okPath: 'ok' })
		const page = readFileSync(resolve(__dirname, '../../src/views/settings/CtiPage.vue'), 'utf8')
		expect(page).toMatch(/<div data-testid="cti-settings">\s*<CnSettingsFieldsSection v-bind="section"/)
	})

	it('shows whether xWiki is reachable only when asked, and its connection once, in Detected integrations', () => {
		expect(settings).not.toMatch(/testXwiki|xwikiStatus/)
		expect(settings).toContain('<DetectedIntegrations')
	})
})

describe('the admin surfaces that were in-app routes', () => {
	const manifest = readFileSync(resolve(__dirname, '../../src/manifest.json'), 'utf8')
	const registry = readFileSync(resolve(__dirname, '../../src/registry.js'), 'utf8')

	it('has no BRP monitor, POS customer or sync settings page, and no unreferenced pipeline manager key', () => {
		expect(manifest).not.toMatch(/"route": "\/admin\/brp-monitor"|"route": "\/sync-settings"|"route": "\/admin\/pos-customer-link"/)
		expect(registry).not.toMatch(/PosCustomerSettingsView/)
		expect(registry).not.toMatch(/BrpMonitorView|SyncSettingsView|PipelineManagerView/)
	})
})

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Boards PqGeavanceerdHub and PqVerbindingscontrole: the Advanced links
 * page and the connection audit as a board-look index.
 *
 * @spec openspec/changes/advanced-hub-and-connection-audit-board/specs/board-look/spec.md#requirement-the-connection-audit-reads-as-its-board
 * @spec openspec/changes/advanced-personal-settings-card/specs/advanced-hub/spec.md#requirement-the-advanced-page-opens-personal-settings
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import {
	auditVerdict,
	CONNECTION_AUDIT_PATH,
	createConnectionAuditHandlers,
} from '../../src/services/connectionAudit.js'

function read(file) {
	return JSON.parse(readFileSync(resolve(__dirname, '../..', file), 'utf8'))
}
const advanced = read('src/manifest.d/99-advanced.json').pages[0]
const audit = read(
	'src/manifest.d/79-marketing-search-intelligence.json',
).pages.find((page) => page.id === 'ConnectionAudit')
const nl = read('l10n/nl.json').translations

describe('the Advanced page', () => {
	it('is a links page at /advanced with the board groups in order', () => {
		expect(advanced.type).toBe('links')
		expect(advanced.route).toBe('/advanced')
		expect(Object.values(advanced.config.categories)).toEqual([
			'Reports and automation',
			'Set up the app',
			'About the app',
			'Administrator',
		])
	})

	it('tones the Flows status as the board does: green', () => {
		const flows = advanced.config.cards.find((card) => card.id === 'Flows')
		expect(flows.status).toEqual({ text: '{on} active, {off} paused', tone: 'success' })
	})

	it('puts every card in a declared group, with a target', () => {
		for (const card of advanced.config.cards) {
			expect(advanced.config.categories[card.category], card.id).toBeTruthy()
			expect(card.route || card.href || card.action, card.id).toBeTruthy()
		}
	})

	it('closes the Administrator group with personal settings, opened in the user settings dialog', () => {
		const admin = advanced.config.cards.filter((card) => card.category === 'admin')
		expect(admin.map((card) => card.id)).toEqual(['AdminSettings', 'PersonalSettings'])
		const personal = admin[1]
		expect(personal.action).toBe('user-settings')
		expect(personal.route).toBeUndefined()
		expect(personal.permission).toBeUndefined()
		expect(personal.status).toBe('Only for you')
		expect(nl[personal.label]).toBe('Persoonlijke instellingen')
		expect(nl[personal.status]).toBe('Alleen voor jou')
	})

	it('draws the panel badge in the purple tone, as the board does', () => {
		expect(advanced.config.panel.badge.tone).toBe('purple')
	})

	it('has Dutch copy for every string', () => {
		const strings = [
			advanced.title,
			advanced.config.description,
			...Object.values(advanced.config.categories),
			...advanced.config.cards.flatMap((card) => [
				card.label,
				card.description,
			]),
			advanced.config.panel.badge.label,
		]
		for (const key of strings) {
			expect(nl[key], key).toBeTruthy()
		}
	})
})

describe('the connection audit', () => {
	it('is a board index over the stored rows', () => {
		expect(audit.type).toBe('index')
		expect(audit.config.schema).toBe('socialConnection')
		expect(audit.config.columns.map((column) => column.key)).toEqual([
			'clientId',
			'counterpartHandle',
			'network',
			'weFollowThem',
			'theyFollowUs',
			'reason',
		])
		expect(audit.config.statRow).toHaveLength(4)
		expect(audit.config.quickFilters).toHaveLength(4)
	})

	it('prints an answer the network will not give as cannot be checked', () => {
		expect(auditVerdict('yes')).toBe('Yes')
		expect(auditVerdict('no')).toBe('No')
		expect(auditVerdict('unknown')).toBe('Cannot be checked')
		expect(auditVerdict('')).toBe('Cannot be checked')
	})

	it('is registered as a cell formatter', () => {
		const app = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		expect(app).toMatch(
			/createAppFormatters\(useObjectStore\(\)\), auditVerdict \}/,
		)
	})

	it('draws no row checkboxes and the board search placeholder (board PqVerbindingscontrole)', () => {
		expect(audit.config.selectable).toBe(false)
		expect(audit.config.searchPlaceholder).toBe('Search a client or username')
		expect(nl['Search a client or username']).toBe('Zoek een klant of gebruikersnaam')
	})

	it('re-runs the audit through the endpoint', async () => {
		const http = { get: vi.fn().mockResolvedValue({ data: { rows: [] } }) }
		const showSuccess = vi.fn()
		const { rerunConnectionAudit } = createConnectionAuditHandlers({
			generateUrl: (path) => path,
			http,
			showSuccess,
			showError: vi.fn(),
			translate: (_app, text) => text,
		})
		await expect(rerunConnectionAudit()).resolves.toBe(true)
		expect(http.get).toHaveBeenCalledWith(CONNECTION_AUDIT_PATH, {
			params: { refresh: 'true' },
		})
		expect(showSuccess).toHaveBeenCalled()
	})

	it('reports a failed run', async () => {
		const showError = vi.fn()
		const { rerunConnectionAudit } = createConnectionAuditHandlers({
			generateUrl: (path) => path,
			http: { get: vi.fn().mockRejectedValue(new Error('down')) },
			showSuccess: vi.fn(),
			showError,
			translate: (_app, text) => text,
		})
		await expect(rerunConnectionAudit()).resolves.toBe(false)
		expect(showError).toHaveBeenCalledWith(
			'Could not load the connection audit.',
		)
	})
})

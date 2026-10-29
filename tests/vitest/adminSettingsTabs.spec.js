/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page as tabs (boards PqBeheer, PqBeheerKoppelingen,
 * PqBeheerKassa, PqBeheerMarketing, PqBeheerPortaal): the shell draws an
 * underline tab strip, mounts only the open tab, and every section sits on
 * the tab its board draws it on.
 *
 * @spec openspec/changes/admin-settings-tabs/specs/admin-settings/spec.md#requirement-the-admin-page-splits-into-tabs
 */
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const source = readFileSync(
	resolve(__dirname, '../../src/views/settings/Settings.vue'),
	'utf8',
)
const template = source.slice(0, source.indexOf('<script>'))
const nl = JSON.parse(
	readFileSync(resolve(__dirname, '../../l10n/nl.json'), 'utf8'),
).translations

/**
 * The markup of one tab's slot.
 *
 * @param {string} id The tab id.
 * @return {string} The slot's markup.
 */
function tab(id) {
	const start = template.indexOf(`<template #tab-${id}>`)
	expect(start, id).toBeGreaterThan(-1)
	const next = template.indexOf('<template #tab-', start + 1)
	return template.slice(
		start,
		next > -1 ? next : template.indexOf('</CnAdminSettingsShell>'),
	)
}

/** @return {string} The markup of the first tab: the shell's default slot. */
function general() {
	return template.slice(
		template.indexOf('@reimportError="onReimportError">'),
		template.indexOf('<template #tab-'),
	)
}

/**
 * The tab table in the script, as plain objects.
 *
 * @return {Array<{id: string, label: string, title: string, note: string}>}
 */
function adminTabs() {
	const block = source.slice(
		source.indexOf('const ADMIN_TABS = ['),
		source.indexOf(']', source.indexOf('const ADMIN_TABS = [')) + 1,
	)
	return [
		...block.matchAll(
			/id: '([^']+)',\s*label: '([^']+)',\s*title: '([^']+)',\s*note: '([^']+)'/g,
		),
	].map(([, id, label, title, note]) => ({ id, label, title, note }))
}

describe('the admin page tabs', () => {
	it('draws an underline tab strip and mounts only the open tab', () => {
		expect(template).toMatch(/:tabs="adminTabs"/)
		expect(template).toMatch(/tabsVariant="underline"/)
		expect(template).toMatch(/\n\t\tlazyTabs\n/)
		expect(template).toMatch(/@tabChange="onTabChange"/)
	})

	it('has the seven tabs in the board order, Algemeen first', () => {
		expect(adminTabs().map((entry) => entry.id)).toEqual([
			'general',
			'connections',
			'integrations',
			'pos',
			'pos-lookup',
			'marketing',
			'portal',
		])
	})

	it('has a Dutch label, title and note for every tab', () => {
		for (const entry of adminTabs()) {
			for (const key of [entry.label, entry.title, entry.note]) {
				expect(nl[key], key).toBeTruthy()
			}
		}
		expect(nl['Point of sale']).toBe('Kassa')
		expect(nl['Pipelinq settings: point of sale']).toBe(
			'Pipelinq-instellingen: kassa',
		)
	})

	it('keeps the general sections on Algemeen', () => {
		const markup = general()
		for (const tag of [
			'<CnRegisterMapping',
			"t('pipelinq', 'Lead Sources')",
			"t('pipelinq', 'Request Channels')",
			'v-bind="sections.leadManagement"',
			'<ProductCategoryManager',
			'<MenuStructureSettings',
			'<SkillSettings',
			'<AgentProfileSettings',
			'<PipelineManager',
			'<ForecastSettings',
			'v-bind="sections.provisionData"',
			'v-bind="sections.brpMonitor"',
			"t('pipelinq', 'Support')",
		]) {
			expect(markup, tag).toContain(tag)
		}
	})

	it('puts telephony, messages, xWiki, Shillinq and the detected apps on Koppelingen', () => {
		const markup = tab('connections')
		const order = [
			'<CtiSettings',
			'<MessagingSettings',
			"t('pipelinq', 'xWiki integration')",
			'v-bind="sections.shillinq"',
			'<DetectedIntegrations',
		].map((tag) => markup.indexOf(tag))
		expect(order.every((index) => index > -1)).toBe(true)
		expect(order).toEqual([...order].sort((a, b) => a - b))
	})

	it('puts the till on Kassa in the board order', () => {
		const markup = tab('pos')
		const order = [
			'<PaymentSettingsForm',
			'<PosTenderTypeManager',
			'<VatRatesSettings',
			'<PosRoleManager',
			'<PosStaffManager',
			':href="posCustomerLookupUrl"',
		].map((tag) => markup.indexOf(tag))
		expect(order.every((index) => index > -1)).toBe(true)
		expect(order).toEqual([...order].sort((a, b) => a - b))
	})

	it('puts marketing, prospects and export on Marketing en gegevens', () => {
		const markup = tab('marketing')
		const order = [
			'v-bind="sections.marketingIntel"',
			'<MarketingTrafficSettings',
			'<DeliverabilitySettings',
			'<MailingListEmbedSettings',
			'<ProspectSettings',
			'v-bind="sections.exportConfiguration"',
			'<CnChipListManager',
		].map((tag) => markup.indexOf(tag))
		expect(order.every((index) => index > -1)).toBe(true)
		expect(order).toEqual([...order].sort((a, b) => a - b))
	})

	it('draws the shared label list last on Marketing, as board PqBeheerMarketing', () => {
		const markup = tab('marketing')
		const card = markup.slice(markup.indexOf('<CnChipListManager'))
		expect(card).toContain('v-if="isAdmin && isConfigured"')
		expect(card).toContain('schema="label"')
		expect(card).toContain(':renameUrl="labelRenameUrl"')
		expect(source).toContain("`${generateUrl('/apps/pipelinq/api/labels')}/{id}/rename`")
		expect(nl.Labels).toBe('Labels')
		expect(nl['The labels people can pick for articles, leads and clients.']).toBe(
			'De labels die mensen kunnen kiezen bij artikelen, leads en klanten.',
		)
		expect(nl['New label']).toBe('Nieuw label')
		expect(nl['Enter a name']).toBe('Naam invullen')
		expect(nl['Double-click a label to rename it. A deleted label stays on the items that already use it.']).toBe(
			'Dubbelklik op een label om het te hernoemen. Een verwijderd label blijft staan op items die het al gebruiken.',
		)
	})

	it('draws the connections on the Integraties tab, as board PqIntegrations', () => {
		expect(tab('integrations')).toContain('<CnConnectionsPanel')
		expect(tab('integrations')).toContain('app="pipelinq"')
		expect(tab('integrations')).toContain(':addUrl="integriqUrl"')
		expect(source).toContain('generateUrl(INTEGRIQ_CONNECTIONS_PATH)')
		const declared = JSON.parse(readFileSync(resolve(__dirname, '../../lib/Settings/connections.json'), 'utf8')).connections.map((c) => c.key)
		for (const key of declared) {
			expect(source).toMatch(new RegExp(`'?${key}'?: (SOCIAL|\\{ kind:)`))
		}
	})

	it('puts the till customer lookup on its own tab', () => {
		expect(tab('pos-lookup')).toContain('<PosCustomerSettings')
		expect(source).toContain("return '?tab=pos-lookup'")
	})

	it('puts the customer portal on Klantportaal', () => {
		expect(tab('portal')).toContain('<PortalSettings')
	})

	it('reads the open tab from the address the shell writes', () => {
		expect(source).toMatch(
			/new URLSearchParams\(window\.location\.search\)\.get\('tab'\)/,
		)
	})
})

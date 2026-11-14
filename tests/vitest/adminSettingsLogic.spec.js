// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin settings page's own logic, run against a plain component context:
 * tabs and breadcrumbs, the register groups, saving the configuration and the
 * xWiki settings, the xWiki status check, and the tag usage counts.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ default: { name, render: () => null } })
const nc = (name) => ({ name, render: () => null })
vi.mock('@nextcloud/vue', () => ({ NcButton: nc('NcButton'), NcLoadingIcon: nc('NcLoadingIcon'), NcNoteCard: nc('NcNoteCard'), NcSettingsSection: nc('NcSettingsSection'), NcTextField: nc('NcTextField') }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnAdminSettingsShell: nc('A'), CnChipListManager: nc('B'), CnConnectionsPanel: nc('C'), CnRegisterMapping: nc('D'), CnSettingsFieldsSection: nc('E') }))
vi.mock('../../src/store/modules/leadSources.js', () => ({ useLeadSourcesStore: () => ({}) }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/store/modules/requestChannels.js', () => ({ useRequestChannelsStore: () => ({}) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => ({}) }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => (key === 'version' ? '1.2.3' : fallback) }))
vi.mock('../../src/components/admin/AgentProfileSettings.vue', () => stub('A'))
vi.mock('../../src/components/admin/ForecastSettings.vue', () => stub('B'))
vi.mock('../../src/components/admin/PortalSettings.vue', () => stub('C'))
vi.mock('../../src/components/admin/SkillSettings.vue', () => stub('D'))
vi.mock('../../src/views/admin/PosCustomerSettings.vue', () => stub('E'))
vi.mock('../../src/views/settings/CtiEventLog.vue', () => stub('F'))
vi.mock('../../src/views/settings/CtiSettings.vue', () => stub('G'))
vi.mock('../../src/views/settings/DeliverabilitySettings.vue', () => stub('H'))
vi.mock('../../src/views/settings/DetectedIntegrations.vue', () => stub('I'))
vi.mock('../../src/views/settings/MailingListEmbedSettings.vue', () => stub('J'))
vi.mock('../../src/views/settings/MarketingTrafficSettings.vue', () => stub('K'))
vi.mock('../../src/views/settings/MenuStructureSettings.vue', () => stub('L'))
vi.mock('../../src/views/settings/MessagingSettings.vue', () => stub('M'))
vi.mock('../../src/views/settings/PaymentSettingsForm.vue', () => stub('N'))
vi.mock('../../src/views/settings/PipelineManager.vue', () => stub('O'))
vi.mock('../../src/views/settings/PosRoleManager.vue', () => stub('P'))
vi.mock('../../src/views/settings/PosStaffManager.vue', () => stub('Q'))
vi.mock('../../src/views/settings/PosTenderTypeManager.vue', () => stub('R'))
vi.mock('../../src/views/settings/ProductCategoryManager.vue', () => stub('S'))
vi.mock('../../src/views/settings/ProspectSettings.vue', () => stub('T'))
vi.mock('../../src/views/settings/TagManager.vue', () => stub('U'))
vi.mock('../../src/views/settings/ScorecardManager.vue', () => stub('Sc'))
vi.mock('../../src/views/settings/VatRatesSettings.vue', () => stub('V'))

const { default: Settings } = await import('../../src/views/settings/Settings.vue')

globalThis.t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))

/**
 * A settings page context with its computed values as getters, its methods
 * bound and its stores doubled.
 *
 * @param {object} [state] Data to set.
 * @return {object} The context.
 */
function page(state = {}) {
	const stores = {
		settingsStore: { fetchSettings: vi.fn(), saveSettings: vi.fn(), config: null, isAdmin: true },
		leadSourcesStore: { tags: ['web'], loading: false, fetchSources: vi.fn(), addSource: vi.fn(), removeSource: vi.fn(), renameSource: vi.fn() },
		requestChannelsStore: { tags: ['phone'], loading: true, fetchChannels: vi.fn(), addChannel: vi.fn(), removeChannel: vi.fn(), renameChannel: vi.fn() },
		objectStore: { objectTypeRegistry: { lead: { register: 'r', schema: 'lead' }, ticket: { register: 'r', schema: 'ticket' } } },
	}
	const ctx = { ...Settings.data(), ...state }
	for (const [name, def] of Object.entries(Settings.computed)) {
		if (name in stores) {
			Object.defineProperty(ctx, name, { get: () => stores[name], configurable: true })
		} else {
			Object.defineProperty(ctx, name, { get: () => def.call(ctx), configurable: true })
		}
	}
	for (const [name, fn] of Object.entries(Settings.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

beforeEach(() => {
	globalThis.OC = { requestToken: 'tok' }
	vi.spyOn(console, 'error').mockImplementation(() => {})
})

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
	window.history.replaceState({}, '', '/')
})

describe('tabs', () => {
	it('opens the tab named in the address, else the first', () => {
		window.history.replaceState({}, '', '/?tab=portal')
		expect(Settings.data().activeTab).toBe('portal')
		window.history.replaceState({}, '', '/?tab=nope')
		expect(Settings.data().activeTab).toBe('general')
		expect(Settings.data().appVersion).toBe('1.2.3')
	})

	it('titles the page and the breadcrumb per tab', () => {
		const p = page()
		expect(p.adminTabs.map((tab) => tab.id)).toEqual(['general', 'connections', 'integrations', 'pos', 'pos-lookup', 'marketing', 'portal'])
		expect([p.pageTitle, p.breadcrumbs.length, p.breadcrumbNote]).toEqual(['Pipelinq settings', 2, 'Set up your Pipelinq installation'])
		p.onTabChange('pos')
		expect([p.pageTitle, p.breadcrumbs.at(-1).label, p.breadcrumbNote]).toEqual(['Pipelinq settings: point of sale', 'Point of sale', 'Only when the module is on'])
		p.activeTab = 'gone'
		expect(p.openTab.id).toBe('general')
		expect(p.breadcrumbs[0].href).toContain('/settings/admin')
	})

	it('offers the links, connection kinds and register groups the tabs need', () => {
		const p = page()
		expect(p.posCustomerLookupUrl).toBe('?tab=pos-lookup')
		expect(p.labelRenameUrl).toMatch(/\/apps\/pipelinq\/api\/labels\/\{id\}\/rename$/)
		expect(p.integriqUrl).toContain('/apps/integriq/')
		expect(p.connectionKinds['social-x']).toEqual({ kind: 'Social network', icon: 'ShareVariant' })
		expect(p.registerGroups.length).toBeGreaterThan(0)
		for (const group of p.registerGroups) {
			expect(group.types.length).toBeGreaterThan(0)
			expect(Object.keys(group.types[0])).toEqual(['slug', 'label', 'description'])
		}
		expect([p.leadSourceTags, p.leadSourcesLoading, p.requestChannelTags, p.requestChannelsLoading]).toEqual([['web'], false, ['phone'], true])
	})
})

describe('configuration', () => {
	it('knows when a register is configured, and merges what a section saved', () => {
		const p = page()
		expect(p.isConfigured).toBe(false)
		p.onSectionSaved({ register: 'r1' })
		p.onSectionSaved(null)
		expect([p.config, p.isConfigured]).toEqual([{ register: 'r1' }, true])
		p.onMarketingTrafficSaved({ register: 'r2' })
		p.onMarketingTrafficSaved(null)
		expect(p.config).toEqual({ register: 'r2' })
		p.onReimported({ config: { register: 'r3' } })
		p.onReimported({})
		expect(p.config).toEqual({ register: 'r3' })
	})

	it('reloads the configuration after a failed reimport', async () => {
		const p = page()
		p.settingsStore.fetchSettings.mockResolvedValueOnce({ config: { register: 'back' } })
		await p.onReimportError()
		expect(p.config).toEqual({ register: 'back' })
		p.settingsStore.fetchSettings.mockResolvedValueOnce(null)
		await p.onReimportError()
		expect(p.config).toEqual({ register: 'back' })
	})

	it('saves the configuration and confirms it', async () => {
		const p = page()
		p.settingsStore.saveSettings.mockResolvedValueOnce({ register: 'saved' })
		await p.save({ register: 'x' })
		expect([p.config, p.message, p.messageType, p.saving]).toEqual([{ register: 'saved' }, 'Configuration saved', 'success', false])
		p.settingsStore.saveSettings.mockResolvedValueOnce(null)
		p.message = ''
		await p.save({})
		expect(p.message).toBe('')
	})

	it('passes tag changes to the lead source and request channel stores', async () => {
		const p = page()
		await p.addLeadSource('fair')
		await p.removeLeadSource('id1')
		await p.renameLeadSource('id1', 'Fair')
		await p.addRequestChannel('chat')
		await p.removeRequestChannel('id2')
		await p.renameRequestChannel('id2', 'Chat')
		expect(p.leadSourcesStore.addSource).toHaveBeenCalledWith('fair')
		expect(p.leadSourcesStore.renameSource).toHaveBeenCalledWith('id1', 'Fair')
		expect(p.requestChannelsStore.removeChannel).toHaveBeenCalledWith('id2')
		expect(p.requestChannelsStore.renameChannel).toHaveBeenCalledWith('id2', 'Chat')
		expect(p.leadSourceLabel('web')).toBeTruthy()
	})
})

describe('xWiki', () => {
	it('reports a reachable xWiki through the app', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ json: async () => ({ available: true, source: 'xwiki-app' }) })))
		const p = page()
		await p.testXwiki()
		expect(fetch.mock.calls[0][1].headers.requesttoken).toBe('tok')
		expect([p.xwikiAppInstalled, p.xwikiMessage, p.xwikiMessageType, p.testingXwiki]).toEqual([true, 'xWiki connection successful.', 'success', false])
	})

	it('warns about an unreachable xWiki and reports a failed check', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ json: async () => ({ available: false, source: 'openregister' }) })))
		const p = page()
		await p.testXwiki()
		expect([p.xwikiAppInstalled, p.xwikiMessageType]).toEqual([false, 'warning'])
		expect(p.xwikiMessage).toContain('xWiki not reachable')
		vi.stubGlobal('fetch', vi.fn(async () => { throw new Error('offline') }))
		await p.testXwiki()
		expect([p.xwikiMessage, p.xwikiMessageType]).toEqual(['offline', 'error'])
	})

	it('saves the trimmed space and a default cache time, then checks again', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ json: async () => ({ available: true }) })))
		const p = page({ config: { xwiki_default_space: ' Docs ' } })
		p.settingsStore.saveSettings.mockResolvedValueOnce({ ok: 1 })
		await p.saveXwiki()
		expect(p.settingsStore.saveSettings).toHaveBeenCalledWith({ xwiki_default_space: 'Docs', xwiki_cache_ttl: '300' })
		expect([p.config, p.xwikiMessage, p.savingXwiki]).toEqual([{ ok: 1 }, 'xWiki connection successful.', false])
		p.settingsStore.saveSettings.mockRejectedValueOnce({ response: { data: { message: 'Bad URL' } } })
		await p.saveXwiki()
		expect([p.xwikiMessage, p.xwikiMessageType]).toEqual(['Bad URL', 'error'])
		p.settingsStore.saveSettings.mockRejectedValueOnce({})
		await p.saveXwiki()
		expect(p.xwikiMessage).toBe('Failed to save xWiki settings.')
	})
})

describe('tag usage', () => {
	it('counts the leads and requests that use a tag', async () => {
		const fetchSpy = vi.fn(async () => ({ ok: true, json: async () => ({ total: 4 }) }))
		vi.stubGlobal('fetch', fetchSpy)
		const p = page()
		expect(await p.checkLeadSourceUsage('web site')).toBe(4)
		expect(fetchSpy.mock.calls[0][0]).toContain('/objects/r/lead?source=web%20site&_limit=1')
		expect(await p.checkRequestChannelUsage('phone')).toBe(4)
		expect(fetchSpy.mock.calls[1][0]).toContain('/objects/r/ticket?ticketType=request&channel=phone&_limit=1')
	})

	it('counts zero for an unknown type, a refused read or no total', async () => {
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: false })))
		const p = page()
		expect(await p.countObjectsWithField('unknown', 'f', 'v')).toBe(0)
		expect(await p.checkLeadSourceUsage('web')).toBe(0)
		vi.stubGlobal('fetch', vi.fn(async () => ({ ok: true, json: async () => ({}) })))
		expect(await p.checkLeadSourceUsage('web')).toBe(0)
	})
})

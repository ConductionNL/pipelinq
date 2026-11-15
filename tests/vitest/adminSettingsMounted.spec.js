// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page (Settings.vue), mounted with every section stubbed: on load
 * it reads the settings, the lead sources and request channels and probes
 * xWiki; the register mapping's save, the lead-source and request-channel
 * managers, the settings sections' saves and the xWiki form are wired to the
 * stores they say they are.
 *
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-settings-ui/tasks.md#task-75
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const stores = vi.hoisted(() => ({
	settings: { config: null, isAdmin: true, fetchSettings: vi.fn(), saveSettings: vi.fn() },
	leadSources: { tags: [], loading: false, fetchSources: vi.fn(), addSource: vi.fn(), removeSource: vi.fn(), renameSource: vi.fn() },
	requestChannels: { tags: [], loading: false, fetchChannels: vi.fn(), addChannel: vi.fn(), removeChannel: vi.fn(), renameChannel: vi.fn() },
	object: { objectTypeRegistry: { lead: { register: 'pipelinq', schema: 'lead' }, ticket: { register: 'pipelinq', schema: 'ticket' } } },
}))

vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => stores.settings }))
vi.mock('../../src/store/modules/leadSources.js', () => ({ useLeadSourcesStore: () => stores.leadSources }))
vi.mock('../../src/store/modules/requestChannels.js', () => ({ useRequestChannelsStore: () => stores.requestChannels }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => stores.object }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: (app, key, fallback) => fallback }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
const named = vi.hoisted(() => (name, h) => ({
	name,
	inheritAttrs: false,
	emits: ['save', 'saved', 'add', 'remove', 'rename'],
	render() {
		return h('div', { 'data-stub': name }, this.$slots.default?.())
	},
}))

vi.mock('../../src/components/admin/AgentProfileSettings.vue', async () => ({ default: named('AgentProfileSettings', (await import('vue')).h) }))
vi.mock('../../src/components/admin/ForecastSettings.vue', async () => ({ default: named('ForecastSettings', (await import('vue')).h) }))
vi.mock('../../src/components/admin/PortalSettings.vue', async () => ({ default: named('PortalSettings', (await import('vue')).h) }))
vi.mock('../../src/components/admin/SkillSettings.vue', async () => ({ default: named('SkillSettings', (await import('vue')).h) }))
vi.mock('../../src/views/admin/PosCustomerSettings.vue', async () => ({ default: named('PosCustomerSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/CtiEventLog.vue', async () => ({ default: named('CtiEventLog', (await import('vue')).h) }))
vi.mock('../../src/views/settings/CtiSettings.vue', async () => ({ default: named('CtiSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/DeliverabilitySettings.vue', async () => ({ default: named('DeliverabilitySettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/DetectedIntegrations.vue', async () => ({ default: named('DetectedIntegrations', (await import('vue')).h) }))
vi.mock('../../src/views/settings/MailingListEmbedSettings.vue', async () => ({ default: named('MailingListEmbedSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/MarketingTrafficSettings.vue', async () => ({ default: named('MarketingTrafficSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/MenuStructureSettings.vue', async () => ({ default: named('MenuStructureSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/MessagingSettings.vue', async () => ({ default: named('MessagingSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/PaymentSettingsForm.vue', async () => ({ default: named('PaymentSettingsForm', (await import('vue')).h) }))
vi.mock('../../src/views/settings/PipelineManager.vue', async () => ({ default: named('PipelineManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/PosRoleManager.vue', async () => ({ default: named('PosRoleManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/PosStaffManager.vue', async () => ({ default: named('PosStaffManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/PosTenderTypeManager.vue', async () => ({ default: named('PosTenderTypeManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/ProductCategoryManager.vue', async () => ({ default: named('ProductCategoryManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/ProspectSettings.vue', async () => ({ default: named('ProspectSettings', (await import('vue')).h) }))
vi.mock('../../src/views/settings/TagManager.vue', async () => ({ default: named('TagManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/ScorecardManager.vue', async () => ({ default: named('ScorecardManager', (await import('vue')).h) }))
vi.mock('../../src/views/settings/VatRatesSettings.vue', async () => ({ default: named('VatRatesSettings', (await import('vue')).h) }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h } = await import('vue')
	return Object.fromEntries(['CnAdminSettingsShell', 'CnChipListManager', 'CnConnectionsPanel', 'CnRegisterMapping', 'CnSettingsFieldsSection'].map((n) => [n, named(n, h)]))
})
vi.mock('@nextcloud/vue', async () => {
	const { h } = await import('vue')
	return Object.fromEntries(['NcButton', 'NcLoadingIcon', 'NcNoteCard', 'NcSettingsSection', 'NcTextField'].map((n) => [n, named(n, h)]))
})

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const { default: Settings } = await import('../../src/views/settings/Settings.vue')
const { default: TagManager } = await import('../../src/views/settings/TagManager.vue')
const { default: MarketingTrafficSettings } = await import('../../src/views/settings/MarketingTrafficSettings.vue')

/** The library's admin shell, drawing every tab's slot so all sections mount. */
const Shell = {
	name: 'CnAdminSettingsShell',
	emits: ['tabChange', 'reimported', 'reimportError'],
	render() {
		return h('div', Object.keys(this.$slots).map((name) => h('div', { 'data-slot': name }, this.$slots[name]())))
	},
}

/** A text field that keeps its label and value. */
const TextField = {
	name: 'NcTextField',
	props: ['modelValue', 'label'],
	emits: ['update:modelValue'],
	render() {
		return h('div', { 'data-label': this.label })
	},
}

/** A button that keeps its text. */
const Button = {
	name: 'NcButton',
	props: ['disabled', 'variant'],
	emits: ['click'],
	render() {
		return h('button', this.$slots.default?.())
	},
}

/**
 * Answer the xWiki status probe.
 *
 * @param {object|Error} status What the endpoint answers, or an Error to throw.
 * @return {void}
 */
function xwiki(status) {
	globalThis.fetch.mockImplementation((url) => {
		if (url.includes('/api/xwiki/status')) {
			return status instanceof Error ? Promise.reject(status) : Promise.resolve({ json: () => Promise.resolve(status) })
		}
		return Promise.resolve({ ok: true, json: () => Promise.resolve({ total: 3 }) })
	})
}

/**
 * Mount the page and let its reads settle.
 *
 * @return {Promise<object>} The wrapper.
 */
async function page() {
	const wrapper = mount(Settings, {
		global: {
			stubs: { CnAdminSettingsShell: Shell, NcTextField: TextField, NcButton: Button },
			mocks: { t: globalThis.t },
		},
	})
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	vi.clearAllMocks()
	globalThis.OC = { requestToken: 'tok' }
	globalThis.fetch = vi.fn()
	stores.settings.config = null
	stores.settings.isAdmin = true
	stores.settings.fetchSettings.mockResolvedValue({ config: { register: 'pipelinq', xwiki_default_space: ' Kennisbank ' } })
	stores.settings.saveSettings.mockResolvedValue({ register: 'pipelinq' })
	xwiki({ available: true, baseUrl: 'https://wiki.example.nl', version: '16.4', source: 'xwiki-app' })
})

afterEach(() => {
	delete globalThis.OC
})

describe('the admin page, mounted', () => {
	it('reads the settings and the two tag lists, and probes xWiki for an admin', async () => {
		const wrapper = await page()

		expect(stores.settings.fetchSettings).toHaveBeenCalledTimes(1)
		expect(stores.leadSources.fetchSources).toHaveBeenCalledTimes(1)
		expect(stores.requestChannels.fetchChannels).toHaveBeenCalledTimes(1)
		expect(globalThis.fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/xwiki/status', expect.objectContaining({ headers: expect.objectContaining({ requesttoken: 'tok' }) }))
		expect(wrapper.vm.isAdmin).toBe(true)
		expect(wrapper.text()).toContain('xWiki reachable at https://wiki.example.nl')
		expect(wrapper.text()).toContain('(version 16.4)')
	})

	it('skips the tag lists without a register and xWiki for a non-admin', async () => {
		stores.settings.fetchSettings.mockResolvedValue({ config: {} })
		stores.settings.isAdmin = false
		await page()

		expect(stores.leadSources.fetchSources).not.toHaveBeenCalled()
		expect(stores.requestChannels.fetchChannels).not.toHaveBeenCalled()
		expect(globalThis.fetch).not.toHaveBeenCalled()
	})

	it('saves the register mapping and says so', async () => {
		const wrapper = await page()

		wrapper.findComponent({ name: 'CnRegisterMapping' }).vm.$emit('save', { register: 'other' })
		await flushPromises()

		expect(stores.settings.saveSettings).toHaveBeenCalledWith({ register: 'other' })
		expect(wrapper.text()).toContain('Configuration saved')
	})

	it('wires the lead source and request channel managers to their stores', async () => {
		const wrapper = await page()
		const [sources, channels] = wrapper.findAllComponents(TagManager)

		sources.vm.$emit('add', 'Beurs')
		sources.vm.$emit('remove', 's1')
		sources.vm.$emit('rename', 's1', 'Vakbeurs')
		channels.vm.$emit('add', 'Balie')
		channels.vm.$emit('remove', 'c1')
		channels.vm.$emit('rename', 'c1', 'Loket')
		await flushPromises()

		expect(stores.leadSources.addSource).toHaveBeenCalledWith('Beurs')
		expect(stores.leadSources.removeSource).toHaveBeenCalledWith('s1')
		expect(stores.leadSources.renameSource).toHaveBeenCalledWith('s1', 'Vakbeurs')
		expect(stores.requestChannels.addChannel).toHaveBeenCalledWith('Balie')
		expect(stores.requestChannels.removeChannel).toHaveBeenCalledWith('c1')
		expect(stores.requestChannels.renameChannel).toHaveBeenCalledWith('c1', 'Loket')
	})

	it('merges what a settings section or the traffic card saved into the config', async () => {
		const wrapper = await page()

		wrapper.findAllComponents({ name: 'CnSettingsFieldsSection' })[0].vm.$emit('saved', { lead_default_stage: 'new' })
		expect(wrapper.vm.config).toMatchObject({ register: 'pipelinq', lead_default_stage: 'new' })

		wrapper.findComponent(MarketingTrafficSettings).vm.$emit('saved', { register: 'pipelinq', utm: true })
		expect(wrapper.vm.config).toEqual({ register: 'pipelinq', utm: true })
	})

	it('saves the xWiki form trimmed and re-probes, and tests the connection on request', async () => {
		const wrapper = await page()
		const field = (label) => wrapper.findAllComponents(TextField).find((c) => c.props('label') === label)
		field('Cache TTL (seconds)').vm.$emit('update:modelValue', '600')
		field('Default xWiki space').vm.$emit('update:modelValue', ' Handboek ')
		await wrapper.vm.$nextTick()
		globalThis.fetch.mockClear()

		const button = (text) => wrapper.findAllComponents(Button).find((b) => b.text().trim() === text)
		button('Save xWiki settings').vm.$emit('click')
		await flushPromises()
		expect(stores.settings.saveSettings).toHaveBeenCalledWith(expect.objectContaining({ xwiki_default_space: 'Handboek', xwiki_cache_ttl: '600' }))
		expect(globalThis.fetch).toHaveBeenCalledTimes(1)

		xwiki({ available: false, source: 'openregister' })
		button('Test connection').vm.$emit('click')
		await flushPromises()
		expect(wrapper.vm.xwikiMessageType).toBe('warning')
		expect(wrapper.text()).toContain('The optional xWiki Nextcloud app is not installed.')
	})
	it('counts the objects that use a source or channel before one is removed', async () => {
		const wrapper = await page()
		const [sources, channels] = wrapper.findAllComponents(TagManager)
		globalThis.fetch.mockClear()

		const sourceCount = await sources.vm.$attrs.usageCheck('Beurs & co')
		const channelCount = await channels.vm.$attrs.usageCheck('Balie')

		expect(globalThis.fetch.mock.calls.map((c) => c[0])).toEqual([
			'/index.php/apps/openregister/api/objects/pipelinq/lead?source=Beurs%20%26%20co&_limit=1',
			'/index.php/apps/openregister/api/objects/pipelinq/ticket?ticketType=request&channel=Balie&_limit=1',
		])
		expect([sourceCount, channelCount]).toEqual([3, 3])

		globalThis.fetch.mockResolvedValueOnce({ ok: false })
		expect(await sources.vm.$attrs.usageCheck('Beurs')).toBe(0)
		stores.object.objectTypeRegistry = {}
		expect(await sources.vm.$attrs.usageCheck('Beurs')).toBe(0)
		stores.object.objectTypeRegistry = { lead: { register: 'pipelinq', schema: 'lead' }, ticket: { register: 'pipelinq', schema: 'ticket' } }
	})
})

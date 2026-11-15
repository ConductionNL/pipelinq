// @vitest-environment jsdom
/**
 * SPDX-License-Identifier: EUPL-1.2
 * Copyright (C) 2026 Conduction B.V.
 *
 * The app shell's own logic (src/App.vue): what it provides to the pages,
 * the registry it hands CnAppRoot (the manifest registry plus the header and
 * row-action handlers), the user-settings order, the permissions it reads,
 * the sidebar component map, and the manifest-delta persistence.
 *
 * The component options are exercised directly with a stand-in `this`, the
 * way Vue calls them, so the shell's decisions are pinned without booting
 * CnAppRoot and its dependency check.
 *
 * @spec openspec/changes/archive/2026-05-31-reverse-2026-05-26-fe-app-shell/tasks.md
 */

import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const http = vi.hoisted(() => {
	const client = {
		get: vi.fn(() => Promise.resolve({ data: {} })),
		post: vi.fn(() => Promise.resolve({ data: {} })),
		put: vi.fn(() => Promise.resolve({ data: {} })),
		patch: vi.fn(() => Promise.resolve({ data: {} })),
		delete: vi.fn(() => Promise.resolve({ data: {} })),
		interceptors: { request: { use: vi.fn() }, response: { use: vi.fn() } },
	}
	client.create = () => client
	return client
})
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', async (importOriginal) => ({ ...(await importOriginal()), ...dialogs }))
vi.mock('@nextcloud/router', async (importOriginal) => ({
	...(await importOriginal()),
	generateUrl: (path) => '/index.php' + path,
}))

let App
let builtinIntegrations

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	globalThis.n = (_app, one, many, count) => (count === 1 ? one : many)
	App = (await import('../../src/App.vue')).default
	builtinIntegrations = (await import('@conduction/nextcloud-vue')).builtinIntegrations
}, 120000)

beforeEach(() => {
	http.get.mockReset()
	http.put.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

afterEach(() => {
	delete window.OC
})

/**
 * Evaluate one computed property with a stand-in component instance.
 *
 * @param {string} name The computed property.
 * @param {object} [self] The instance fields it reads.
 * @return {*} Its value.
 */
function computedOf(name, self = {}) {
	return App.computed[name].call(self)
}

describe('App shell: provide and data', () => {
	it('starts both sidebars inactive and open, with no tabs declared', () => {
		const data = App.data.call({})
		expect(data.objectSidebarState).toMatchObject({
			active: false, open: true, objectType: '', objectId: '', title: '', subtitle: '',
			register: '', schema: '', hiddenTabs: [],
		})
		expect(data.objectSidebarState.tabs).toBeUndefined()
		expect(data.sidebarState).toMatchObject({
			active: false, open: true, schema: null, visibleColumns: null, searchValue: '',
			activeFilters: {}, facetData: {}, onSearch: null, onColumnsChange: null, onFilterChange: null,
		})
	})

	it('provides its own sidebar states to the pages', () => {
		const self = { objectSidebarState: { active: true }, sidebarState: { active: false }, manifest: {} }
		const provided = App.provide.call(self)
		expect(provided.objectSidebarState).toBe(self.objectSidebarState)
		expect(provided.sidebarState).toBe(self.sidebarState)
	})

	it('provides the board look only when the manifest asks for it', () => {
		expect(App.provide.call({ manifest: { look: 'board' } }).cnLook.value).toBe('board')
		expect(App.provide.call({ manifest: { look: 'other' } }).cnLook.value).toBe('nextcloud')
		expect(App.provide.call({ manifest: {} }).cnLook.value).toBe('nextcloud')
		expect(App.provide.call({ manifest: null }).cnLook.value).toBe('nextcloud')
	})

	it('requires a manifest and defaults the registry and page types', () => {
		expect(App.props.manifest.required).toBe(true)
		expect(App.props.registry.default()).toEqual({})
		expect(App.props.pageTypes.default).toBeNull()
	})
})

describe('App shell: computed settings', () => {
	it('orders the user settings with the app sections after notifications', () => {
		expect(computedOf('userSettingsSections')).toEqual([
			'notifications', 'app', 'credentials', 'walkthrough', 'views', 'integrations',
		])
	})

	it('reads the current user permissions, none without a user', () => {
		window.OC = { currentUser: { permissions: ['admin', 'sales'] } }
		expect(computedOf('permissions')).toEqual(['admin', 'sales'])
		window.OC = {}
		expect(computedOf('permissions')).toEqual([])
		delete window.OC
		expect(computedOf('permissions')).toEqual([])
	})

	it('maps the lead column widgets to their cell components', () => {
		const widgets = computedOf('cellWidgets')
		expect(Object.keys(widgets)).toEqual(['lead-close-date', 'lead-probability', 'lead-score'])
		for (const component of Object.values(widgets)) {
			expect(component).toBeTypeOf('object')
		}
	})

	it('labels the notification rules by schema and rule', () => {
		const labels = computedOf('notificationLabels')
		expect(labels['lead.newLead']).toBe('A new lead comes in')
		expect(labels['client.clientUpdated']).toBe('A client I own changes')
	})

	it('adds the audit verdict to the app formatters', () => {
		setActivePinia(createPinia())
		const formatters = computedOf('cellFormatters')
		expect(formatters.auditVerdict).toBeTypeOf('function')
		expect(formatters.bookingCustomerName).toBeTypeOf('function')
		expect(formatters.bookingServiceName).toBeTypeOf('function')
	})

	it('resolves the sidebar tab and widget names of the built-in integrations', () => {
		const map = computedOf('sidebarComponents')
		const names = Object.keys(map)
		expect(names.length).toBeGreaterThan(0)
		for (const [name, component] of Object.entries(map)) {
			expect(component.name).toBe(name)
		}
		const withTab = builtinIntegrations.find((i) => i.tab?.name)
		expect(map[withTab.tab.name]).toBe(withTab.tab)
	})
})

describe('App shell: the registry handed to CnAppRoot', () => {
	const HANDLERS = [
		'openIntegriqConnections', 'rerunConnectionAudit', 'assignTicketToMe',
		'walkInCall', 'walkInServe', 'walkInAbandon', 'walkInCallNext',
	]

	it('keeps the manifest registry and adds every handler as a handler entry', () => {
		const page = { kind: 'page', component: {} }
		const registry = computedOf('appRegistry', { registry: { SomePage: page }, $router: {} })
		expect(registry.SomePage).toBe(page)
		for (const name of HANDLERS) {
			expect(registry[name]?.kind, name).toBe('handler')
			expect(registry[name].handler, name).toBeTypeOf('function')
		}
	})

	it('lets a shell handler win over a registry entry of the same name', () => {
		const registry = computedOf('appRegistry', { registry: { walkInCall: { kind: 'page' } }, $router: {} })
		expect(registry.walkInCall.kind).toBe('handler')
	})

	it('leaves the app for the integriq connections overview', () => {
		const assign = vi.fn()
		const original = window.location
		delete window.location
		window.location = { ...original, pathname: '/', assign }
		try {
			computedOf('appRegistry', { registry: {}, $router: {} }).openIntegriqConnections.handler()
		} finally {
			window.location = original
		}
		expect(assign).toHaveBeenCalledWith('/index.php/apps/integriq/connections?app=pipelinq&link=1')
	})

	it('re-runs the connection audit through the shell http client and toasts the result', async () => {
		http.get.mockResolvedValue({ data: {} })
		const handler = computedOf('appRegistry', { registry: {}, $router: {} }).rerunConnectionAudit.handler
		await expect(handler()).resolves.toBe(true)
		expect(http.get).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/marketing/connection-audit', {
			params: { refresh: 'true' },
		})
		expect(dialogs.showSuccess).toHaveBeenCalledWith('The connection audit ran again.')

		http.get.mockRejectedValue({ response: { data: { error: 'Nope' } } })
		await expect(handler()).resolves.toBe(false)
		expect(dialogs.showError).toHaveBeenCalledWith('Nope')
	})
})

describe('App shell: methods', () => {
	it('persists a manifest edit to buildiq\'s app-override store', async () => {
		http.put.mockResolvedValue({ data: {} })
		const delta = { pages: [{ id: 'leads', title: 'Deals' }] }
		await expect(App.methods.persistManifestDelta(delta)).resolves.toBeUndefined()
		expect(http.put).toHaveBeenCalledWith('/index.php/apps/buildiq/api/app-overrides/pipelinq', delta)
	})

	it('lets a failed save reach the editor', async () => {
		http.put.mockRejectedValue(new Error('403'))
		await expect(App.methods.persistManifestDelta({})).rejects.toThrow('403')
	})

	it('translates through the pipelinq catalogue', () => {
		window._oc_l10n_registry_translations = { pipelinq: { Deals: 'Kansen' }, other: { Leads: 'X' } }
		window._oc_l10n_registry_plural_functions = { pipelinq: (count) => (count === 1 ? 0 : 1) }
		try {
			expect(App.methods.translateForApp('Deals')).toBe('Kansen')
			expect(App.methods.translateForApp('Leads')).toBe('Leads')
		} finally {
			delete window._oc_l10n_registry_translations
			delete window._oc_l10n_registry_plural_functions
		}
	})
})

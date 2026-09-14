// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The Integrations page over integriq's connection registry, offline.
 *
 * What the browser cannot show on the shared instance, because it installs
 * integriq: that the page names Integriq as the app it requires and that the
 * menu entry hides without it. And what nothing throws about when it drifts:
 * a column naming a formatter no map provides, or a header action naming a
 * handler nobody registered, both render quietly as nothing.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-settings/spec.md#requirement-req-as-131-an-admin-reads-pipelinqs-connections-on-an-integrations-page-over-integriqs-registry
 */

import { describe, expect, it } from 'vitest'
import fragment from '../../src/manifest.d/97-connection-registry.json'
import {
	CONNECTION_STATUS_LABELS,
	createConnectionFormatters,
	createConnectionHandlers,
	INTEGRIQ_CONNECTIONS_PATH,
} from '../../src/services/connectionRegistry.js'

const page = fragment.pages.find((p) => p.id === 'Integrations')
const menu = fragment.menu.find((m) => m.id === 'ConnectionsMenu')
const echo = (key) => key
const formatters = createConnectionFormatters(echo)

describe('the Integrations page declaration', () => {
	it('reads integriq app_connection and requires integriq', () => {
		expect(page.route).toBe('/settings/integrations')
		expect(page.type).toBe('index')
		expect(page.config.register).toBe('integriq')
		expect(page.config.schema).toBe('app_connection')
		expect(page.requiresApp).toEqual({ id: 'integriq', name: 'Integriq' })
		expect(page.permission).toBe('admin')
	})

	it('offers no generic Add button, only Add integration through a registered handler', () => {
		expect(page.config.showAdd).toBe(false)
		const handlers = createConnectionHandlers({ generateUrl: echo, assign: () => {} })
		const names = page.config.headerActions.map((a) => a.handler)
		expect(names).toEqual(['openIntegriqConnections'])
		for (const name of names) {
			expect(typeof handlers[name]).toBe('function')
		}
	})

	it('names only formatters the page is given', () => {
		const named = page.config.columns.map((c) => c.formatter).filter(Boolean)
		expect(named).toEqual(['connectionStatus', 'connectionSettingsLabel'])
		for (const name of named) {
			expect(typeof formatters[name]).toBe('function')
		}
	})

	it('opens from a settings entry preset to pipelinq that hides without integriq', () => {
		expect(menu.route).toBe(page.id)
		expect(menu.query).toEqual({ app: 'pipelinq' })
		expect(menu.section).toBe('settings')
		expect(menu.permission).toBe('admin')
		expect(menu.visibleIf).toEqual({ appInstalled: 'integriq' })
	})

	it('carries no em-dash in anything a reader sees', () => {
		const visible = [
			menu.label,
			page.title,
			...page.config.columns.map((c) => c.label),
			...page.config.headerActions.map((a) => a.label),
			page.config.folderSidebar.allLabel,
		]
		for (const text of visible) {
			expect(text).not.toMatch(/—|--/)
		}
	})
})

describe('the Add integration handler', () => {
	it('leaves for integriq with this app preset and the link dialog open', () => {
		const visited = []
		const handlers = createConnectionHandlers({
			generateUrl: (path) => `/index.php${path}`,
			assign: (url) => visited.push(url),
		})

		handlers.openIntegriqConnections({ actionId: 'add-integration' })

		expect(INTEGRIQ_CONNECTIONS_PATH).toBe('/apps/integriq/connections?app=pipelinq&link=1')
		expect(visited).toEqual(['/index.php/apps/integriq/connections?app=pipelinq&link=1'])
	})
})

describe('the connection status formatter', () => {
	it('names each of the five states through the translator', () => {
		const dutch = createConnectionFormatters((key) => `nl:${key}`)
		expect(dutch.connectionStatus('configured')).toBe('nl:Configured')
		expect(formatters.connectionStatus('unconfigured')).toBe('Not configured')
		expect(formatters.connectionStatus('simulated')).toBe('Simulated')
		expect(formatters.connectionStatus('unavailable')).toBe('Not available')
		expect(formatters.connectionStatus('error')).toBe('Error')
		expect(Object.keys(CONNECTION_STATUS_LABELS)).toHaveLength(5)
	})

	it('does not let a mock adapter read as a configured connection', () => {
		expect(formatters.connectionStatus('simulated')).not.toBe(formatters.connectionStatus('configured'))
	})

	it('renders an unknown value as itself and a missing one as empty', () => {
		expect(formatters.connectionStatus('degraded')).toBe('degraded')
		expect(formatters.connectionStatus('toString')).toBe('toString')
		expect(formatters.connectionStatus(undefined)).toBe('')
		expect(formatters.connectionStatus(null)).toBe('')
	})
})

describe('the connection settings-link formatter', () => {
	it('labels a link when there is somewhere to go', () => {
		expect(formatters.connectionSettingsLabel('/settings/admin/pipelinq#section-cti')).toBe('Open settings')
	})

	it('offers nothing when the connection has no settings section', () => {
		expect(formatters.connectionSettingsLabel('')).toBe('')
		expect(formatters.connectionSettingsLabel(undefined)).toBe('')
		expect(formatters.connectionSettingsLabel(null)).toBe('')
	})
})

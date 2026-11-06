// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The customer portal admin tab as an overview of every portal on the
 * install: the portal list, the selected portal's form, adding a portal,
 * a draft status, and the latest portal actions across portals.
 *
 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const csvMock = vi.hoisted(() => ({ downloadCsv: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole),
}))
vi.mock('../../src/services/csvExport.js', () => csvMock)
vi.mock('../../src/components/admin/ServiceAccountPicker.vue', () => ({
	default: { name: 'ServiceAccountPicker', render: () => h('div') },
}))

function slot(tag) {
	return {
		props: ['modelValue', 'type', 'name', 'description', 'disabled', 'variant', 'ariaLabel'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, { onClick: () => this.$emit('click') }, [
				this.$slots.icon?.(),
				this.$slots.default?.(),
			])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcActions: { name: 'NcActions', ...slot('div') },
	NcActionButton: { name: 'NcActionButton', ...slot('button') },
	NcButton: { name: 'NcButton', ...slot('button') },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', ...slot('label') },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcNoteCard: { name: 'NcNoteCard', ...slot('div') },
	NcSettingsSection: { name: 'NcSettingsSection', ...slot('section') },
}))

const { default: PortalSettings } =
	await import('../../src/components/admin/PortalSettings.vue')

const TENANTS = [
	{ tenantId: 'oost', displayName: 'Mijn Oost', customDomain: 'mijn.oost.example', brandPrimaryColor: '#c8102e', enabledFeatures: ['requests', 'appointments', 'invoices'], status: 'live' },
	{ tenantId: 'west', displayName: 'Mijn West', customDomain: 'mijn.west.example', brandPrimaryColor: '#00707a', enabledFeatures: ['requests'], status: 'live' },
	{ tenantId: 'loket', displayName: 'Ondernemersloket', customDomain: 'loket.example', brandPrimaryColor: '#4a3f8f', enabledFeatures: ['contracts'], status: 'draft' },
]

const EVENTS = [
	{ tenantId: 'oost', eventType: 'question-answered', outcome: 'success', occurredAt: '2026-10-28T10:14:00Z', accountId: 'acc-1' },
	{ tenantId: 'loket', eventType: 'invoice-download', outcome: 'success', occurredAt: '2026-10-28T09:58:00Z', accountId: 'acc-2' },
]

function answerGets() {
	axiosMock.get.mockImplementation((url, options = {}) => {
		const params = options.params || {}
		if (url.endsWith('/portal/api/admin/tenants')) {
			return Promise.resolve({ data: { tenants: TENANTS } })
		}
		if (url.endsWith('/portal/api/admin/tenant-config')) {
			const config = TENANTS.find((tenant) => tenant.tenantId === params.tenantId)
			return Promise.resolve({ data: { config: config || { tenantId: params.tenantId }, configured: Boolean(config) } })
		}
		if (url.endsWith('/portal/api/admin/accounts')) {
			return Promise.resolve({ data: { accounts: [] } })
		}
		if (url.endsWith('/portal/api/admin/audit-events')) {
			if (params.all === '1') {
				return Promise.resolve({ data: { events: EVENTS, total: 1284 } })
			}
			return Promise.resolve({ data: { events: [] } })
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

async function mountTab() {
	const wrapper = mount(PortalSettings)
	await flushPromises()
	return wrapper
}

describe('the portal tenant manager', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		csvMock.downloadCsv.mockReset()
		answerGets()
	})

	it('lists every portal with its domain, colour, tabs in words and status', async () => {
		const wrapper = await mountTab()
		const rows = wrapper.findAll('[data-testid="portal-tenant-row"]')

		expect(rows).toHaveLength(3)
		expect(rows[0].text()).toContain('Mijn Oost')
		expect(rows[0].text()).toContain('mijn.oost.example')
		expect(rows[0].text()).toContain('#c8102e')
		expect(rows[0].text()).toContain('Requests, Appointments, Invoices')
		expect(rows[0].find('.portal-status--live').text()).toBe('Live')
		expect(rows[2].find('.portal-status--draft').text()).toBe('Draft')
		expect(wrapper.find('[data-testid="portal-tab-tenants"]').text()).toContain('3')
	})

	it('opens the first portal and opens another one from its row', async () => {
		const wrapper = await mountTab()
		expect(wrapper.find('#portal-display-name').element.value).toBe('Mijn Oost')

		await wrapper.findAll('[data-testid="portal-tenant-row"]')[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('#portal-display-name').element.value).toBe('Mijn West')
		expect(wrapper.find('#portal-custom-domain').element.value).toBe('mijn.west.example')
	})

	it('adds a portal under an id made from its name, as a draft when chosen', async () => {
		axiosMock.post.mockImplementation((url, body) => Promise.resolve({ data: body.config }))
		const wrapper = await mountTab()

		await wrapper.find('[data-testid="portal-add"]').trigger('click')
		await flushPromises()
		await wrapper.find('#portal-display-name').setValue('Mijn Test Portaal')
		await wrapper.find('#portal-status').setValue('draft')
		await wrapper.vm.save()

		const [url, body, options] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/portal/api/admin/tenant-config')
		expect(options.params.tenantId).toBe('mijn-test-portaal')
		expect(body.config.status).toBe('draft')
		expect(body.config.displayName).toBe('Mijn Test Portaal')
	})

	it('shows the latest actions of every portal with the portal name and the log total', async () => {
		const wrapper = await mountTab()
		const urls = axiosMock.get.mock.calls.map(([url, options]) => [url, options?.params?.all])
		expect(urls).toContainEqual(['/index.php/apps/pipelinq/portal/api/admin/audit-events', '1'])

		const rows = wrapper.findAll('[data-testid="portal-recent-row"]')
		expect(rows).toHaveLength(2)
		expect(rows[0].text()).toContain('Mijn Oost')
		expect(rows[1].text()).toContain('Ondernemersloket')
		expect(wrapper.find('[data-testid="portal-tab-audit"]').text()).toContain('1284')
	})

	it('downloads the shown actions as CSV', async () => {
		const wrapper = await mountTab()

		await wrapper.find('[data-testid="portal-recent-download"]').trigger('click')

		expect(csvMock.downloadCsv).toHaveBeenCalledTimes(1)
		const [filename, rows] = csvMock.downloadCsv.mock.calls[0]
		expect(filename).toMatch(/\.csv$/)
		expect(rows).toHaveLength(2)
	})
})

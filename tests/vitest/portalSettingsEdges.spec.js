// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The customer portal admin tab off its happy path: reads that fail, a new
 * portal saved without a name, every form field reaching the saved record,
 * the audit tab, the moments in the actions table, and the texts the
 * service account picker is given.
 *
 * @spec openspec/changes/portal-tenants-on-one-admin-tab/specs/customer-portal/spec.md#requirement-the-admin-tab-lists-every-portal-tenant
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole),
}))
vi.mock('../../src/services/csvExport.js', () => ({ downloadCsv: vi.fn() }))
vi.mock('../../src/components/admin/ServiceAccountPicker.vue', () => ({
	default: {
		name: 'ServiceAccountPicker',
		props: ['url', 'help', 'savedMessage'],
		render() {
			return h('div', { 'data-testid': 'picker' }, [
				h('span', { class: 'picker-help' }, this.help('pipelinq-portal-service')),
				h('span', { class: 'picker-saved' }, this.savedMessage('portal-svc')),
			])
		},
	},
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
	{ tenantId: 'oost', displayName: 'Mijn Oost', enabledFeatures: ['requests'], status: 'live' },
	{ tenantId: 'west', displayName: 'Mijn West', enabledFeatures: ['requests'], status: 'live' },
]

/**
 * Answer the admin GETs from a table of url suffix → response (or Error).
 *
 * @param {object} answers Suffix → data, or an Error to reject with.
 */
function answer(answers) {
	axiosMock.get.mockImplementation((url, options = {}) => {
		const suffix = url.replace('/index.php/apps/pipelinq/portal/api/admin/', '')
		const key = suffix === 'audit-events' && options.params?.all === '1' ? 'recent' : suffix
		const value = answers[key]
		if (value instanceof Error) {
			return Promise.reject(value)
		}
		return Promise.resolve({ data: typeof value === 'function' ? value(options.params || {}) : value })
	})
}

async function mountTab() {
	const wrapper = mount(PortalSettings)
	await flushPromises()
	return wrapper
}

describe('the portal admin tab off its happy path', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
	})

	it('opens empty with an error when every read fails', async () => {
		answer({
			tenants: new Error('down'),
			recent: new Error('down'),
			'tenant-config': new Error('down'),
			accounts: new Error('down'),
			'audit-events': new Error('down'),
		})
		const wrapper = await mountTab()

		expect(wrapper.text()).toContain('No portals yet.')
		expect(wrapper.text()).toContain('No portal actions yet.')
		expect(wrapper.find('[data-testid="portal-form"]').exists()).toBe(false)
		expect(wrapper.text()).toContain('Could not load the customer portal configuration.')
		expect(wrapper.find('[data-testid="portal-tab-audit"]').text()).toContain('0')
		const configCall = axiosMock.get.mock.calls.find(([url]) => url.endsWith('/tenant-config'))
		expect(configCall[1].params.tenantId).toBe('default')
	})

	it('says an unsaved tenant runs on the defaults', async () => {
		answer({
			tenants: { tenants: [] },
			recent: { events: [] },
			'tenant-config': { configured: false, config: { displayName: '' } },
			accounts: { accounts: [] },
			'audit-events': { events: [] },
		})
		const wrapper = await mountTab()

		expect(wrapper.text()).toContain('This tenant has no saved configuration yet.')
		expect(wrapper.find('[data-testid="portal-form"] h3').text()).toBe('New portal')
	})

	it('refuses to save a new portal without a name', async () => {
		answer({
			tenants: { tenants: TENANTS },
			recent: { events: [] },
			'tenant-config': (params) => ({ configured: true, config: TENANTS.find((tenant) => tenant.tenantId === params.tenantId) }),
			accounts: { accounts: [] },
			'audit-events': { events: [] },
		})
		const wrapper = await mountTab()

		await wrapper.find('[data-testid="portal-add"]').trigger('click')
		await wrapper.find('#portal-display-name').setValue('  -- ')
		await wrapper.vm.save()
		await flushPromises()

		expect(axiosMock.post).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('Give the portal a name first.')
		expect(wrapper.text()).not.toContain('This tenant has no saved configuration yet.')
	})

	it('saves every field of the form, the tabs and the audience', async () => {
		answer({
			tenants: { tenants: TENANTS },
			recent: { events: [] },
			'tenant-config': (params) => ({ configured: true, config: TENANTS.find((tenant) => tenant.tenantId === params.tenantId) }),
			accounts: { accounts: [] },
			'audit-events': { events: [] },
		})
		axiosMock.post.mockImplementation((url, body) => Promise.resolve({ data: body.config }))
		const wrapper = await mountTab()

		await wrapper.find('#portal-custom-domain').setValue('  mijn.oost.example ')
		await wrapper.find('#portal-primary-color').setValue('#123456')
		await wrapper.find('#portal-background-color').setValue('#fafafa')
		await wrapper.find('#portal-subdomain').setValue(' oost ')
		await wrapper.find('#portal-support-email').setValue(' help@oost.example ')
		await wrapper.find('#portal-support-phone').setValue(' 0201234567 ')
		const switches = wrapper.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		// The tab switches come first (requests … profile), then residents, then businesses.
		switches[0].vm.$emit('update:modelValue', false)
		switches[2].vm.$emit('update:modelValue', true)
		switches[8].vm.$emit('update:modelValue', false)
		switches[9].vm.$emit('update:modelValue', true)
		await wrapper.vm.save()
		await flushPromises()

		const [, body, options] = axiosMock.post.mock.calls[0]
		expect(options.params.tenantId).toBe('oost')
		expect(body.config).toMatchObject({
			customDomain: 'mijn.oost.example',
			brandPrimaryColor: '#123456',
			brandBackgroundColor: '#fafafa',
			subdomain: 'oost',
			supportEmail: 'help@oost.example',
			supportPhone: '0201234567',
			enabledFeatures: ['invoices'],
			b2cEnabled: false,
			b2bEnabled: true,
		})
		expect(wrapper.text()).toContain('Customer portal configuration saved.')
	})

	it('opens a portal from its row action without also opening it from the row', async () => {
		answer({
			tenants: { tenants: TENANTS },
			recent: { events: [] },
			'tenant-config': (params) => ({ configured: true, config: TENANTS.find((tenant) => tenant.tenantId === params.tenantId) }),
			accounts: { accounts: [] },
			'audit-events': { events: [] },
		})
		const wrapper = await mountTab()
		axiosMock.get.mockClear()

		await wrapper.findAllComponents({ name: 'NcActionButton' })[1].trigger('click')
		await flushPromises()

		expect(wrapper.find('#portal-display-name').element.value).toBe('Mijn West')
		const configCalls = axiosMock.get.mock.calls.filter(([url]) => url.endsWith('/tenant-config'))
		expect(configCalls).toHaveLength(1)
		expect(configCalls[0][1].params.tenantId).toBe('west')
	})

	it('switches to the audit tab with the tenant accounts and events, and back', async () => {
		answer({
			tenants: { tenants: TENANTS },
			recent: { events: [] },
			'tenant-config': (params) => ({ configured: true, config: TENANTS.find((tenant) => tenant.tenantId === params.tenantId) }),
			accounts: { accounts: [{ id: 'a1', displayName: 'Jan', email: 'jan@example.nl', status: 'active' }] },
			'audit-events': { events: [{ eventType: 'login', outcome: 'success', occurredAt: '2026-10-01T10:00:00Z', accountId: 'a1' }] },
		})
		const wrapper = await mountTab()

		await wrapper.find('[data-testid="portal-tab-audit"]').trigger('click')
		expect(wrapper.find('[data-testid="portal-tab-audit"]').attributes('aria-selected')).toBe('true')
		expect(wrapper.text()).toContain('jan@example.nl')
		expect(wrapper.text()).toContain('login')
		expect(wrapper.find('[data-testid="portal-form"]').exists()).toBe(false)

		await wrapper.find('[data-testid="portal-tab-tenants"]').trigger('click')
		expect(wrapper.find('[data-testid="portal-tab-tenants"]').attributes('aria-selected')).toBe('true')
		expect(wrapper.find('[data-testid="portal-form"]').exists()).toBe(true)
	})

	it('shows today as "today" and keeps a moment it cannot read as written', async () => {
		const now = new Date()
		answer({
			tenants: { tenants: TENANTS },
			recent: {
				events: [
					{ tenantId: 'oost', eventType: 'login', occurredAt: now.toISOString(), accountName: 'Jan' },
					{ tenantId: 'gone', eventType: 'login', occurredAt: 'yesterday-ish', objectId: 'obj-1' },
					{ eventType: 'logout' },
				],
				total: 3,
			},
			'tenant-config': (params) => ({ configured: true, config: TENANTS.find((tenant) => tenant.tenantId === params.tenantId) }),
			accounts: { accounts: [] },
			'audit-events': { events: [] },
		})
		const wrapper = await mountTab()
		const rows = wrapper.findAll('[data-testid="portal-recent-row"]').map((row) => row.findAll('td').map((cell) => cell.text()))

		expect(rows[0][0]).toMatch(/^today /)
		expect(rows[0].slice(1)).toEqual(['Jan', 'Mijn Oost', 'login', ''])
		expect(rows[1]).toEqual(['yesterday-ish', '', 'gone', 'login', 'obj-1'])
		expect(rows[2]).toEqual(['', '', '', 'logout', ''])
	})

	it('gives the service account picker texts that name the group and the user', async () => {
		answer({
			tenants: { tenants: [] },
			recent: { events: [] },
			'tenant-config': { configured: true, config: {} },
			accounts: { accounts: [] },
			'audit-events': { events: [] },
		})
		const wrapper = await mountTab()

		expect(wrapper.find('.picker-help').text()).toContain('It joins the group pipelinq-portal-service,')
		expect(wrapper.find('.picker-saved').text()).toBe('The portal now saves as portal-svc.')
		expect(wrapper.findComponent({ name: 'ServiceAccountPicker' }).props('url'))
			.toBe('/index.php/apps/pipelinq/portal/api/admin/service-account')
	})
})

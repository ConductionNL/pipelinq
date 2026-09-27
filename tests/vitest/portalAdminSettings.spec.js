// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The customer portal admin section (pipelinq#2041). The tenant config had an
 * admin API and no screen: an administrator could only change the portal tabs,
 * branding, domain, audience and support contacts with a hand-written POST.
 *
 * These specs mount the section with the HTTP layer replaced and prove the
 * three things the screen exists for: it reads the stored config back before
 * editing, it saves the WHOLE record (the server replaces it, so a partial
 * payload would wipe the fields the form does not show), and it lists the
 * tenant's accounts and audit events. The last spec pins the caller: the
 * admin settings page mounts the section, so it is reachable.
 *
 * @spec exclude the portal admin screen has no owning requirement; customer-portal
 *   specifies only the widget-mode origin allow-list (pipelinq#2041)
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path) => '/index.php' + path,
}))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))

function slot (tag) {
  return {
	props: ['modelValue', 'type', 'name', 'description', 'disabled', 'variant'],
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
	NcButton: { name: 'NcButton', ...slot('button') },
	NcCheckboxRadioSwitch: { name: 'NcCheckboxRadioSwitch', ...slot('label') },
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => h('span') },
	NcNoteCard: { name: 'NcNoteCard', ...slot('div') },
	NcSettingsSection: { name: 'NcSettingsSection', ...slot('section') },
}))

const { default: PortalSettings } = await import(
	'../../src/components/admin/PortalSettings.vue'
)

const storedConfig = {
	tenantId: 'default',
	displayName: 'Gemeente Voorbeeld',
	enabledFeatures: ['requests', 'documents'],
	customDomain: 'portaal.voorbeeld.nl',
	mfaEnforced: true,
	widgetAllowedOrigins: ['https://www.voorbeeld.nl'],
	b2bEnabled: false,
	b2cEnabled: true,
}

/**
 * Answer the three admin GETs the section makes on mount.
 */
function answerGets() {
	axiosMock.get.mockImplementation((url) => {
		if (url.endsWith('/portal/api/admin/tenant-config')) {
			return Promise.resolve({ data: { config: storedConfig, configured: true } })
		}
		if (url.endsWith('/portal/api/admin/accounts')) {
			return Promise.resolve({
				data: {
					accounts: [
						{ id: 'a1', email: 'jan@example.nl', displayName: 'Jan', status: 'active' },
					],
				},
			})
		}
		if (url.endsWith('/portal/api/admin/audit-events')) {
			return Promise.resolve({
				data: { events: [{ eventType: 'login', outcome: 'success', occurredAt: '2026-09-27T10:00:00Z' }] },
			})
		}
		return Promise.reject(new Error('unexpected ' + url))
	})
}

describe('PortalSettings', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.post.mockReset()
		answerGets()
	})

	it('reads the stored tenant config, accounts and audit events on mount', async () => {
		const wrapper = mount(PortalSettings)
		await flushPromises()

		const urls = axiosMock.get.mock.calls.map((call) => call[0])
		expect(urls).toContain('/index.php/apps/pipelinq/portal/api/admin/tenant-config')
		expect(wrapper.find('#portal-custom-domain').element.value).toBe('portaal.voorbeeld.nl')
		expect(wrapper.text()).toContain('jan@example.nl')
		expect(wrapper.text()).toContain('login')
	})

	it('saves the whole record, keeping fields the form does not show', async () => {
		axiosMock.post.mockImplementation((url, body) => Promise.resolve({ data: body.config }))
		const wrapper = mount(PortalSettings)
		await flushPromises()

		wrapper.vm.toggleFeature('invoices', true)
		wrapper.vm.toggleFeature('documents', false)
		await wrapper.vm.save()

		expect(axiosMock.post).toHaveBeenCalledTimes(1)
		const [url, body] = axiosMock.post.mock.calls[0]
		expect(url).toBe('/index.php/apps/pipelinq/portal/api/admin/tenant-config')
		expect(body.config.enabledFeatures).toEqual(['requests', 'invoices'])
		expect(body.config.mfaEnforced).toBe(true)
		expect(body.config.widgetAllowedOrigins).toEqual(['https://www.voorbeeld.nl'])
		expect(body.config.customDomain).toBe('portaal.voorbeeld.nl')
	})

	it('shows the server message when the save is refused', async () => {
		axiosMock.post.mockRejectedValue({
			response: { data: { message: 'Kleurcontrast is onvoldoende' } },
		})
		const wrapper = mount(PortalSettings)
		await flushPromises()

		await wrapper.vm.save()
		await flushPromises()

		expect(wrapper.text()).toContain('Kleurcontrast is onvoldoende')
	})

	it('is mounted by the admin settings page for admins', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/settings/Settings.vue'),
			'utf8',
		)
		expect(source).toMatch(/<PortalSettings v-if="isAdmin && isConfigured" \/>/)
		expect(source).toMatch(/import PortalSettings from '..\/..\/components\/admin\/PortalSettings.vue'/)
	})
})

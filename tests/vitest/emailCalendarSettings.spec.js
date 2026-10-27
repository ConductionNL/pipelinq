// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Email and calendar" section of the user settings dialog (board
 * PqSynchronisatie), mounted with axios and the Nextcloud components
 * replaced: it lists the user's own Mail accounts, saves a choice through
 * the settings endpoint, and App.vue places it in the dialog.
 *
 * @spec openspec/specs/email-calendar-sync/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('@nextcloud/vue', () => {
	const slot = (tag) => ({
		props: ['name', 'modelValue', 'value', 'type', 'label', 'helperText', 'disabled'],
		emits: ['update:modelValue', 'click', 'blur'],
		render() {
			return h(tag, {
				'data-value': this.value,
				onClick: () => {
					this.$emit('click')
					if (this.type === 'radio') this.$emit('update:modelValue', this.value)
					if (this.type === 'switch') this.$emit('update:modelValue', !this.modelValue)
				},
			}, [this.name, this.label, this.$slots.default?.()])
		},
	})
	return {
		NcAppSettingsSection: slot('section'),
		NcButton: slot('button'),
		NcCheckboxRadioSwitch: slot('label'),
		NcLoadingIcon: slot('span'),
		NcTextField: slot('div'),
	}
})

let Section

beforeAll(async () => {
	Section = (await import('../../src/components/settings/EmailCalendarSettingsSection.vue')).default
})

beforeEach(() => {
	http.get.mockReset()
	http.post.mockReset()
	dialogs.showError.mockReset()
	http.get.mockImplementation((url) => {
		if (url.endsWith('/settings')) return Promise.resolve({ data: { account: 0, enabled: false, excludedAddresses: ['noreply@zuiddrecht.nl'] } })
		if (url.endsWith('/accounts')) return Promise.resolve({ data: { accounts: [{ id: 3, email: 'p.jansen@zuiddrecht.nl', name: 'Persoonlijk' }, { id: 4, email: 'klantcontact@zuiddrecht.nl', name: '' }] } })
		return Promise.resolve({ data: { lastRunAt: null, linked: 0, scanned: 0, error: null } })
	})
	http.post.mockImplementation((url, body) => Promise.resolve({ data: body }))
})

const global = { mixins: [{ methods: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) } }] }

describe('EmailCalendarSettingsSection', () => {
	it('lists the user\'s own Mail accounts', async () => {
		const w = mount(Section, { global })
		await flushPromises()
		const accounts = w.find('[data-testid="email-sync-accounts"]')
		expect(accounts.text()).toContain('p.jansen@zuiddrecht.nl')
		expect(accounts.text()).toContain('klantcontact@zuiddrecht.nl')
		expect(w.text()).toContain('Never')
	})

	it('saves the chosen account with the skipped addresses', async () => {
		const w = mount(Section, { global })
		await flushPromises()
		await w.find('[data-value="4"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/sync/email/settings', {
			account: 4,
			enabled: false,
			excludedAddresses: ['noreply@zuiddrecht.nl'],
		})
	})

	it('picks the only account when sync is turned on', async () => {
		http.get.mockImplementation((url) => {
			if (url.endsWith('/accounts')) return Promise.resolve({ data: { accounts: [{ id: 3, email: 'p.jansen@zuiddrecht.nl', name: '' }] } })
			return Promise.resolve({ data: {} })
		})
		const w = mount(Section, { global })
		await flushPromises()
		await w.find('[data-testid="email-sync-enabled"]').trigger('click')
		await flushPromises()
		expect(http.post.mock.calls[0][1]).toMatchObject({ account: 3, enabled: true })
	})

	it('says so when the user has no Mail account', async () => {
		http.get.mockImplementation((url) => Promise.resolve({ data: url.endsWith('/accounts') ? { accounts: [] } : {} }))
		const w = mount(Section, { global })
		await flushPromises()
		expect(w.find('[data-testid="email-sync-no-accounts"]').exists()).toBe(true)
	})

	it('is placed in the user settings dialog between Notifications and Credentials', () => {
		const app = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		expect(app).toMatch(/<template #user-settings-sections>\s*<ContactsSyncSettingsSection \/>\s*<EmailCalendarSettingsSection \/>/)
		expect(app).toContain("['notifications', 'app', 'credentials', 'walkthrough', 'views', 'integrations']")
	})
})

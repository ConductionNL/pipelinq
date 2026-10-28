// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The service account picker, shared by the customer portal settings and the
 * messaging settings. It reads the account in use, warns while there is none,
 * and saves the one an admin chooses. The last spec pins both callers.
 *
 * @spec exclude shared admin control for the portal and messaging service accounts;
 *   no requirement owns the acting identity
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) =>
		text.replace(/\{(\w+)\}/g, (match, key) => vars[key] ?? match),
}))
vi.mock('../../src/services/userDisplayName.js', () => ({
	listAssignableUserIds: vi.fn(async () => ['sms-bot', 'pipelinq-berichten']),
}))
vi.mock('@nextcloud/vue', () => ({
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'inputLabel', 'inputId', 'disabled'],
		emits: ['update:modelValue'],
		render() {
			return h('div', {
				'data-stub': 'NcSelect',
				'data-label': this.inputLabel,
				'data-value': this.modelValue,
				'data-options': (this.options || []).join(','),
			})
		},
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['type'],
		render() {
			return h('div', { class: 'note-' + this.type }, this.$slots.default?.())
		},
	},
}))

/**
 * Choose an account in the select, as an admin does.
 *
 * @param {object} wrapper The picker.
 * @param {string} userId The account.
 * @return {Promise<void>}
 */
async function choose(wrapper, userId) {
	wrapper.findComponent({ name: 'NcSelect' }).vm.$emit('update:modelValue', userId)
	await flushPromises()
}

const { default: ServiceAccountPicker } =
	await import('../../src/components/admin/ServiceAccountPicker.vue')

function picker() {
	return mount(ServiceAccountPicker, {
		props: {
			url: '/index.php/apps/pipelinq/api/messaging/service-account',
			inputId: 'messaging-service-account',
			legend: 'SMS and WhatsApp service account',
			help: (group) => 'Saved as this account, in ' + group + '.',
			consequence: 'Until you choose one, nothing is saved.',
			savedMessage: (user) => 'Now saved as ' + user + '.',
			loadError: 'Could not load.',
			saveError: 'Could not save.',
			defaultGroup: 'pipelinq-messaging-service',
		},
	})
}

describe('ServiceAccountPicker', () => {
	beforeEach(() => {
		axiosMock.get.mockReset()
		axiosMock.put.mockReset()
	})

	it('warns while no account is chosen and says what stops working', async () => {
		axiosMock.get.mockResolvedValue({
			data: {
				userId: '',
				usable: false,
				reason: 'unset',
				group: 'pipelinq-messaging-service',
			},
		})

		const wrapper = picker()
		await flushPromises()

		expect(axiosMock.get).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/messaging/service-account',
		)
		expect(wrapper.find('.note-warning').text()).toBe(
			'No account is chosen. Until you choose one, nothing is saved.',
		)
		expect(wrapper.text()).toContain('in pipelinq-messaging-service.')
	})

	it('offers the accounts in a select labelled as the board draws it', async () => {
		axiosMock.get.mockResolvedValue({
			data: { userId: 'pipelinq-berichten', usable: true, reason: null },
		})

		const wrapper = picker()
		await flushPromises()

		const select = wrapper.get('[data-stub="NcSelect"]')
		expect(select.attributes('data-label')).toBe('SMS and WhatsApp service account')
		expect(select.attributes('data-value')).toBe('pipelinq-berichten')
		expect(select.attributes('data-options')).toBe('sms-bot,pipelinq-berichten')
		expect(wrapper.find('legend').exists()).toBe(false)
	})

	it('says which account is in use as soon as it loads', async () => {
		axiosMock.get.mockResolvedValue({
			data: { userId: 'pipelinq-berichten', usable: true, reason: null },
		})

		const wrapper = picker()
		await flushPromises()

		expect(wrapper.find('.note-success').text()).toBe('Now saved as pipelinq-berichten.')
		expect(wrapper.find('.note-warning').exists()).toBe(false)
	})

	it('saves the chosen account and confirms it', async () => {
		axiosMock.get.mockResolvedValue({
			data: { userId: '', usable: false, reason: 'unset' },
		})
		axiosMock.put.mockResolvedValue({
			data: {
				userId: 'sms-bot',
				usable: true,
				reason: null,
				group: 'pipelinq-messaging-service',
			},
		})

		const wrapper = picker()
		await flushPromises()
		await choose(wrapper, 'sms-bot')

		expect(axiosMock.put).toHaveBeenCalledWith(
			'/index.php/apps/pipelinq/api/messaging/service-account',
			{ userId: 'sms-bot' },
		)
		expect(wrapper.find('.note-warning').exists()).toBe(false)
		expect(wrapper.find('.note-success').text()).toBe('Now saved as sms-bot.')
	})

	it('shows the server refusal when the account cannot be used', async () => {
		axiosMock.get.mockResolvedValue({
			data: { userId: '', usable: false, reason: 'unset' },
		})
		axiosMock.put.mockRejectedValue({
			response: { data: { message: 'No account "ghost" exists.' } },
		})

		const wrapper = picker()
		await flushPromises()
		await choose(wrapper, 'ghost')

		expect(wrapper.find('.note-error').text()).toBe('No account "ghost" exists.')
	})

	it('is mounted by the messaging settings and the portal settings', () => {
		const messaging = readFileSync(
			resolve(__dirname, '../../src/views/settings/MessagingSettings.vue'),
			'utf8',
		)
		const portal = readFileSync(
			resolve(__dirname, '../../src/components/admin/PortalSettings.vue'),
			'utf8',
		)

		expect(messaging).toContain('<ServiceAccountPicker')
		expect(messaging).toContain("'/apps/pipelinq/api/messaging/service-account'")
		expect(portal).toContain('<ServiceAccountPicker')
		expect(portal).toContain("adminUrl('service-account')")
	})
})

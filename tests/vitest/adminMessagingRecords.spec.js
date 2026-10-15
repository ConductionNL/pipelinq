// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The messaging and mail transport sections of the admin page list their
 * records as rows of facts that wrap inside the card, not as wide tables that
 * scroll sideways. Every value a table column showed is still on the row,
 * under its own label.
 *
 * @spec openspec/changes/outbound-messaging-provider-wiring/tasks.md#task-4.1
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const storeMock = vi.hoisted(() => ({
	fetchCollection: vi.fn(),
	saveObject: vi.fn(),
	deleteObject: vi.fn(),
}))

vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php'
		+ path.replace(/\{(\w+)\}/g, (whole, key) => params[key] ?? whole),
}))
vi.mock('../../src/store/modules/object.js', () => ({
	useObjectStore: () => storeMock,
}))
vi.mock('../../src/components/admin/ServiceAccountPicker.vue', () => ({
	default: { name: 'ServiceAccountPicker', render: () => h('div') },
}))
vi.mock('../../src/dialogs/ConfirmDialog.vue', () => ({
	default: { name: 'ConfirmDialog', render: () => h('div') },
}))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'label', 'inputLabel', 'name'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(
				tag,
				{ 'data-stub': name, 'data-label': this.inputLabel || this.label },
				[this.name, this.$slots.default?.()],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcEmptyContent: stub('NcEmptyContent'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
	NcTextField: stub('NcTextField'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const mountOptions = { global: { mocks: { t: globalThis.t } } }

const { default: MessagingSettings } =
	await import('../../src/views/settings/MessagingSettings.vue')
const { default: DeliverabilitySettings } =
	await import('../../src/views/settings/DeliverabilitySettings.vue')

const collections = {
	channelProvider: [
		{
			id: 'p1',
			displayName: 'Twilio SMS (NL)',
			kind: 'sms',
			vendor: 'twilio',
			sourceId: 'twilio-sms',
			phoneNumber: '+31600000000',
			priority: 5,
			active: true,
			sandbox: true,
		},
	],
	messageSendBudget: [
		{
			id: 'b1',
			providerId: 'p1',
			period: 'monthly',
			currentPeriodMessages: 12,
			currentPeriodCostEur: 3.5,
		},
	],
	messageTemplate: [
		{
			id: 'm1',
			providerId: 'p1',
			externalId: 'welcome_v2',
			language: 'nl',
			status: 'approved',
			lastSyncedAt: '',
		},
	],
	mailTransport: [
		{
			id: 't1',
			displayName: 'Instance mail server',
			kind: 'instance',
			senderDomain: 'gemeente.example.nl',
			active: true,
			default: true,
			dkimVerified: true,
			dmarcStatus: 'found',
		},
	],
}

beforeEach(() => {
	storeMock.fetchCollection.mockReset()
	storeMock.fetchCollection.mockImplementation(async (type) => collections[type])
})

/**
 * The facts of one record as {label: text}.
 *
 * @param {object} record The record wrapper.
 * @return {object} Each dt's text mapped to its dd's text.
 */
function factsOf(record) {
	const facts = {}
	for (const pair of record.findAll('dl > div')) {
		facts[pair.find('dt').text()] = pair.find('dd').text()
	}
	return facts
}

describe('messaging settings', () => {
	it('lists a provider with every value its table row showed', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		expect(wrapper.find('table').exists()).toBe(false)
		const provider = wrapper.get('[data-testid="messaging-provider"]')
		expect(provider.get('h3').text()).toBe('Twilio SMS (NL)')
		expect(provider.text()).toContain('Active')
		expect(provider.text()).toContain('Sandbox')
		expect(factsOf(provider)).toMatchObject({
			Kind: 'SMS',
			Vendor: 'Twilio',
			Source: 'twilio-sms',
			'Phone / account': '+31600000000',
			Priority: '5',
		})
		expect(factsOf(provider)['Webhook URL']).toContain(
			'/index.php/apps/pipelinq/api/messaging-webhooks/sms/p1',
		)
		const actions = provider
			.findAll('.messaging-settings__record-actions button')
			.map((b) => b.text())
		expect(actions).toEqual(['Test connection', 'Edit', 'Delete'])
	})

	it('lists a send budget with its fields, usage and actions', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		const budget = wrapper.get('[data-testid="messaging-budget"]')
		expect(budget.get('h3').text()).toBe('Twilio SMS (NL)')
		expect(factsOf(budget)['Used this period']).toMatch(/12\s+msgs \/ €3\.50/)
		const fields = budget
			.findAll('.messaging-settings__fields > [data-stub]')
			.map((f) => f.attributes('data-label') || f.text())
		expect(fields).toEqual([
			'Period',
			'Max messages',
			'Max cost (EUR)',
			'Alert threshold (0-1)',
			'Hard stop',
		])
		const actions = budget
			.findAll('.messaging-settings__record-actions button')
			.map((b) => b.text())
		expect(actions).toEqual(['Save', 'Delete'])
	})

	it('lists a synced template with provider, language, status and sync time', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		const template = wrapper.get('[data-testid="messaging-template"]')
		expect(template.get('h3').text()).toBe('welcome_v2')
		expect(template.text()).toContain('Approved')
		expect(factsOf(template)).toEqual({
			Provider: 'Twilio SMS (NL)',
			Language: 'nl',
			'Last synced': '—',
		})
	})
})

describe('deliverability settings', () => {
	it('lists a mail transport with kind, domain, verdict and its controls', async () => {
		const wrapper = mount(DeliverabilitySettings, mountOptions)
		await flushPromises()

		expect(wrapper.find('table').exists()).toBe(false)
		const transport = wrapper.get('[data-testid="mail-transport"]')
		expect(transport.get('h3').text()).toBe('Instance mail server')
		const facts = factsOf(transport)
		expect(facts['Sender domain']).toBe('gemeente.example.nl')
		expect(facts.Deliverability).toContain('DKIM found')
		expect(Object.keys(facts)).toEqual([
			'Kind',
			'Sender domain',
			'Deliverability',
		])
		const controls = transport
			.findAll('.deliverability-settings__record-actions > *')
			.map((c) => c.text())
		expect(controls).toEqual(['Active', 'Default', 'Check now'])
	})
})

describe('no sideways scrolling', () => {
	it.each([
		'src/views/settings/MessagingSettings.vue',
		'src/views/settings/DeliverabilitySettings.vue',
	])('%s draws no horizontal scroller', (file) => {
		const source = readFileSync(resolve(__dirname, '../..', file), 'utf8')
		expect(source).not.toMatch(/overflow-x:\s*auto/)
		expect(source).not.toContain('<table')
	})
})

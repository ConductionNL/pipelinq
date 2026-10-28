// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The messaging section of the admin page lists providers, budgets and
 * templates as tables that fit the card, as the PqBeheerKoppelingen board
 * draws them; the mail transports list their records as rows of facts.
 * Neither scrolls sideways.
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

vi.mock('@conduction/nextcloud-vue', () => ({
	CnSettingsSection: {
		name: 'CnSettingsSection',
		props: ['name', 'description'],
		render() {
			return h('section', { 'data-stub': 'CnSettingsSection' }, [
				this.$slots.actions?.(),
				this.$slots.default?.(),
			])
		},
	},
}))

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
 * The cell texts of one table row.
 *
 * @param {object} row The row wrapper.
 * @return {Array<string>} One text per cell.
 */
function cellsOf(row) {
	return row.findAll('td').map((cell) => cell.text().replace(/\s+/g, ' ').trim())
}

/**
 * The column heads of a table.
 *
 * @param {object} table The table wrapper.
 * @return {Array<string>} One text per head.
 */
function headsOf(table) {
	return table.findAll('thead th').map((cell) => cell.text().trim())
}

describe('messaging settings', () => {
	it('is one card: the service account, then providers, budgets and templates under hairlines', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		const sections = wrapper.findAll('section[data-stub="NcSettingsSection"]')
		expect(sections).toHaveLength(1)
		const heads = sections[0].findAll('h3').map((h3) => h3.text())
		expect(heads).toEqual(['Providers', 'Send budgets', 'Templates'])
		expect(sections[0].findAll('hr')).toHaveLength(3)
	})

	it('lists a provider as a table row with every value it showed', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		const table = wrapper.get('[data-testid="messaging-providers"]')
		expect(headsOf(table)).toEqual([
			'Name',
			'Kind',
			'Vendor',
			'Source',
			'Phone / account',
			'Priority',
			'Status',
			'Webhook URL',
			'Actions',
		])
		const row = wrapper.get('[data-testid="messaging-provider"]')
		const cells = cellsOf(row)
		expect(cells.slice(0, 6)).toEqual([
			'Twilio SMS (NL)',
			'SMS',
			'Twilio',
			'twilio-sms',
			'+31600000000',
			'5',
		])
		expect(cells[6]).toContain('Active')
		expect(cells[6]).toContain('Sandbox')
		expect(row.get('.messaging-settings__webhook-url').attributes('title')).toContain(
			'/index.php/apps/pipelinq/api/messaging-webhooks/sms/p1',
		)
		expect(row.findAll('td:last-child button').map((b) => b.text())).toEqual([
			'',
			'Test connection',
			'Edit',
			'Delete',
		])
		expect(row.findAllComponents({ name: 'NcButton' })[0].vm.$attrs['aria-label']).toBe(
			'Copy webhook URL',
		)
	})

	it('lists a send budget as a table row with its inputs, usage and actions', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		const table = wrapper.get('[data-testid="messaging-budgets"]')
		expect(headsOf(table)).toEqual([
			'Provider',
			'Period',
			'Max messages',
			'Max cost (EUR)',
			'Alert threshold (0-1)',
			'Hard stop',
			'Used this period',
			'Actions',
		])
		const row = wrapper.get('[data-testid="messaging-budget"]')
		expect(cellsOf(row)[0]).toBe('Twilio SMS (NL)')
		expect(row.findAll('input[type="number"]').map((i) => i.attributes('aria-label'))).toEqual([
			'Max messages',
			'Max cost (EUR)',
			'Alert threshold (0-1)',
		])
		expect(cellsOf(row)[6]).toMatch(/12\s+msgs \/ €3\.50/)
		expect(row.findAll('td:last-child button').map((b) => b.text())).toEqual(['Save', 'Delete'])
	})

	it('lists a synced template as a table row with provider, id, language, status and sync time', async () => {
		const wrapper = mount(MessagingSettings, mountOptions)
		await flushPromises()

		const row = wrapper.get('[data-testid="messaging-template"]')
		expect(cellsOf(row)).toEqual(['Twilio SMS (NL)', 'welcome_v2', 'nl', 'Approved', '—'])
	})

	it('puts icons on Refresh and the add buttons', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/settings/MessagingSettings.vue'),
			'utf8',
		)
		expect(source.match(/<Refresh :size/g) || []).toHaveLength(3)
		expect(source.match(/<Plus :size/g) || []).toHaveLength(2)
	})
})

describe('deliverability settings', () => {
	it('lists a mail transport as a table row with domain, verdict, default and controls', async () => {
		const wrapper = mount(DeliverabilitySettings, mountOptions)
		await flushPromises()

		const table = wrapper.get('[data-testid="mail-transports"]')
		expect(headsOf(table)).toEqual([
			'Name',
			'Kind',
			'Sender domain',
			'Deliverability',
			'Default',
			'Active',
			'Actions',
		])
		const row = wrapper.get('[data-testid="mail-transport"]')
		const cells = cellsOf(row)
		expect(cells[0]).toBe('Instance mail server')
		expect(cells[2]).toBe('gemeente.example.nl')
		expect(cells[3]).toContain('DKIM found')
		expect(row.get('.cn-status-badge--success').text()).toBe('DKIM found')
		expect(cells[4]).toBe('Default')
		expect(row.findAll('td:last-child button').map((b) => b.text())).toEqual(['Check now'])
	})

	it('offers Make default as a button on a transport that is not the default', async () => {
		storeMock.fetchCollection.mockImplementation(async (type) =>
			type === 'mailTransport'
				? [{ ...collections.mailTransport[0], id: 't2', default: false, dkimVerified: false }]
				: collections[type],
		)
		const wrapper = mount(DeliverabilitySettings, mountOptions)
		await flushPromises()

		const row = wrapper.get('[data-testid="mail-transport"]')
		expect(cellsOf(row)[4]).toBe('Make default')
		expect(row.get('.cn-status-badge--error').text()).toBe('DKIM missing')
	})

	it('puts Refresh in the title row as the tonal button with its icon', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/settings/DeliverabilitySettings.vue'),
			'utf8',
		)
		expect(source).toMatch(/<template #actions>[\s\S]*?class="cn-button--tonal"[\s\S]*?<Refresh :size/)
	})
})

describe('no sideways scrolling', () => {
	it.each([
		'src/views/settings/MessagingSettings.vue',
		'src/views/settings/DeliverabilitySettings.vue',
	])('%s draws no horizontal scroller', (file) => {
		const source = readFileSync(resolve(__dirname, '../..', file), 'utf8')
		expect(source).not.toMatch(/overflow-x:\s*auto/)
	})
})

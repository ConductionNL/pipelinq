// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page shows stored values in the reader's language: the DMARC
 * verdict and transport kind, the POS role permission labels and the CTI
 * platform and event type. Stored values stay as they are; an unknown value
 * shows as stored.
 *
 * @spec openspec/changes/marketing-mail-transports/specs/marketing-mail-transports/spec.md#requirement-the-deliverability-panel-shows-spf-dkim-and-dmarc-status-per-sender-domain
 * @spec openspec/changes/cti-screenpop-adapter/tasks.md#task-5.6
 * @spec openspec/changes/pos-staff-pin-permissions/tasks.md#8.1
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { dmarcVerdictText, kindLabel } from '../../src/utils/deliverabilityLabels.js'
import {
	CTI_EVENT_TYPE_LABELS,
	CTI_PLATFORM_LABELS,
	enumLabel,
} from '../../src/utils/enumLabels.js'

const ROOT = path.resolve(__dirname, '../..')
function readJson(...parts) {
	return JSON.parse(fs.readFileSync(path.join(ROOT, ...parts), 'utf8'))
}

const EN = readJson('l10n', 'en.json').translations
const NL = readJson('l10n', 'nl.json').translations

/**
 * Deep-merge like ConfigFileLoaderService::deepMergeConfig.
 *
 * @param {object} base The base.
 * @param {object} override The fragment.
 * @return {object} The base, merged in place.
 */
function merge(base, override) {
	const isObject = (v) => v && typeof v === 'object' && !Array.isArray(v)
	for (const [key, value] of Object.entries(override)) {
		if (key === '__proto__' || key === 'constructor' || key === 'prototype') {
			continue
		}
		if (isObject(value) && isObject(base[key])) {
			merge(base[key], value)
		} else {
			base[key] = value
		}
	}
	return base
}

const register = readJson('lib', 'Settings', 'pipelinq_register.json')
fs.readdirSync(path.join(ROOT, 'lib', 'Settings', 'register.d'))
	.filter((name) => name.endsWith('.json'))
	.sort()
	.forEach((name) =>
		merge(register, readJson('lib', 'Settings', 'register.d', name)),
	)
const schemas = register.components.schemas

/**
 * Assert an English source string has its catalogue entry and a Dutch text.
 *
 * @param {string} label The English source string.
 */
function expectDutch(label) {
	expect(EN[label], label).toBe(label)
	expect(NL[label], label).toBeTruthy()
	expect(NL[label], label).not.toBe(label)
}

describe('deliverability verdicts and kinds', () => {
	it('has a Dutch text for every DMARC verdict and transport kind', () => {
		for (const status of ['found', 'missing', 'invalid', 'unknown']) {
			expectDutch(dmarcVerdictText(status))
		}
		for (const kind of ['instance', 'mailAccount', 'provider']) {
			expectDutch(kindLabel(kind))
		}
	})
})

describe('POS role permission labels', () => {
	it('has a Dutch text for every permission field title', () => {
		const props = schemas.posRole.properties
		for (const key of [
			'canVoid',
			'maxDiscountPercent',
			'canRefund',
			'canNoSale',
		]) {
			expectDutch(props[key].title)
		}
	})

	it('lets the admin entry hand the app catalogue to the library', () => {
		const source = fs.readFileSync(path.join(ROOT, 'src', 'settings.js'), 'utf8')
		expect(source).toMatch(
			/app\.provide\(\s*'cnTranslate',\s*\(text, vars\) => t\('pipelinq', text, vars\)\s*\)/,
		)
	})
})

describe('CTI platform and event type labels', () => {
	it('labels exactly the values the schema allows', () => {
		const log = schemas.ctiEventLog.properties
		expect(Object.keys(CTI_PLATFORM_LABELS).sort()).toEqual(
			[...log.platform.enum].sort(),
		)
		expect(Object.keys(CTI_PLATFORM_LABELS).sort()).toEqual(
			[...schemas.ctiAdapterConfig.properties.platform.enum].sort(),
		)
		expect(Object.keys(CTI_EVENT_TYPE_LABELS).sort()).toEqual(
			[...log.event_type.enum].sort(),
		)
	})

	it('has a Dutch text for every event type and for "other"', () => {
		for (const label of Object.values(CTI_EVENT_TYPE_LABELS)) {
			expectDutch(label)
		}
		expectDutch(CTI_PLATFORM_LABELS.other)
	})

	it('shows an unknown value as stored', () => {
		expect(enumLabel(CTI_EVENT_TYPE_LABELS, 'hold')).toBe('hold')
		expect(enumLabel(CTI_PLATFORM_LABELS, 'teams')).toBe('teams')
	})
})

const getEventLog = vi.hoisted(() => vi.fn())
vi.mock('../../src/services/ctiApi.js', () => ({ getEventLog }))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn() }))
vi.mock('../../src/dialogs/CtiPayloadDialog.vue', () => ({
	default: { name: 'CtiPayloadDialog', render: () => h('div') },
}))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'inputLabel', 'name'],
		render() {
			return h(
				tag,
				{
					'data-stub': name,
					'data-options': JSON.stringify(this.options || null),
				},
				[this.name, this.$slots.default?.()],
			)
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSelect: stub('NcSelect'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))

globalThis.t = (app, text) => NL[text] || text

describe('the CTI event log in Dutch', () => {
	it('shows the platform and event type by their label', async () => {
		getEventLog.mockResolvedValue({
			events: [
				{
					id: 1,
					received_at: '2026-03-01T09:00:00+00:00',
					platform: 'callvoip',
					event_type: 'ringing',
				},
				{
					id: 2,
					received_at: '2026-03-02T09:00:00+00:00',
					platform: 'other',
					event_type: 'recording',
				},
				{
					id: 3,
					received_at: '2026-03-03T09:00:00+00:00',
					platform: 'teams',
					event_type: 'hold',
				},
			],
		})
		const { default: CtiEventLog } =
			await import('../../src/views/settings/CtiEventLog.vue')
		const wrapper = mount(CtiEventLog, {
			global: { mocks: { t: globalThis.t } },
		})
		await flushPromises()

		const facts = wrapper
			.findAll('[data-testid="cti-event"]')
			.map((row) => row.findAll('dd').map((dd) => dd.text()))
		expect(facts[0].slice(1, 3)).toEqual(['CallVoip', 'Gaat over'])
		expect(facts[1].slice(1, 3)).toEqual(['Overig', 'Opname'])
		expect(facts[2].slice(1, 3)).toEqual(['teams', 'hold'])

		const eventOptions = JSON.parse(
			wrapper.findAll('[data-stub="NcSelect"]')[1].attributes('data-options'),
		)
		expect(eventOptions).toContainEqual({ value: 'ringing', label: 'Gaat over' })
		expect(eventOptions.map((o) => o.value)).toEqual(
			Object.keys(CTI_EVENT_TYPE_LABELS),
		)
	})
})

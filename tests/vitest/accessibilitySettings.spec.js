// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Accessibility card of the admin page (REQ-PAS-003): a functional
 * administrator sees the date of the last manual audit and links to the
 * conformance report and the draft accessibility statement.
 *
 * @spec openspec/changes/platform-accessibility-statement
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) =>
		String(text).replace(/\{(\w+)\}/g, (m, k) =>
			k in vars ? String(vars[k]) : m,
		)
})

vi.mock('@nextcloud/vue', () => ({
	NcSettingsSection: {
		name: 'NcSettingsSection',
		props: ['name', 'description'],
		render() {
			return h('section', { 'data-stub': 'NcSettingsSection' }, [
				h('h2', this.name),
				h('p', { class: 'description' }, this.description),
				this.$slots.default?.(),
			])
		},
	},
}))

const { default: AccessibilitySettings } =
	await import('../../src/views/settings/AccessibilitySettings.vue')
const { accessibilityStatement, formatAuditDate } =
	await import('../../src/config/accessibilityStatement.js')

const mountOptions = { global: { mocks: { t: globalThis.t } } }

describe('the Accessibility card', () => {
	it('links the conformance report and the draft statement on the docs site', () => {
		const wrapper = mount(AccessibilitySettings, mountOptions)
		expect(wrapper.find('h2').text()).toBe('Accessibility')
		const report = wrapper.find('a.accessibility-settings__report')
		const statement = wrapper.find('a.accessibility-settings__statement')
		expect(report.text()).toBe('Accessibility conformance report')
		expect(report.attributes('href')).toBe(
			'https://pipelinq.conduction.nl/docs/compliance/accessibility-conformance-report',
		)
		expect(statement.text()).toBe('Draft accessibility statement')
		expect(statement.attributes('href')).toBe(
			'https://pipelinq.conduction.nl/docs/compliance/toegankelijkheidsverklaring-concept',
		)
		for (const link of [report, statement]) {
			expect(link.attributes('target')).toBe('_blank')
			expect(link.attributes('rel')).toBe('noopener noreferrer')
		}
	})

	it('says no audit is recorded while the date is empty', () => {
		expect(accessibilityStatement.lastAuditDate).toBeNull()
		const wrapper = mount(AccessibilitySettings, mountOptions)
		expect(wrapper.find('.accessibility-settings__audit').text()).toBe(
			'No manual audit has been recorded yet.',
		)
	})

	it('shows the date of the last manual audit', () => {
		const wrapper = mount(AccessibilitySettings, {
			...mountOptions,
			props: { lastAuditDate: '2026-11-03' },
		})
		expect(wrapper.find('.accessibility-settings__audit').text()).toBe(
			`Last manual audit: ${formatAuditDate('2026-11-03')}`,
		)
		expect(wrapper.find('.accessibility-settings__audit').text()).toContain(
			'2026',
		)
	})
})

describe('formatAuditDate', () => {
	it('formats the calendar day in the given locale', () => {
		expect(formatAuditDate('2026-11-03', 'en-GB')).toBe('3 November 2026')
		expect(formatAuditDate('2026-11-03', 'nl-NL')).toBe('3 november 2026')
	})

	it('keeps the first and last day of a year on that day', () => {
		expect(formatAuditDate('2027-01-01', 'en-GB')).toBe('1 January 2027')
		expect(formatAuditDate('2026-12-31', 'en-GB')).toBe('31 December 2026')
	})

	it('returns nothing for a missing or malformed date', () => {
		expect(formatAuditDate(null)).toBe('')
		expect(formatAuditDate('')).toBe('')
		expect(formatAuditDate('03-11-2026')).toBe('')
		expect(formatAuditDate('2026-02-30')).toBe('')
	})
})

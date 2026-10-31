// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The "Contacts" section of the user settings dialog (board PqPersoonlijk),
 * mounted with axios and the Nextcloud components replaced: it shows the
 * sync status, saves the write-back switch, runs a full sync and names the
 * records that failed, and App.vue places it before "Email and calendar".
 *
 * @spec openspec/specs/contacts-sync/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ get: vi.fn(), put: vi.fn(), post: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
vi.mock('../../src/dialogs/ContactImportDialog.vue', () => ({ default: { name: 'ContactImportDialog', render: () => h('div', { 'data-testid': 'import-dialog' }) } }))
vi.mock('@nextcloud/vue', () => {
	const slot = (tag) => ({
		props: ['name', 'modelValue', 'type', 'disabled', 'variant'],
		emits: ['update:modelValue', 'click'],
		render() {
			return h(tag, {
				onClick: () => {
					this.$emit('click')
					if (this.type === 'switch') this.$emit('update:modelValue', !this.modelValue)
				},
			}, [this.name, this.$slots.default?.()])
		},
	})
	return {
		NcAppSettingsSection: slot('section'),
		NcButton: slot('button'),
		NcCheckboxRadioSwitch: slot('label'),
		NcLoadingIcon: slot('span'),
		NcNoteCard: slot('div'),
	}
})

let Section
let status

beforeAll(async () => {
	Section = (await import('../../src/components/settings/ContactsSyncSettingsSection.vue')).default
})

beforeEach(() => {
	status = { writeBack: true, linked: 412, addressBook: 'Pipelinq CRM', lastRun: null }
	http.get.mockReset()
	http.put.mockReset()
	http.post.mockReset()
	http.get.mockImplementation(() => Promise.resolve({ data: status }))
	http.put.mockImplementation((url, body) => Promise.resolve({ data: { ...status, writeBack: body.writeBack } }))
	http.post.mockImplementation(() => {
		status = { ...status, lastRun: { at: '2026-10-07T09:12:00+02:00', updated: 3, failed: [{ name: 'Bakkerij De Graaf' }] } }
		return Promise.resolve({ data: status.lastRun })
	})
})

const global = { mixins: [{ methods: { t: (app, text, vars) => (vars ? text.replace(/\{(\w+)\}/g, (m, k) => vars[k]) : text) } }] }

describe('ContactsSyncSettingsSection', () => {
	it('shows how many records are linked and where new cards go', async () => {
		const wrapper = mount(Section, { global })
		await flushPromises()
		const facts = wrapper.find('[data-testid="contacts-sync-status"]').text()
		expect(facts).toContain('412 residents, businesses and contacts')
		expect(facts).toContain('Pipelinq CRM')
		expect(facts).toContain('Never')
	})

	it('saves the write-back switch', async () => {
		const wrapper = mount(Section, { global })
		await flushPromises()
		await wrapper.find('[data-testid="contacts-sync-write-back"]').trigger('click')
		await flushPromises()
		expect(http.put).toHaveBeenCalledWith('/apps/pipelinq/api/contacts-sync/settings', { writeBack: false })
		expect(wrapper.vm.status.writeBack).toBe(false)
	})

	it('runs a full sync and names the record whose card failed', async () => {
		const wrapper = mount(Section, { global })
		await flushPromises()
		await wrapper.find('[data-testid="contacts-sync-run"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/contacts-sync/run')
		expect(wrapper.find('[data-testid="contacts-sync-status"]').text()).toContain('3 cards updated')
		expect(wrapper.find('[data-testid="contacts-sync-failed"]').text()).toContain('Bakkerij De Graaf')
	})

	it('opens the Nextcloud Contacts import from the section', async () => {
		const wrapper = mount(Section, { global })
		await flushPromises()
		await wrapper.find('[data-testid="contacts-sync-import"]').trigger('click')
		expect(wrapper.find('[data-testid="import-dialog"]').exists()).toBe(true)
	})

	it('sits in the user settings dialog before Email and calendar', () => {
		const app = readFileSync(resolve(__dirname, '../../src/App.vue'), 'utf8')
		expect(app.indexOf('<ContactsSyncSettingsSection />')).toBeGreaterThan(-1)
		expect(app.indexOf('<ContactsSyncSettingsSection />')).toBeLessThan(app.indexOf('<EmailCalendarSettingsSection />'))
	})
})

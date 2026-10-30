// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Lead rotation (pipeline-lead-rotation): the admin switch and the cap and
 * pause on an agent profile, and LeadDetail showing why a lead has its owner.
 *
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-new-unassigned-leads-are-assigned-in-turn-req-lrt-001
 * @spec openspec/changes/pipeline-lead-rotation/specs/lead-rotation/spec.md#requirement-a-colleague-can-pause-their-share-req-lrt-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const profilesStore = vi.hoisted(() => ({
	loading: false,
	profiles: [{ id: 'p1', userId: 'anna', skills: [], maxConcurrent: 10, isAvailable: true, pauseLeadAssignment: true, leadCapPer30Days: 10 }],
	fetchProfiles: vi.fn(),
	saveProfile: vi.fn(),
	deleteProfile: vi.fn(),
}))
const settingsStore = vi.hoisted(() => ({
	config: { lead_rotation_enabled: 'false' },
	fetchSettings: vi.fn(),
	saveSettings: vi.fn(),
}))

vi.mock('../../src/store/modules/agentProfiles.js', () => ({ useAgentProfilesStore: () => profilesStore }))
vi.mock('../../src/store/modules/skills.js', () => ({ useSkillsStore: () => ({ skills: [], fetchSkills: vi.fn() }) }))
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => settingsStore }))

function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'label', 'name', 'description'],
		emits: ['click', 'update:modelValue'],
		render() {
			return h(tag, { 'data-stub': name, 'data-label': this.label }, [this.$slots.default?.()])
		},
	}
}

vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcSettingsSection: stub('NcSettingsSection', 'section'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDeleteDialog: stub('CnDeleteDialog'),
	CnStatusBadge: stub('CnStatusBadge', 'span'),
}))

globalThis.t = (app, text, vars = {}) =>
	String(text).replace(/\{(\w+)\}/g, (whole, key) => vars[key] ?? whole)

const mountOptions = { global: { mocks: { t: globalThis.t } } }
const { default: AgentProfileSettings } = await import('../../src/components/admin/AgentProfileSettings.vue')
const { default: manifest } = await import('../../src/manifest.json')

describe('lead rotation settings', () => {
	it('switches automatic assignment through the app settings', async () => {
		const wrapper = mount(AgentProfileSettings, mountOptions)
		const toggle = wrapper.getComponent({ name: 'NcCheckboxRadioSwitch' })
		expect(toggle.props('modelValue')).toBe(false)
		expect(toggle.text()).toBe('Assign new leads automatically')

		toggle.vm.$emit('update:modelValue', true)
		await flushPromises()
		expect(settingsStore.saveSettings).toHaveBeenCalledWith({ lead_rotation_enabled: 'true' })
	})

	it('shows a paused colleague and the cap, and saves the two fields', async () => {
		const wrapper = mount(AgentProfileSettings, mountOptions)
		expect(wrapper.find('[data-label="Lead assignment paused"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('at most 10 leads per 30 days')

		wrapper.vm.startEdit(profilesStore.profiles[0])
		await flushPromises()
		await wrapper.get('#agent-edit-lead-cap').setValue('')
		await wrapper.vm.saveEdit()
		const saved = profilesStore.saveProfile.mock.calls[0][0]
		expect(saved).not.toHaveProperty('leadCapPer30Days')
		expect(saved.pauseLeadAssignment).toBe(true)
	})
})

describe('LeadDetail', () => {
	it('shows why the lead has its owner, read only', () => {
		const lead = manifest.pages.find((page) => page.id === 'LeadDetail')
		const deal = lead.config.widgets.find((widget) => widget.id === 'lead-deal').content
		expect(deal.include).toContain('assignmentReason')
		expect(deal.overrides.assignmentReason).toEqual({ editable: false, label: 'Why this owner', order: 9 })
	})
})

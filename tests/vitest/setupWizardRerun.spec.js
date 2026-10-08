// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The admin page can run the setup wizard again (pipelinq-audit-admin-forms-pos, A1).
 * The wizard opens by itself once per setup version; after that the admin
 * settings card is the way back in, with the manifest's own steps.
 *
 * @spec openspec/changes/pipelinq-audit-admin-forms-pos/specs/admin-settings/spec.md
 */

import { mount } from '@vue/test-utils'
import { readFileSync } from 'fs'
import { resolve } from 'path'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import manifest from '../../src/manifest.json'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnSetupWizard: {
		name: 'CnSetupWizard',
		props: ['appId', 'steps', 'cancellable'],
		emits: ['close', 'complete'],
		render() {
			return h('div', { 'data-wizard': this.appId })
		},
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		emits: ['click'],
		render() {
			return h(
				'button',
				{ onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcSettingsSection: {
		name: 'NcSettingsSection',
		render() {
			return h('section', this.$slots.default?.())
		},
	},
}))
vi.mock('vue-material-design-icons/AutoFix.vue', () => ({
	default: { name: 'AutoFix', render: () => null },
}))

const { default: SetupWizardSection } =
	await import('../../src/views/settings/SetupWizardSection.vue')

describe('run the setup wizard again', () => {
	it('opens the manifest wizard from the admin card and closes it again', async () => {
		const wrapper = mount(SetupWizardSection, {
			global: { mocks: { t: (_app, text) => text } },
		})
		expect(wrapper.find('[data-wizard]').exists()).toBe(false)

		await wrapper.find('button').trigger('click')
		const wizard = wrapper.findComponent({ name: 'CnSetupWizard' })
		expect(wizard.exists()).toBe(true)
		expect(wizard.props('appId')).toBe('pipelinq')
		expect(wizard.props('steps')).toEqual(manifest.setup.steps)

		wizard.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.find('[data-wizard]').exists()).toBe(false)
	})

	// setup-wizard-close-on-server: the close is now recorded on the server
	// and in localStorage, and CnAppRoot no longer opens the wizard by itself.
	// The admin card must not read either record, or it could never reopen.
	it('opens the wizard again after it was closed on the server', async () => {
		window.localStorage.setItem('cn-setup-wizard-dismissed:pipelinq:1', '1')
		const wrapper = mount(SetupWizardSection, {
			global: { mocks: { t: (_app, text) => text } },
		})

		await wrapper.find('button').trigger('click')
		expect(wrapper.find('[data-wizard]').exists()).toBe(true)

		const source = readFileSync(
			resolve(__dirname, '../../src/views/settings/SetupWizardSection.vue'),
			'utf8',
		)
		expect(source).not.toMatch(/dismissed|localStorage|setup\/status/)
		window.localStorage.removeItem('cn-setup-wizard-dismissed:pipelinq:1')
	})

	it('is on the admin settings page', () => {
		const source = readFileSync(
			resolve(__dirname, '../../src/views/settings/Settings.vue'),
			'utf8',
		)
		expect(source).toMatch(/<SetupWizardSection v-if="isAdmin" \/>/)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client page's New request opens the request dialog with that client
 * picked already (PqKlant / PqOrganisatie header "Nieuw verzoek").
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: { template: '<div><slot /></div>' } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: { template: '<button />' }, NcCheckboxRadioSwitch: { template: '<input />' } }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/views/requests/RequestForm.vue', () => ({ default: { name: 'RequestForm', props: ['preLinkedClient', 'showActions', 'boardLayout'], template: '<div class="stub-form" />' } }))

const { default: RequestCreateDialog } = await import('../../src/dialogs/RequestCreateDialog.vue')

const stubs = {
	CnDialog: { template: '<div><slot /><slot name="actions" /></div>' },
	RequestForm: { name: 'RequestForm', props: ['preLinkedClient', 'showActions', 'boardLayout'], template: '<div class="stub-form" />' },
	NcButton: true,
	NcCheckboxRadioSwitch: true,
}

describe('RequestCreateDialog with a client', () => {
	it('hands the client to the form', () => {
		const wrapper = mount(RequestCreateDialog, { props: { clientId: 'client-1' }, global: { stubs, config: { globalProperties: { t: (app, text) => text } } } })
		expect(wrapper.findComponent({ name: 'RequestForm' }).props('preLinkedClient')).toBe('client-1')
	})

	it('leaves the client to choose without one', () => {
		const wrapper = mount(RequestCreateDialog, { global: { stubs, config: { globalProperties: { t: (app, text) => text } } } })
		expect(wrapper.findComponent({ name: 'RequestForm' }).props('preLinkedClient')).toBeNull()
	})
})

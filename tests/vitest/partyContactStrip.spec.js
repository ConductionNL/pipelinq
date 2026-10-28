// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client page's contact strip (PqKlant, PqOrganisatie): one card with the
 * warnings line, "No warnings on this resident." and an Add a warning link
 * when there are none, and the call, mail and directions links drawn as
 * buttons with a verb.
 *
 * @spec openspec/changes/client-page-as-the-relation-boards/specs/client-detail/spec.md#requirement-the-warnings-and-the-ways-to-reach-the-client-are-one-card
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDetailCard: { props: ['title'], template: '<div class="stub-card"><slot /></div>' } }))
vi.mock('@nextcloud/dialogs', () => ({ showError: () => {}, showSuccess: () => {} }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { template: '<button><slot /></button>' },
	NcLoadingIcon: { template: '<i />' },
	NcSelect: { template: '<div />' },
	NcTextField: { template: '<input />' },
}))
vi.mock('../../src/services/partyIndicators.js', () => ({
	acknowledgeIndicator: vi.fn(),
	addIndicatorValue: vi.fn(),
	fetchIndicatorVocabulary: vi.fn(async () => []),
	fetchPartyIndicators: vi.fn(async () => []),
	needsAcknowledgement: () => false,
}))
vi.mock('../../src/components/LogVisitAction.vue', () => ({ default: { name: 'LogVisitAction', props: ['clientId'], template: '<button class="stub-visit" />' } }))

vi.stubGlobal('t', (app, text) => text)

const { default: PartyContactStrip } = await import('../../src/components/PartyContactStrip.vue')
const global = { config: { globalProperties: { t: (app, text) => text } } }

describe('PartyContactStrip', () => {
	it('draws the none line with Add a warning and no card of the panel\'s own', async () => {
		const wrapper = mount(PartyContactStrip, { props: { partyId: 'c-1', partyKind: 'person' }, global })
		await flushPromises()
		expect(wrapper.find('.stub-card').exists()).toBe(false)
		expect(wrapper.find('.party-indicators__none').text()).toContain('No warnings on this resident.')
		expect(wrapper.find('[data-testid="party-indicators-add"]').exists()).toBe(true)
	})

	it('draws the contact links as buttons with a verb, and the visit action', async () => {
		const wrapper = mount(PartyContactStrip, { props: { partyId: 'c-1', partyKind: 'person', phone: '06 12 34 56 78', email: 's@example.nl', address: 'Parkstraat 24, Zuiddrecht' }, global })
		await flushPromises()
		expect(wrapper.find('.contact-links--buttons').exists()).toBe(true)
		expect(wrapper.findAll('.contact-links__link').map((link) => link.text())).toEqual(['Call them', 'Email them', 'Directions'])
		expect(wrapper.find('.stub-visit').exists()).toBe(true)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The contact page's Nextcloud Contacten card (board PqContactpersoon): the
 * synced pill, the address book and the last change, and Update in Contacts
 * writing the record to the card again.
 *
 * @spec openspec/changes/contact-person-page-as-board/specs/contact-person-page-as-board/spec.md#requirement-the-contact-page-shows-its-address-book-card
 */

import { flushPromises, mount } from '@vue/test-utils'
import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it, vi } from 'vitest'

const today = new Date()
today.setHours(9, 12, 0, 0)

vi.mock('@nextcloud/l10n', async (importOriginal) => ({ ...(await importOriginal()), getLanguage: () => 'nl' }))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnStatusBadge: { name: 'CnStatusBadge', props: ['label', 'variant'], template: '<span class="pill" :data-variant="variant">{{ label }}</span>' },
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { name: 'NcButton', template: '<button v-bind="$attrs" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span />' },
}))
const api = vi.hoisted(() => ({ cardStatus: vi.fn(), writeBack: vi.fn(async () => 'u-1') }))
vi.mock('../../src/services/contactSyncApi.js', () => api)

const { default: ContactsSyncSection } = await import('../../src/components/ContactsSyncSection.vue')

const fill = (s, vars = {}) => s.replace(/\{(\w+)\}/g, (_, k) => vars[k])
const mountCard = (props) => mount(ContactsSyncSection, { props, global: { mocks: { t: (_a, s, v) => fill(s, v) } } })

describe('the Nextcloud Contacten card', () => {
	it('names the address book and today\'s change when the card exists', async () => {
		api.cardStatus.mockResolvedValueOnce({ linked: true, addressBook: 'Pipelinq CRM', updatedAt: today.toISOString() })
		const wrapper = mountCard({ entityId: 'c-1', contactsUid: 'u-1' })
		await flushPromises()
		expect(api.cardStatus).toHaveBeenCalledWith('u-1')
		expect(wrapper.find('.pill').text()).toBe('Synced with Contacts')
		expect(wrapper.find('.pill').attributes('data-variant')).toBe('success')
		expect(wrapper.find('[data-testid="contacts-sync-line"]').text()).toBe('Card in the address book Pipelinq CRM, updated today 09:12.')
	})

	it('says so when there is no card, without asking the server', async () => {
		api.cardStatus.mockClear()
		const wrapper = mountCard({ entityId: 'c-1', contactsUid: '' })
		await flushPromises()
		expect(api.cardStatus).not.toHaveBeenCalled()
		expect(wrapper.find('.pill').text()).toBe('Not in Contacts')
	})

	it('writes the record to its card with Update in Contacts', async () => {
		api.cardStatus.mockResolvedValue({ linked: true, addressBook: 'Pipelinq CRM', updatedAt: null })
		const wrapper = mountCard({ entityId: 'c-1', entityType: 'contact', contactsUid: 'u-1' })
		await flushPromises()
		await wrapper.find('[data-testid="contacts-sync-update"]').trigger('click')
		await flushPromises()
		expect(api.writeBack).toHaveBeenCalledWith('contact', 'c-1')
		expect(wrapper.find('[data-testid="contacts-sync-line"]').text()).toBe('Card in the address book Pipelinq CRM.')
	})

	it('sits in the contact page\'s side column before History', () => {
		const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
		const page = manifest.pages.find((p) => p.id === 'ContactDetail')
		expect(page.config.sideColumn).toEqual(['contact-kpis-deals', 'contact-related', 'contact-sync', 'contact-side-history'])
		expect(page.config.widgets.find((w) => w.id === 'contact-sync').type).toBe('ContactsSyncWidget')
	})
})

describe('Relationships and Channels in the compact look', () => {
	const manifest = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../src/manifest.json'), 'utf8'))
	const page = manifest.pages.find((p) => p.id === 'ContactDetail')
	const registry = fs.readFileSync(path.resolve(__dirname, '../../src/registry.js'), 'utf8')

	it('asks Overview for the compact look of both', () => {
		const sections = page.config.widgets.find((w) => w.id === 'contact-overview').content.sections
		expect(sections[2].widget.content.compact).toBe(true)
		expect(sections[3].widget.content.compact).toBe(true)
	})

	it('passes compact through to both sections', () => {
		expect(registry.match(/compact: content\.compact === true/g)).toHaveLength(2)
	})
})

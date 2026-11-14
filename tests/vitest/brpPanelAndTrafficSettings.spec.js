// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BRP contact panel (BSN feedback, the purpose-bound lookup, the hidden
 * address and revealing it) and the marketing traffic settings (reading the
 * stored config, saving, and replacing or removing the Search Console key),
 * mounted with axios and the settings store doubled.
 *
 * @spec openspec/specs/brp-lookup/spec.md
 */

import axios from '@nextcloud/axios'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.hoisted(() => {
	globalThis.t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
	globalThis.n = (app, one, many, count, vars = {}) => globalThis.t(app, count === 1 ? one : many, vars).replace('%n', String(count))
})

const stub = (name) => ({ name, render: () => null })
const toasts = []
vi.mock('@conduction/nextcloud-vue', () => ({
	BSN_ERROR_CHECKSUM: 'checksum',
	BSN_ERROR_LENGTH: 'length',
	CnDetailCard: stub('CnDetailCard'),
	validateBsn: (raw) => {
		const digits = String(raw).replace(/\D/g, '')
		if (digits.length !== 9) {
			return { isFormallyValid: false, errorCode: 'length' }
		}
		const sum = digits.split('').reduce((acc, d, i) => acc + Number(d) * (i === 8 ? -1 : 9 - i), 0)
		return sum % 11 === 0 ? { isFormallyValid: true, errorCode: null } : { isFormallyValid: false, errorCode: 'checksum' }
	},
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcLoadingIcon: stub('NcLoadingIcon'),
	NcNoteCard: stub('NcNoteCard'),
	NcSettingsSection: stub('NcSettingsSection'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))
vi.mock('@nextcloud/dialogs', () => ({
	showError: (m) => toasts.push(['error', m]),
	showSuccess: (m) => toasts.push(['success', m]),
}))
vi.mock('../../src/modals/BrpDoelbindingModal.vue', () => ({ default: stub('BrpDoelbindingModal') }))
const settings = { saveSettings: vi.fn() }
vi.mock('../../src/store/modules/settings.js', () => ({ useSettingsStore: () => settings }))

const { default: BrpContactPanel } = await import('../../src/components/BrpContactPanel.vue')
const { default: MarketingTrafficSettings } = await import('../../src/views/settings/MarketingTrafficSettings.vue')

const mocks = { t: globalThis.t, n: globalThis.n }

/**
 * Mount a component and let it load.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {Promise<object>} The wrapper.
 */
async function mountIt(component, props = {}) {
	const w = shallowMount(component, { props, global: { mocks } })
	await flushPromises()
	return w
}

beforeEach(() => {
	toasts.length = 0
	vi.clearAllMocks()
	axios.get = vi.fn(async () => ({ data: {} }))
	axios.post = vi.fn()
})

describe('BrpContactPanel', () => {
	const valid = '111222333'

	it('gives feedback on the BSN and allows a lookup only for a formally valid one', async () => {
		const w = await mountIt(BrpContactPanel, { contactId: 'c1' })
		expect([w.vm.bsnFeedback, w.vm.canLookup]).toEqual(['', false])
		w.vm.rawBsn = '1234'
		expect(w.vm.bsnFeedback).toBe('A BSN is exactly 9 digits')
		w.vm.rawBsn = '111222334'
		expect([w.vm.bsnFeedback, w.vm.canLookup]).toEqual(['This BSN does not pass the 11-check', false])
		w.vm.rawBsn = valid
		expect([w.vm.bsnFeedback, w.vm.canLookup]).toEqual(['BSN passes the 11-check', true])
		w.vm.openDoelbinding()
		expect(w.vm.showModal).toBe(true)
	})

	it('opens no purpose dialog for an invalid BSN', async () => {
		const w = await mountIt(BrpContactPanel, { contactId: 'c1', initialBsn: '123' })
		w.vm.openDoelbinding()
		expect(w.vm.showModal).toBe(false)
	})

	it('looks up the person with the stated purpose and links the contact', async () => {
		axios.post.mockResolvedValueOnce({ data: { persoon: { given_names: 'Jan', name_prefix: 'de', surname: 'Vries', residence: { street: 'Dorpsstraat' } }, responseInCache: 1 } })
		const w = await mountIt(BrpContactPanel, { contactId: 'c1', initialBsn: valid })
		await w.vm.onLookup({ verzoekreden: 'r', purposeBinding: 'p', basis: 'b', vogScreening: false })
		const [url, body] = axios.post.mock.calls[0]
		expect(url).toMatch(/\/apps\/pipelinq\/api\/brp\/lookup$/)
		expect(body).toEqual({ bsn: valid, verzoekreden: 'r', purposeBinding: 'p', basis: 'b', gekoppeldContact: 'c1', vogScreening: false })
		expect([w.vm.lookupState, w.vm.cacheHit, w.vm.fullName, w.vm.address]).toEqual(['success', true, 'Jan de Vries', { street: 'Dorpsstraat' }])
		expect(w.emitted('contactUpdated')).toHaveLength(1)
		expect(toasts).toEqual([['success', 'BRP data retrieved']])
	})

	it('hides a secret address until it is revealed, and records the reveal', async () => {
		axios.post.mockResolvedValueOnce({ data: { persoon: { surname: 'X', indicationSecret: '1', residence: { street: 'Hidden' } } } })
		const w = await mountIt(BrpContactPanel, { contactId: 'c 1', initialBsn: valid })
		await w.vm.onLookup({})
		expect(w.vm.address).toBeNull()
		axios.post.mockResolvedValueOnce({ data: { residence: { street: 'Revealed' } } })
		await w.vm.revealAddress()
		expect(axios.post.mock.calls[1][0]).toMatch(/\/apps\/pipelinq\/api\/brp\/contact\/c%201\/reveal-address$/)
		expect([w.vm.revealedAddress, w.vm.address]).toEqual([true, { street: 'Revealed' }])
		expect(toasts.at(-1)).toEqual(['success', 'Address revealed — audit record created.'])
		axios.post.mockRejectedValueOnce({ response: { data: { errorMessage: 'No permission' } } })
		await w.vm.revealAddress()
		axios.post.mockRejectedValueOnce({})
		await w.vm.revealAddress()
		expect(toasts.slice(-2)).toEqual([['error', 'No permission'], ['error', 'Could not reveal address.']])
	})

	it('reports an empty answer and an unavailable BRP', async () => {
		const w = await mountIt(BrpContactPanel, { contactId: 'c1', initialBsn: valid })
		expect([w.vm.fullName, w.vm.address]).toEqual(['', null])
		axios.post.mockResolvedValueOnce({ data: {} })
		await w.vm.onLookup({})
		expect([w.vm.lookupState, w.emitted('contactUpdated')]).toEqual(['error', undefined])
		axios.post.mockRejectedValueOnce({ response: { data: { errorMessage: 'Doelbinding refused' } } })
		await w.vm.onLookup({})
		axios.post.mockRejectedValueOnce({})
		await w.vm.onLookup({})
		expect(toasts.map((e) => e[1])).toEqual(['Doelbinding refused', 'BRP is currently unavailable — please try again in a few minutes.'])
	})
})

describe('MarketingTrafficSettings', () => {
	const config = {
		'blast.utm_auto': 'false',
		'blast.traffic_portal': 'https://www.example.nl',
		'search.gsc.properties': 'sc-domain:a.nl, sc-domain:b.nl',
		'search.gsc.service_account_key_set': 'true',
		'search.gsc.last_import_at': '2026-05-01',
	}

	it('reads the stored settings and the service account', async () => {
		axios.get.mockResolvedValueOnce({ data: { serviceAccountEmail: 'sa@x.iam', lastImportAt: '2026-05-10' } })
		const w = await mountIt(MarketingTrafficSettings, { config })
		expect([w.vm.utmAuto, w.vm.trafficPortal, w.vm.gscProperties, w.vm.keySet]).toEqual([false, 'https://www.example.nl', 'sc-domain:a.nl\nsc-domain:b.nl', true])
		expect([w.vm.serviceAccountEmail, w.vm.lastImportAt]).toEqual(['sa@x.iam', '2026-05-10'])
		expect(axios.get.mock.calls[0][1]).toEqual({ params: { limit: 1 } })
		expect(w.vm.keyPlaceholder).toBe('A key is stored. Paste a new one to replace it.')
		expect(w.vm.keyHelp).toContain('stored encrypted')
	})

	it('defaults to automatic UTM tags and no key, and survives a failed status read', async () => {
		axios.get.mockRejectedValueOnce(new Error('x'))
		const w = await mountIt(MarketingTrafficSettings)
		expect([w.vm.utmAuto, w.vm.keySet, w.vm.serviceAccountEmail, w.vm.keyPlaceholder]).toEqual([true, false, '', '{ "type": "service_account", ... }'])
	})

	it('saves the settings with a new key only when one is pasted', async () => {
		settings.saveSettings.mockResolvedValue({ 'blast.utm_auto': 'true' })
		const w = await mountIt(MarketingTrafficSettings, { config })
		Object.assign(w.vm, { utmAuto: true, trafficPortal: ' https://p ', gscProperties: ' a \n b ', gscKey: '' })
		await w.vm.save()
		expect(settings.saveSettings).toHaveBeenCalledWith({ 'blast.utm_auto': 'true', 'blast.traffic_portal': 'https://p', 'search.gsc.properties': 'a \n b' })
		w.vm.gscKey = ' {"type":"service_account"} '
		await w.vm.save()
		expect(settings.saveSettings.mock.calls[1][0]['search.gsc.service_account_key']).toBe('{"type":"service_account"}')
		expect([w.vm.gscKey, w.vm.message, w.vm.messageType]).toEqual(['', 'Marketing traffic settings saved', 'success'])
		expect(w.emitted('saved')).toHaveLength(2)
	})

	it('reports a failed save and removes a stored key', async () => {
		const w = await mountIt(MarketingTrafficSettings, { config })
		settings.saveSettings.mockResolvedValueOnce(null)
		await w.vm.save()
		expect([w.vm.message, w.vm.messageType, w.vm.saving]).toEqual(['Saving the marketing traffic settings failed', 'error', false])
		settings.saveSettings.mockResolvedValueOnce({ 'search.gsc.service_account_key_set': 'false' })
		w.vm.serviceAccountEmail = 'sa@x'
		await w.vm.clearKey()
		expect(settings.saveSettings).toHaveBeenLastCalledWith({ 'search.gsc.service_account_key_clear': 'true' })
		expect([w.vm.keySet, w.vm.serviceAccountEmail, w.vm.message]).toEqual([false, '', 'The stored key was removed'])
		settings.saveSettings.mockResolvedValueOnce(null)
		await w.vm.clearKey()
		expect(w.vm.saving).toBe(false)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * BrpContactPanel, rendered: the BSN field's success and error state, the
 * lookup button and the purpose dialog it opens and closes, the loading and
 * error lines, and the person block (name, cache badge, fields with their
 * "-" fallback, a confidential address behind the reveal button, and the
 * address with its country only when abroad).
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosPost = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({ default: { post: axiosPost } }))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (path, vars = {}) => '/index.php' + path.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@conduction/nextcloud-vue', async () => {
	const { h: render } = await import('vue')
	return {
		BSN_ERROR_CHECKSUM: 'checksum',
		BSN_ERROR_LENGTH: 'length',
		CnDetailCard: {
			props: { title: { default: '' } },
			render() {
				return render('section', { 'data-title': this.title }, this.$slots.default?.())
			},
		},
		validateBsn: (raw) => {
			const digits = String(raw).replace(/\D/g, '')
			if (digits.length !== 9) {
				return { isFormallyValid: false, errorCode: 'length' }
			}
			const sum = digits.split('').reduce((acc, d, i) => acc + Number(d) * (i === 8 ? -1 : 9 - i), 0)
			return sum % 11 === 0 ? { isFormallyValid: true, errorCode: null } : { isFormallyValid: false, errorCode: 'checksum' }
		},
	}
})
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: { disabled: { default: false }, variant: { default: '' } },
		emits: ['click'],
		render() {
			return h('button', { class: this.variant, disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
		},
	},
	NcLoadingIcon: { render: () => h('i', { class: 'loading' }) },
	NcTextField: {
		name: 'NcTextField',
		inheritAttrs: false,
		props: { modelValue: { default: '' }, success: { default: false }, error: { default: false }, helperText: { default: '' } },
		emits: ['update:modelValue'],
		render() {
			return h('input', { value: this.modelValue, onInput: (e) => this.$emit('update:modelValue', e.target.value) })
		},
	},
}))
vi.mock('../../src/modals/BrpDoelbindingModal.vue', async () => {
	const { h: render } = await import('vue')
	return {
		default: {
			name: 'BrpDoelbindingModal',
			emits: ['close', 'submit'],
			render: () => render('div', { class: 'purpose-modal' }),
		},
	}
})

const { default: BrpContactPanel } = await import('../../src/components/BrpContactPanel.vue')

const t = (app, text, vars = {}) => String(text).replace(/\{(\w+)\}/g, (m, k) => (k in vars ? String(vars[k]) : m))
const VALID = '111222333'
const PURPOSE = { verzoekreden: 'r', purposeBinding: 'p', basis: 'b', vogScreening: false }

/**
 * Mount the panel.
 *
 * @param {object} props Extra props.
 * @return {object} The wrapper.
 */
function open(props = {}) {
	return mount(BrpContactPanel, { props: { contactId: 'c-1', ...props }, global: { mocks: { t } } })
}

/**
 * Mount with a valid BSN and run a lookup that answers with this person.
 *
 * @param {object|null} persoon The person, or null.
 * @param {boolean} cached Whether the answer came from the cache.
 * @return {Promise<object>} The wrapper.
 */
async function lookedUp(persoon, cached = false) {
	axiosPost.mockResolvedValueOnce({ data: { persoon, responseInCache: cached } })
	const wrapper = open({ initialBsn: VALID })
	await wrapper.find('[data-testid="brp-lookup-button"]').trigger('click')
	await wrapper.findComponent({ name: 'BrpDoelbindingModal' }).vm.$emit('submit', PURPOSE)
	await flushPromises()
	return wrapper
}

const field = (wrapper) => wrapper.findComponent({ name: 'NcTextField' })

describe('BrpContactPanel rendered', () => {
	beforeEach(() => {
		axiosPost.mockReset()
	})

	it('titles the card and starts with a neutral, empty BSN field and a disabled lookup', () => {
		const wrapper = open()

		expect(wrapper.find('section').attributes('data-title')).toBe('BSN and BRP')
		expect(field(wrapper).props()).toMatchObject({ modelValue: '', success: false, error: false, helperText: ' ' })
		expect(wrapper.find('[data-testid="brp-lookup-button"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="brp-persoon"]').exists()).toBe(false)
	})

	it('marks a wrong BSN as an error and a valid one as a success', async () => {
		const wrapper = open()

		await field(wrapper).vm.$emit('update:modelValue', '111222334')
		expect(field(wrapper).props()).toMatchObject({ success: false, error: true, helperText: 'This BSN does not pass the 11-check' })

		await field(wrapper).vm.$emit('update:modelValue', VALID)
		expect(field(wrapper).props()).toMatchObject({ success: true, error: false, helperText: 'BSN passes the 11-check' })
		expect(wrapper.find('[data-testid="brp-lookup-button"]').attributes('disabled')).toBeUndefined()
	})

	it('opens the purpose dialog from the button and closes it again', async () => {
		const wrapper = open({ initialBsn: VALID })

		await wrapper.find('[data-testid="brp-lookup-button"]').trigger('click')
		expect(wrapper.find('.purpose-modal').exists()).toBe(true)

		await wrapper.findComponent({ name: 'BrpDoelbindingModal' }).vm.$emit('close')
		expect(wrapper.find('.purpose-modal').exists()).toBe(false)
		expect(axiosPost).not.toHaveBeenCalled()
	})

	it('shows the loading line and disables the button while looking up', async () => {
		let release
		axiosPost.mockReturnValueOnce(new Promise((resolve) => { release = resolve }))
		const wrapper = open({ initialBsn: VALID })
		await wrapper.find('[data-testid="brp-lookup-button"]').trigger('click')

		await wrapper.findComponent({ name: 'BrpDoelbindingModal' }).vm.$emit('submit', PURPOSE)

		expect(wrapper.find('.purpose-modal').exists()).toBe(false)
		expect(wrapper.find('.brp-panel__status').text()).toBe('Retrieve from BRP…')
		expect(wrapper.find('.loading').exists()).toBe(true)
		expect(wrapper.find('[data-testid="brp-lookup-button"]').attributes('disabled')).toBeDefined()

		release({ data: {} })
		await flushPromises()
		expect(wrapper.find('.loading').exists()).toBe(false)
	})

	it('shows the error line with the server message', async () => {
		axiosPost.mockRejectedValueOnce({ response: { data: { errorMessage: 'Geen toegang' } } })
		const wrapper = open({ initialBsn: VALID })
		await wrapper.find('[data-testid="brp-lookup-button"]').trigger('click')
		await wrapper.findComponent({ name: 'BrpDoelbindingModal' }).vm.$emit('submit', PURPOSE)
		await flushPromises()

		expect(wrapper.find('.brp-panel__status--error').text()).toBe('Geen toegang')
		expect(wrapper.find('[data-testid="brp-persoon"]').exists()).toBe(false)
	})

	it('renders the person with name parts, cache badge, "-" for missing fields and a Dutch address without country', async () => {
		const wrapper = await lookedUp({
			given_names: 'Jan',
			name_prefix: 'van',
			surname: 'Dijk',
			date_of_birth: '1980-01-02',
			residence: { straat: 'Markt', huisnummer: 1, huisletter: 'a', postcode: '1234 AB', woonplaats: 'Utrecht', land: 'Nederland' },
		}, true)
		const block = wrapper.find('[data-testid="brp-persoon"]')

		expect(block.find('strong').text()).toBe('Jan van Dijk')
		expect(block.find('.brp-panel__cache-badge').text()).toBe('⚡ from cache')
		expect(block.find('.brp-panel__geheim-icon').exists()).toBe(false)
		expect(block.findAll('dd').map((dd) => dd.text())).toEqual(['1980-01-02', '-', '-'])
		expect(block.findAll('.brp-panel__address > div').map((div) => div.text())).toEqual(['Markt 1a', '1234 AB Utrecht'])
	})

	it('names the country of an address abroad, and shows no address block without one', async () => {
		const abroad = await lookedUp({ surname: 'Smit', residence: { straat: 'Rue', huisnummer: 2, postcode: '75001', woonplaats: 'Paris', land: 'Frankrijk' } })
		expect(abroad.findAll('.brp-panel__address > div').at(-1).text()).toBe('Frankrijk')
		expect(abroad.find('.brp-panel__cache-badge').exists()).toBe(false)

		const none = await lookedUp({ surname: 'Smit' })
		expect(none.find('.brp-panel__address').exists()).toBe(false)
	})

	it('hides a confidential address behind the reveal button and shows the revealed one', async () => {
		const wrapper = await lookedUp({ surname: 'Geheim', indicationSecret: '1', residence: { straat: 'Oud', huisnummer: 9 } })

		expect(wrapper.find('.brp-panel__geheim-icon').attributes('title')).toBe('Confidentiality active')
		expect(wrapper.find('.brp-panel__secret span').text()).toBe('[SECRET]')
		expect(wrapper.find('.brp-panel__address').exists()).toBe(false)

		axiosPost.mockResolvedValueOnce({ data: { residence: { straat: 'Nieuw', huisnummer: 3, postcode: '9999 ZZ', woonplaats: 'Zwolle' } } })
		await wrapper.find('.brp-panel__secret button').trigger('click')
		await flushPromises()

		expect(axiosPost).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/brp/contact/c-1/reveal-address')
		expect(wrapper.find('.brp-panel__secret').exists()).toBe(false)
		expect(wrapper.findAll('.brp-panel__address > div').map((div) => div.text())).toEqual(['Nieuw 3', '9999 ZZ Zwolle'])
	})

	it('falls back to the stored address when the reveal answer has none', async () => {
		const wrapper = await lookedUp({ surname: 'Geheim', indicationSecret: '1', residence: { straat: 'Oud', huisnummer: 9 } })

		axiosPost.mockResolvedValueOnce({ data: {} })
		await wrapper.find('.brp-panel__secret button').trigger('click')
		await flushPromises()

		expect(wrapper.find('.brp-panel__address div').text()).toBe('Oud 9')
	})
})

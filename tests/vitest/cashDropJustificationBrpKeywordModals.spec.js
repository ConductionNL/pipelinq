// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Four small dialogs and what they hand back: the cash drop (amount and
 * reason), the large-commitment justification, the BRP purpose limitation
 * before a lookup, and confirming a keyword proposal into a target.
 *
 * @spec openspec/specs/bsn-validatie-en-brp-lookup/spec.md#REQ-BSN-002
 * @spec openspec/specs/marketing-keyword-intelligence/spec.md#requirement-a-proposal-becomes-a-keyword-target-only-when-a-person-confirms-it
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const axiosMock = vi.hoisted(() => ({ post: vi.fn() }))

/**
 * A stand-in that keeps its props on the element and renders its slots.
 *
 * @param {string} name The component name.
 * @return {object} The component.
 */
function stub(name) {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'disabled', 'error', 'helperText', 'name', 'label', 'inputLabel'],
		emits: ['update:modelValue', 'click', 'closing'],
		render() {
			return h('div', { 'data-stub': name }, [
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

vi.mock('@nextcloud/axios', () => ({ default: axiosMock }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => '/index.php' + path }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: stub('CnDialog') }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
	NcSelect: stub('NcSelect'),
	NcTextArea: stub('NcTextArea'),
	NcTextField: stub('NcTextField'),
}))

/**
 * Translate as the identity.
 *
 * @param {string} app The app id.
 * @param {string} text The text.
 * @return {string} The text.
 */
const t = (app, text) => text
globalThis.t = t

const { default: CashShiftDropDialog } = await import('../../src/modals/CashShiftDropDialog.vue')
const { default: CommitJustificationModal } = await import('../../src/modals/CommitJustificationModal.vue')
const { default: BrpDoelbindingModal } = await import('../../src/modals/BrpDoelbindingModal.vue')
const { default: KeywordTargetConfirmModal } = await import('../../src/modals/KeywordTargetConfirmModal.vue')

/**
 * Mount with the translation helper.
 *
 * @param {object} component The component.
 * @param {object} [props] Its props.
 * @return {object} The wrapper.
 */
function mountIt(component, props = {}) {
	return mount(component, { props, global: { mocks: { t } } })
}

/**
 * The stand-ins of one component.
 *
 * @param {object} w The wrapper.
 * @param {string} name The component name.
 * @return {Array<object>} The stand-ins.
 */
function all(w, name) {
	return w.findAllComponents({ name })
}

beforeEach(() => {
	vi.clearAllMocks()
})

describe('CashShiftDropDialog', () => {
	it.each(['', 'abc', '0', '-5'])('refuses the amount %p and says why', async (amount) => {
		const w = mountIt(CashShiftDropDialog)
		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', amount)

		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(w.emitted('confirm')).toBeUndefined()
		const field = all(w, 'NcTextField')[0]
		expect(field.props('error')).toBe(true)
		expect(field.props('helperText')).toBe('Enter an amount greater than zero')
	})

	it('records the amount and the chosen reason', async () => {
		const w = mountIt(CashShiftDropDialog)
		const select = all(w, 'NcSelect')[0]
		expect(select.props('options').map((o) => o.id)).toEqual(['manager-deposit', 'bank-run', 'security-removal', 'other'])

		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', '125.50')
		await select.vm.$emit('update:modelValue', select.props('options')[1])
		await all(w, 'NcButton')[1].vm.$emit('click')

		expect(w.emitted('confirm')).toEqual([[{ amount: 125.5, reason: 'bank-run' }]])
		expect(all(w, 'NcTextField')[0].props('helperText')).toBe('')
	})

	it('records an empty reason when none is chosen, disables recording while submitting, and closes', async () => {
		const w = mountIt(CashShiftDropDialog, { submitting: true })
		expect(all(w, 'NcButton')[1].props('disabled')).toBe(true)

		await all(w, 'NcTextField')[0].vm.$emit('update:modelValue', '10')
		await all(w, 'NcButton')[1].vm.$emit('click')
		await all(w, 'NcButton')[0].vm.$emit('click')
		await all(w, 'CnDialog')[0].vm.$emit('closing')

		expect(w.emitted('confirm')).toEqual([[{ amount: 10, reason: '' }]])
		expect(w.emitted('close')).toEqual([[], []])
	})
})

describe('CommitJustificationModal', () => {
	it('needs ten characters before it saves, and saves them trimmed', async () => {
		const w = mountIt(CommitJustificationModal, { initialReason: '  short  ' })
		const save = () => all(w, 'NcButton')[1]
		expect(all(w, 'NcTextArea')[0].props('modelValue')).toBe('  short  ')
		expect(save().props('disabled')).toBe(true)
		expect(all(w, 'NcTextArea')[0].props('error')).toBe(false)

		await save().vm.$emit('click')
		expect(w.emitted('save')).toBeUndefined()
		expect(all(w, 'NcTextArea')[0].props('error')).toBe(true)
		expect(all(w, 'NcTextArea')[0].props('helperText')).toBe('Please enter at least 10 characters.')

		await all(w, 'NcTextArea')[0].vm.$emit('update:modelValue', '  Contract draft signed  ')
		expect(save().props('disabled')).toBe(false)
		expect(all(w, 'NcTextArea')[0].props('error')).toBe(false)
		await save().vm.$emit('click')

		expect(w.emitted('save')).toEqual([['Contract draft signed']])
	})

	it('starts empty and closes', async () => {
		const w = mountIt(CommitJustificationModal)

		expect(all(w, 'NcTextArea')[0].props('modelValue')).toBe('')
		await all(w, 'NcButton')[0].vm.$emit('click')
		expect(w.emitted('close')).toEqual([[]])
	})
})

describe('BrpDoelbindingModal', () => {
	/**
	 * Fill the two required choices.
	 *
	 * @param {object} w The wrapper.
	 * @param {string} reason The request reason.
	 * @param {string} binding The legal basis.
	 * @return {Promise<void>}
	 */
	async function choose(w, reason, binding) {
		const [reasons, bindings] = all(w, 'NcSelect')
		await reasons.vm.$emit('update:modelValue', reason)
		await bindings.vm.$emit('update:modelValue', binding)
	}

	it('cannot retrieve until a reason and a legal basis are chosen', async () => {
		const w = mountIt(BrpDoelbindingModal)
		const retrieve = () => all(w, 'NcButton')[1]
		expect(retrieve().props('disabled')).toBe(true)

		await retrieve().vm.$emit('click')
		await w.find('form').trigger('submit')
		expect(w.emitted('submit')).toBeUndefined()

		await all(w, 'NcSelect')[0].vm.$emit('update:modelValue', 'VOG-screening')
		expect(retrieve().props('disabled')).toBe(true)
		await all(w, 'NcSelect')[1].vm.$emit('update:modelValue', 'Rechtmatig belang')
		expect(retrieve().props('disabled')).toBe(false)
	})

	it('offers the reasons and legal bases by value', () => {
		const w = mountIt(BrpDoelbindingModal)
		const [reasons, bindings] = all(w, 'NcSelect')

		expect(reasons.props('options').map((o) => o.value)).toEqual([
			'Behandeling AVG-inzageverzoek art. 15',
			'Behandeling AVG-verwijderverzoek art. 17',
			'VOG-screening',
			'Reguliere verzoekbehandeling',
			'Overig',
		])
		expect(bindings.props('options').map((o) => o.value)).toEqual([
			'Publieke taak — Wet BRP art. 3.3',
			'AVG art. 6 lid 1 sub e',
			'Rechtmatig belang',
			'Overig',
		])
		expect(bindings.props('options')[1].label).toBe('AVG art. 6 lid 1 sub e (publieke taak)')
	})

	it('adds a note of twenty characters or more to the reason, and the VOG flag', async () => {
		const w = mountIt(BrpDoelbindingModal)
		await choose(w, 'Overig', 'AVG art. 6 lid 1 sub e')
		await all(w, 'NcTextArea')[0].vm.$emit('update:modelValue', 'Inzage gevraagd door betrokkene')
		await all(w, 'NcCheckboxRadioSwitch')[0].vm.$emit('update:modelValue', true)

		await w.find('form').trigger('submit')

		expect(w.emitted('submit')).toEqual([[{
			verzoekreden: 'Overig — Inzage gevraagd door betrokkene',
			purposeBinding: 'AVG art. 6 lid 1 sub e',
			basis: 'AVG art. 6 lid 1 sub e',
			vogScreening: true,
		}]])
	})

	it('leaves a shorter note out of the reason', async () => {
		const w = mountIt(BrpDoelbindingModal)
		await choose(w, 'VOG-screening', 'Rechtmatig belang')
		await all(w, 'NcTextArea')[0].vm.$emit('update:modelValue', 'te kort')

		await all(w, 'NcButton')[1].vm.$emit('click')
		await all(w, 'NcButton')[0].vm.$emit('click')

		expect(w.emitted('submit')[0][0]).toEqual({
			verzoekreden: 'VOG-screening',
			purposeBinding: 'Rechtmatig belang',
			basis: 'Rechtmatig belang',
			vogScreening: false,
		})
		expect(w.emitted('close')).toEqual([[]])
	})
})

describe('KeywordTargetConfirmModal', () => {
	it('starts on use more with the proposal top page, and confirms the choice', async () => {
		axiosMock.post.mockResolvedValue({ data: {} })
		const w = mountIt(KeywordTargetConfirmModal, {
			proposal: { query: '  woo verzoek  ', topPage: 'https://gemeente.nl/woo' },
			kind: 'striking-distance',
		})

		expect(w.find('.keyword-confirm__term').text()).toBe('woo verzoek')
		const [status, intent] = all(w, 'NcSelect')
		expect(status.props('modelValue').id).toBe('use-more')
		expect(all(w, 'NcTextField')[0].props('modelValue')).toBe('https://gemeente.nl/woo')

		await intent.vm.$emit('update:modelValue', intent.props('options')[3])
		await all(w, 'NcTextArea')[0].vm.$emit('update:modelValue', 'Top 3 halen')
		await all(w, 'NcButton')[1].vm.$emit('click')
		await flushPromises()

		expect(axiosMock.post).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/marketing/keyword-targets', {
			term: 'woo verzoek',
			status: 'use-more',
			proposalKind: 'striking-distance',
			intent: 'transactional',
			targetPageRef: 'https://gemeente.nl/woo',
			property: '',
			notes: 'Top 3 halen',
		})
		expect(w.emitted('confirmed')).toEqual([['woo verzoek']])
	})

	it('cannot add a proposal without a term', () => {
		const w = mountIt(KeywordTargetConfirmModal, { proposal: { term: '   ' } })

		expect(all(w, 'NcButton')[1].props('disabled')).toBe(true)
		expect(all(w, 'NcTextField')[0].props('modelValue')).toBe('')
	})

	it('shows the server error, or a general one, and stays open', async () => {
		axiosMock.post.mockRejectedValueOnce({ response: { data: { error: 'Target exists' } } })
		axiosMock.post.mockRejectedValueOnce(new Error('offline'))
		const w = mountIt(KeywordTargetConfirmModal, { proposal: { term: 'afval' } })

		await all(w, 'NcButton')[1].vm.$emit('click')
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toBe('Target exists')

		await all(w, 'NcButton')[1].vm.$emit('click')
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toBe('The keyword target could not be added.')
		expect(w.emitted('confirmed')).toBeUndefined()
		expect(all(w, 'NcButton')[1].props('disabled')).toBe(false)

		await all(w, 'CnDialog')[0].vm.$emit('closing')
		expect(w.emitted('close')).toEqual([[]])
	})
})

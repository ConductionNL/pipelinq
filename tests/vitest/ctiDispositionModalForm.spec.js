// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The call outcome dialog as filled in: the subject shows its error only
 * once left empty, the escalation queue appears only for Escalated, the
 * facts list names the client, the call length and the recorded moment
 * (linked when there is a link), and saving hands the form back only when
 * subject and outcome are both there.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md
 */
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

vi.mock('@nextcloud/l10n', () => ({
	n: (app, one, many, count) =>
		(count === 1 ? one : many).replace('%n', String(count)),
}))
vi.mock('vue-material-design-icons/Check.vue', () => ({
	default: { render: () => h('i') },
}))
function field (name) {
  return {
	name,
	props: ['modelValue', 'error', 'options', 'value'],
	emits: ['update:modelValue', 'blur'],
	render() {
		return h('div', { class: name }, this.$slots.default?.())
	},
}
}
vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['disabled', 'variant'],
		emits: ['click'],
		render() {
			return h(
				'button',
				{ disabled: this.disabled, onClick: () => this.$emit('click') },
				this.$slots.default?.(),
			)
		},
	},
	NcCheckboxRadioSwitch: field('NcCheckboxRadioSwitch'),
	NcSelect: field('NcSelect'),
	NcTextArea: field('NcTextArea'),
	NcTextField: field('NcTextField'),
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: {
		name: 'CnDialog',
		props: ['eyebrow'],
		emits: ['closing'],
		render() {
			return h('div', [this.$slots.default?.(), this.$slots.actions?.()])
		},
	},
}))

const t = (app, text) => text
globalThis.t = t

const { default: CtiDispositionModal } =
	await import('../../src/modals/CtiDispositionModal.vue')

function byId (wrapper, id) {
  return wrapper.findAllComponents(`[data-testid="cti-disposition-${id}"]`)[0]
}
function radio (wrapper, value) {
  return wrapper
		.findAllComponents({ name: 'NcCheckboxRadioSwitch' })
		.find((r) => r.props('value') === value)
}

describe('the call outcome dialog', () => {
	it('shows the subject error only once the field was left empty', async () => {
		const wrapper = mount(CtiDispositionModal, { global: { mocks: { t } } })
		const subject = byId(wrapper, 'subject')
		expect(subject.props('error')).toBe(false)
		await subject.vm.$emit('blur')
		expect(subject.props('error')).toBe(true)
		await subject.vm.$emit('update:modelValue', 'Paspoort')
		expect(subject.props('error')).toBe(false)
	})

	it('offers the queue only for an escalated call with queues to pick', async () => {
		const queues = [{ value: 'q-1', label: 'Second line' }]
		const wrapper = mount(CtiDispositionModal, {
			props: { queueOptions: queues },
			global: { mocks: { t } },
		})
		await radio(wrapper, 'resolved').vm.$emit('update:modelValue', 'resolved')
		expect(wrapper.find('.cti-disposition__queue').exists()).toBe(false)
		await radio(wrapper, 'escalated').vm.$emit('update:modelValue', 'escalated')
		expect(byId(wrapper, 'queue').props('options')).toEqual(queues)

		const none = mount(CtiDispositionModal, { global: { mocks: { t } } })
		await radio(none, 'escalated').vm.$emit('update:modelValue', 'escalated')
		expect(none.find('.cti-disposition__queue').exists()).toBe(false)
	})

	it('lists the client, the call length and the recorded moment', () => {
		const wrapper = mount(CtiDispositionModal, {
			props: {
				clientName: 'Anna',
				durationSeconds: 125,
				recordedAs: 'Contact moment',
				recordedHref: '/cm/1',
			},
			global: { mocks: { t } },
		})
		const terms = wrapper
			.findAll('[data-testid="cti-disposition-facts"] dt')
			.map((d) => d.text())
		const values = wrapper
			.findAll('[data-testid="cti-disposition-facts"] dd')
			.map((d) => d.text())
		expect(terms).toEqual(['Client', 'Duration', 'Recorded as'])
		expect(values).toEqual(['Anna', '2 minutes 5 seconds', 'Contact moment'])
		expect(
			wrapper
				.find('[data-testid="cti-disposition-facts"] a')
				.attributes('href'),
		).toBe('/cm/1')
	})

	it('shows a recorded moment without a link as text, and no facts without any', () => {
		const plain = mount(CtiDispositionModal, {
			props: { recordedAs: 'Note' },
			global: { mocks: { t } },
		})
		expect(plain.find('[data-testid="cti-disposition-facts"] a').exists()).toBe(
			false,
		)
		expect(plain.find('[data-testid="cti-disposition-facts"] dd').text()).toBe(
			'Note',
		)
		const bare = mount(CtiDispositionModal, { global: { mocks: { t } } })
		expect(bare.find('[data-testid="cti-disposition-facts"]').exists()).toBe(
			false,
		)
	})

	it('saves the filled form only with a subject and an outcome', async () => {
		const wrapper = mount(CtiDispositionModal, { global: { mocks: { t } } })
		const save = wrapper.find('[data-testid="cti-disposition-submit"]')
		expect(save.attributes('disabled')).toBeDefined()

		await wrapper.find('form').trigger('submit')
		expect(wrapper.emitted('submit')).toBeUndefined()
		expect(byId(wrapper, 'subject').props('error')).toBe(true)

		await byId(wrapper, 'subject').vm.$emit('update:modelValue', 'Paspoort')
		await radio(wrapper, 'callback').vm.$emit('update:modelValue', 'callback')
		await byId(wrapper, 'notes').vm.$emit(
			'update:modelValue',
			'Call back Friday',
		)
		expect(save.attributes('disabled')).toBeUndefined()
		await save.trigger('click')
		expect(wrapper.emitted('submit')).toEqual([
			[
				{
					subject: 'Paspoort',
					outcome: 'callback',
					notes: 'Call back Friday',
					queue: '',
				},
			],
		])
	})

	it('keeps save off while saving, and closes from cancel', async () => {
		const wrapper = mount(CtiDispositionModal, {
			props: { saving: true },
			global: { mocks: { t } },
		})
		await byId(wrapper, 'subject').vm.$emit('update:modelValue', 'X')
		await radio(wrapper, 'resolved').vm.$emit('update:modelValue', 'resolved')
		expect(
			wrapper
				.find('[data-testid="cti-disposition-submit"]')
				.attributes('disabled'),
		).toBeDefined()
		await wrapper.find('[data-testid="cti-disposition-cancel"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BRP identity check: the check, reason and purpose-binding fields reach
 * the lookup, a nameless person is still recorded, a refusal is shown, a
 * submitted form looks up, and Cancel / Close and the dialog closing close it.
 *
 * @spec openspec/specs/brp-lookup/spec.md#requirement-a-brp-lookup-can-verify-a-clients-identity
 */
import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ post: vi.fn() }))
const bus = vi.hoisted(() => ({ emit: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/event-bus', () => bus)
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { 'data-testid': this.$attrs['data-testid'], disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/BrpClientCheckDialog.vue')

const at = (w, id) => w.find(`[data-testid="brp-check-${id}"]`)

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
})

describe('BrpClientCheckDialog fields', () => {
	it('sends the check, reason and binding chosen, records a nameless person and refreshes the page', async () => {
		http.post.mockResolvedValue({ data: {} })
		const w = mount(Dialog, { props: { clientId: 'client-1' } })

		await at(w, 'identity-check').setValue('  ID card shown  ')
		await at(w, 'bsn').setValue('999994001')
		await at(w, 'reason').setValue('Overig')
		await at(w, 'binding').setValue('AVG art. 6 lid 1 sub e')
		await w.find('form').trigger('submit')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/brp/lookup', {
			bsn: '999994001',
			verzoekreden: 'Overig',
			purposeBinding: 'AVG art. 6 lid 1 sub e',
			verifiedClient: 'client-1',
			identityCheck: 'ID card shown',
		})
		expect(at(w, 'found').text()).toBe('Found in the BRP: the person. The identity is recorded as verified.')
		expect(at(w, 'bsn').element.value).toBe('')
		expect(at(w, 'lookup').exists()).toBe(false)
		expect(at(w, 'cancel').text()).toBe('Close')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('does not look up an incomplete BSN, and shows the BRP refusal or the fallback', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { errorMessage: 'No purpose binding' } } }).mockRejectedValueOnce(new Error('down'))
		const w = mount(Dialog)

		await at(w, 'bsn').setValue('12345')
		await w.find('form').trigger('submit')
		expect(http.post).not.toHaveBeenCalled()

		await at(w, 'bsn').setValue('999994001')
		await at(w, 'lookup').trigger('click')
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toBe('No purpose binding')
		await at(w, 'lookup').trigger('click')
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toBe('The BRP could not be asked. Try again.')
	})

	it('closes from Cancel and when the dialog closes', async () => {
		const w = mount(Dialog)

		await at(w, 'cancel').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')

		expect(w.emitted('close')).toHaveLength(2)
	})
})

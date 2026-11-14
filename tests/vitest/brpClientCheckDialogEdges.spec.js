// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BRP check dialog closes from either door, sends nothing for a BSN that
 * is not nine digits, passes the reason and binding the employee picked,
 * explains a refused lookup and, once a person is found, only offers Close.
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
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { name: 'CnDialog', emits: ['closing'], render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/BrpClientCheckDialog.vue')

beforeEach(() => {
	http.post.mockReset()
	bus.emit.mockReset()
})

describe('BrpClientCheckDialog edges', () => {
	it('closes from Cancel and from the dialog itself', async () => {
		const w = mount(Dialog)
		await w.find('[data-testid="brp-check-cancel"]').trigger('click')
		w.findComponent({ name: 'CnDialog' }).vm.$emit('closing')
		expect(w.emitted('close')).toHaveLength(2)
		expect(w.find('[data-testid="brp-check-cancel"]').text()).toBe('Cancel')
	})

	it('sends nothing when the form is submitted with a BSN that is not nine digits', async () => {
		const w = mount(Dialog)
		await w.find('[data-testid="brp-check-bsn"]').setValue('12345')
		await w.find('form').trigger('submit')
		await flushPromises()
		expect(http.post).not.toHaveBeenCalled()
	})

	it('passes the chosen reason, binding and a trimmed identity check, and offers only Close after a find', async () => {
		http.post.mockResolvedValue({ data: {} })
		const w = mount(Dialog, { props: { clientId: 'client-1' } })
		await w.find('[data-testid="brp-check-identity-check"]').setValue('  Passport shown  ')
		await w.find('[data-testid="brp-check-reason"]').setValue('Overig')
		await w.find('[data-testid="brp-check-binding"]').setValue('AVG art. 6 lid 1 sub e')
		await w.find('[data-testid="brp-check-bsn"]').setValue('999994001')
		await w.find('form').trigger('submit')
		await flushPromises()

		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/brp/lookup', {
			bsn: '999994001',
			verzoekreden: 'Overig',
			purposeBinding: 'AVG art. 6 lid 1 sub e',
			verifiedClient: 'client-1',
			identityCheck: 'Passport shown',
		})
		expect(w.find('[data-testid="brp-check-found"]').text()).toBe('Found in the BRP: the person. The identity is recorded as verified.')
		expect(w.find('[data-testid="brp-check-lookup"]').exists()).toBe(false)
		expect(w.find('[data-testid="brp-check-cancel"]').text()).toBe('Close')
		expect(w.find('[data-testid="brp-check-bsn"]').element.value).toBe('')
		expect(bus.emit).toHaveBeenCalledWith('cn:page:refresh', {})
	})

	it('shows the server refusal, or a fallback, and lets the employee try again', async () => {
		http.post.mockRejectedValueOnce({ response: { data: { errorMessage: 'No person with this BSN.' } } })
		const w = mount(Dialog)
		await w.find('[data-testid="brp-check-bsn"]').setValue('999994001')
		await w.find('[data-testid="brp-check-lookup"]').trigger('click')
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toBe('No person with this BSN.')
		expect(w.find('[data-testid="brp-check-lookup"]').attributes('disabled')).toBeUndefined()
		expect(bus.emit).not.toHaveBeenCalled()

		http.post.mockRejectedValueOnce(new Error('network'))
		await w.find('[data-testid="brp-check-lookup"]').trigger('click')
		await flushPromises()
		expect(w.find('[role="alert"]').text()).toBe('The BRP could not be asked. Try again.')
	})
})

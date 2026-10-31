// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqWerkplekKlant "Wie is het · Opzoeken in de BRP": the employee looks
 * the caller up with what they checked, and the lookup names the client it
 * verifies, so the server records the verified identity on the client.
 *
 * @spec openspec/specs/brp-lookup/spec.md#requirement-a-brp-lookup-can-verify-a-clients-identity
 */
import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const http = vi.hoisted(() => ({ post: vi.fn(() => Promise.resolve({ data: { persoon: { givenNames: 'Sanne', namePrefix: 'de', surname: 'Vries' } } })) }))
vi.mock('@nextcloud/axios', () => ({ default: http }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('@nextcloud/event-bus', () => ({ emit: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({
	NcButton: { props: ['disabled'], emits: ['click'], render() { return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.()) } },
}))
vi.mock('@conduction/nextcloud-vue', () => ({
	CnDialog: { render() { return h('div', [this.$slots.default?.(), this.$slots.actions?.()]) } },
}))

const { default: Dialog } = await import('../../src/dialogs/BrpClientCheckDialog.vue')

describe('BrpClientCheckDialog', () => {
	it('looks the caller up for the client it verifies, with what was checked', async () => {
		const w = mount(Dialog, { props: { clientId: 'client-sanne' } })
		expect(w.find('[data-testid="brp-check-lookup"]').attributes('disabled')).toBeDefined()
		await w.find('[data-testid="brp-check-bsn"]').setValue('999994001')
		await w.find('[data-testid="brp-check-lookup"]').trigger('click')
		await flushPromises()
		expect(http.post).toHaveBeenCalledWith('/apps/pipelinq/api/brp/lookup', {
			bsn: '999994001',
			verzoekreden: 'Reguliere verzoekbehandeling',
			purposeBinding: 'Publieke taak — Wet BRP art. 3.3',
			verifiedClient: 'client-sanne',
			identityCheck: 'Date of birth and address asked',
		})
		expect(w.find('[data-testid="brp-check-found"]').text()).toContain('Sanne de Vries')
	})
})

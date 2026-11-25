// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The lead edit form is held to the stage limit (pipeline-stage-limit 1.5):
 * a save that moves the lead into a full stage is refused by the server with
 * a sentence naming the stage, and the form shows that sentence under the
 * stage field and stays open. Other errors stay form-level.
 *
 * @spec openspec/changes/pipeline-stage-limit/specs/pipeline-stage-limits/spec.md#requirement-no-write-puts-an-item-into-a-full-stage-req-psl-002
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'
import { stageRefusal } from '../../src/services/stageRefusal.js'

const setResult = vi.fn()
const setValidationErrors = vi.fn()

vi.mock('@conduction/nextcloud-vue', () => ({
	CnFormDialog: {
		name: 'CnFormDialog',
		props: ['schema', 'item', 'register'],
		emits: ['confirm', 'close'],
		methods: {
			setResult(result) {
				setResult(result)
			},
			setValidationErrors(fieldErrors, message) {
				setValidationErrors(fieldErrors, message)
			},
		},
		render() {
			return h('form', { 'data-testid': 'cn-form-dialog' })
		},
	},
}))
vi.mock('../../src/modals/SlipReasonModal.vue', () => ({
	default: { name: 'SlipReasonModal', render: () => h('div') },
}))

globalThis.t = (_app, text) => text

const { default: LeadEditDialog } = await import('../../src/dialogs/LeadEditDialog.vue')

const FULL = 'Stage Proposal is full (8 of 8)'
const lead = { id: 'l-1', title: 'Solar roof', stage: 'Qualified', forecastCategory: 'pipeline' }

/**
 * Mount the dialog with the page's save.
 *
 * @param {Function} confirm The page's save path.
 * @return {object} The wrapper.
 */
function mountDialog(confirm) {
	return mount(LeadEditDialog, {
		props: { show: true, item: lead, schema: { properties: {} }, confirm, close: vi.fn() },
		global: { mocks: { t: (_app, text) => text } },
	})
}

beforeEach(() => {
	setResult.mockReset()
	setValidationErrors.mockReset()
})

describe('stageRefusal', () => {
	it('returns the refusal when the edit moved into the stage it names', () => {
		expect(stageRefusal(lead, { stage: 'Proposal' }, FULL)).toBe(FULL)
	})

	it('returns null when the stage did not change', () => {
		expect(stageRefusal(lead, { stage: 'Qualified' }, 'Stage Qualified is full (3 of 3)')).toBeNull()
		expect(stageRefusal(lead, {}, FULL)).toBeNull()
	})

	it('returns null for an error that does not name the new stage, or no error', () => {
		expect(stageRefusal(lead, { stage: 'Proposal' }, 'The lead could not be saved.')).toBeNull()
		expect(stageRefusal(lead, { stage: 'Proposal' }, undefined)).toBeNull()
		expect(stageRefusal(lead, { stage: 'Proposal' }, '')).toBeNull()
	})

	it('treats a lead without a stage as moving into the new one', () => {
		expect(stageRefusal({ id: 'l-2' }, { stage: 'Proposal' }, FULL)).toBe(FULL)
		expect(stageRefusal(null, { stage: 'Proposal' }, FULL)).toBe(FULL)
	})
})

describe('the lead edit form and a full stage', () => {
	it('shows the refusal under the stage field and keeps the form open', async () => {
		const confirm = vi.fn().mockResolvedValue({ error: FULL })
		const wrapper = mountDialog(confirm)
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { ...lead, stage: 'Proposal' })
		await flushPromises()

		expect(confirm).toHaveBeenCalledWith({ ...lead, stage: 'Proposal' })
		expect(setValidationErrors).toHaveBeenCalledWith({ stage: FULL }, FULL)
		expect(setResult).not.toHaveBeenCalled()
	})

	it('reads the refusal from a rejected save too', async () => {
		const confirm = vi.fn().mockRejectedValue({ response: { data: { message: FULL } } })
		const wrapper = mountDialog(confirm)
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { ...lead, stage: 'Proposal' })
		await flushPromises()

		expect(setValidationErrors).toHaveBeenCalledWith({ stage: FULL }, FULL)
	})

	it('keeps another error form-level', async () => {
		const confirm = vi.fn().mockResolvedValue({ error: 'The lead could not be saved.' })
		const wrapper = mountDialog(confirm)
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { ...lead, stage: 'Proposal' })
		await flushPromises()

		expect(setValidationErrors).not.toHaveBeenCalled()
		expect(setResult).toHaveBeenCalledWith({ error: 'The lead could not be saved.' })
	})

	it('hands a successful save to the form as before', async () => {
		const confirm = vi.fn().mockResolvedValue(undefined)
		const wrapper = mountDialog(confirm)
		wrapper.findComponent({ name: 'CnFormDialog' }).vm.$emit('confirm', { ...lead, stage: 'Proposal' })
		await flushPromises()

		expect(setResult).toHaveBeenCalledWith({ success: true })
		expect(setValidationErrors).not.toHaveBeenCalled()
	})
})

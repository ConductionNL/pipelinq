// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The prospect (ICP) settings form: what it reads into the form, what it
 * saves, and what it says about the save.
 *
 * @spec openspec/changes/reverse-2026-05-26-fe-settings-ui/tasks.md#task-65
 */
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => '/index.php' + p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcSettingsSection: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnSettingsSection: {} }))

let section

beforeAll(async () => {
	globalThis.t = (app, text) => text
	globalThis.OC = { requestToken: 'tok' }
	section = (await import('../../src/views/settings/ProspectSettings.vue')).default
}, 60000)

afterEach(() => {
	vi.unstubAllGlobals()
})

function bind(state) {
	const ctx = { ...section.data.call({}), ...state }
	for (const [name, fn] of Object.entries(section.methods)) {
		ctx[name] = fn.bind(ctx)
	}
	return ctx
}

function answer(...answers) {
	const queue = [...answers]
	vi.stubGlobal('fetch', vi.fn(async () => {
		const next = queue.shift()
		if (next instanceof Error) throw next
		return { ok: next.ok, json: async () => next.body }
	}))
}

const headers = { 'Content-Type': 'application/json', requesttoken: 'tok', 'OCS-APIREQUEST': 'true' }

describe('ProspectSettings', () => {
	it('reads the stored profile into the form, keeping only that a KvK key is configured', async () => {
		answer({ ok: true, body: { employeeCountMin: 5, employeeCountMax: 50, provinces: ['NH'], legalForms: ['BV'], excludeInactive: false, kvkApiKey: '***configured***', openCorporatesEnabled: true, sbiCodes: ['62', '63'], keywords: ['ict'] } })
		const ctx = bind({})
		await ctx.fetchSettings()
		expect(fetch).toHaveBeenCalledWith('/index.php/apps/pipelinq/api/prospects/settings', { headers })
		expect(ctx.form).toEqual({ employeeCountMin: 5, employeeCountMax: 50, provinces: ['NH'], legalForms: ['BV'], excludeInactive: false, kvkApiKey: '***configured***', openCorporatesEnabled: true })
		expect([ctx.sbiCodesText, ctx.keywordsText, ctx.loading]).toEqual(['62, 63', 'ict', false])
	})

	it('reads an empty profile with its defaults, and leaves the form on a refusal or failure', async () => {
		answer({ ok: true, body: { kvkApiKey: 'secret' } }, { ok: false }, new Error('x'))
		const ctx = bind({})
		await ctx.fetchSettings()
		expect(ctx.form).toEqual({ employeeCountMin: 0, employeeCountMax: 0, provinces: [], legalForms: [], excludeInactive: true, kvkApiKey: '', openCorporatesEnabled: false })
		expect([ctx.sbiCodesText, ctx.keywordsText]).toEqual(['', ''])
		const before = ctx.form
		await ctx.fetchSettings()
		await ctx.fetchSettings()
		expect(ctx.form).toBe(before)
		expect(ctx.loading).toBe(false)
	})

	it('saves the form with the codes and keywords as lists, without a masked key', async () => {
		answer({ ok: true }, { ok: true }, { ok: false }, new Error('x'))
		const ctx = bind({ form: { employeeCountMin: 1, kvkApiKey: '***configured***' }, sbiCodesText: ' 62, ,63 ', keywordsText: '' })
		await ctx.save()
		expect(fetch).toHaveBeenLastCalledWith('/index.php/apps/pipelinq/api/prospects/settings', { method: 'PUT', headers, body: JSON.stringify({ employeeCountMin: 1, sbiCodes: ['62', '63'], keywords: [] }) })
		expect([ctx.message, ctx.messageType, ctx.saving]).toEqual(['ICP settings saved successfully', 'success', false])
		ctx.form = { kvkApiKey: 'new-key' }
		await ctx.save()
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ kvkApiKey: 'new-key', sbiCodes: ['62', '63'], keywords: [] })
		await ctx.save()
		expect([ctx.message, ctx.messageType]).toEqual(['Failed to save ICP settings', 'error'])
		ctx.message = ''
		await ctx.save()
		expect([ctx.message, ctx.messageType, ctx.saving]).toEqual(['Failed to save ICP settings', 'error', false])
	})
})

// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The blast wizard's review step: each row it lists, the step that sets it,
 * and the value it shows.
 *
 * @spec openspec/specs/marketing-ui/spec.md#requirement-blast-creation-wizard-gates-on-compliance
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {}, CnStepper: {} }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showInfo: vi.fn() }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (p) => p }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcDateTimePickerNative: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextArea: {}, NcTextField: {} }))
vi.mock('../../src/modals/MissingConsentModal.vue', () => ({ default: {} }))

let wizard

beforeAll(async () => {
	wizard = (await import('../../src/dialogs/BlastWizardDialog.vue')).default
}, 60000)

const t = (app, text, vars = {}) => text.replace(/\{(\w+)\}/g, (m, k) => vars[k] ?? m)

/**
 * @param {object} state The wizard state, computed values given as plain values.
 * @return {Array<object>} The review rows.
 */
function rows(state) {
	const ctx = { t, ...state }
	for (const [name, fn] of Object.entries(wizard.methods || {})) {
		if (!(name in ctx)) {
			ctx[name] = fn.bind(ctx)
		}
	}
	return wizard.computed.reviewRows.call(ctx)
}

const base = {
	audienceKind: 'list',
	selectedList: { name: 'Leden' },
	selectedSegment: null,
	channelOptions: [{ value: 'email', label: 'E-mail' }],
	selectedChannel: 'email',
	scheduleMode: 'now',
	scheduledForDate: null,
	abEnabled: false,
	model: { name: ' Mei ', abSplitPercent: 30 },
	selectedTemplate: { name: 'Nieuwsbrief' },
	selectedTransport: { label: 'SMTP' },
	selectedConnectorSource: { label: 'Mailgun' },
}

describe('BlastWizardDialog.reviewRows', () => {
	it('lists every choice with the step that sets it', () => {
		expect(rows(base)).toEqual([
			{ key: 'name', step: 'basics', label: 'Name', value: 'Mei' },
			{ key: 'channel', step: 'basics', label: 'Channel', value: 'E-mail' },
			{ key: 'audience', step: 'audience', label: 'Audience', value: 'Mailing list: Leden' },
			{ key: 'template', step: 'content', label: 'Template', value: 'Nieuwsbrief' },
			{ key: 'transport', step: 'delivery', label: 'Send through', value: 'SMTP' },
			{ key: 'source', step: 'delivery', label: 'Connector source', value: 'Mailgun' },
			{ key: 'when', step: 'delivery', label: 'When', value: 'As soon as the blast is created' },
			{ key: 'ab', step: 'ab', label: 'A/B test', value: 'Off' },
		])
	})

	it('reads a segment, a scheduled moment, an A/B split and the defaults', () => {
		const date = new Date(2026, 10, 10, 9, 30)
		const values = rows({
			...base,
			audienceKind: 'segment',
			selectedSegment: { name: 'Actief' },
			selectedChannel: 'sms',
			scheduleMode: 'later',
			scheduledForDate: date,
			abEnabled: true,
			selectedTemplate: null,
			selectedTransport: null,
			selectedConnectorSource: null,
		}).map((row) => row.value)
		expect(values).toEqual(['Mei', '', 'Segment: Actief', 'None', 'Default transport', 'None', date.toLocaleString(), 'Variant A 30%, variant B 70%'])
		expect(rows({ ...base, selectedList: null, scheduleMode: 'later' })[2].value).toBe('Mailing list: ')
		expect(rows({ ...base, audienceKind: 'segment' })[2].value).toBe('Segment: ')
		expect(rows({ ...base, scheduleMode: 'later' })[6].value).toBe('As soon as the blast is created')
	})
})

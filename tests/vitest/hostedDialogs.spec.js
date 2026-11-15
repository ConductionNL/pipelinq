/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The four marketing form dialogs a manifest page opens (campaign, journey,
 * segment, template): the props the host passes, no attribute fallthrough,
 * and a load each time the dialog opens.
 *
 * @spec openspec/changes/marketing-campaigns/specs/marketing-campaigns/spec.md#requirement-a-campaign-owns-its-campaign-value-and-its-channel-vocabulary
 */
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({ CnDialog: {} }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcDateTimePickerNative: {}, NcLoadingIcon: {}, NcNoteCard: {}, NcSelect: {}, NcTextArea: {}, NcTextField: {} }))
vi.mock('@nextcloud/axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }))
vi.mock('@nextcloud/dialogs', () => ({ showSuccess: vi.fn(), showWarning: vi.fn(), showError: vi.fn() }))
vi.mock('../../src/components/SegmentBuilder.vue', () => ({ default: {} }))
vi.mock('../../src/components/templates/MailBlockEditor.vue', () => ({ default: {} }))

const names = ['CampaignFormDialog', 'JourneyFormDialog', 'SegmentFormDialog', 'TemplateFormDialog']
/** What each dialog runs when it opens: the two edit forms read their record, the other two reset. */
const onOpen = { CampaignFormDialog: 'load', JourneyFormDialog: 'load', SegmentFormDialog: 'reset', TemplateFormDialog: 'reset' }
const dialogs = {}

beforeAll(async () => {
	globalThis.t = (app, text) => text
	for (const name of names) {
		dialogs[name] = (await import(`../../src/dialogs/${name}.vue`)).default
	}
}, 60000)

/**
 * @param {object} component A component.
 * @param {string} key An option.
 * @return {Array} The option as the component and its mixins declare it.
 */
function declared(component, key) {
	return [...(component.mixins || []), component].map((part) => part[key]).filter((value) => value !== undefined)
}

describe.each(names)('%s', (name) => {
	it('takes show, item, close and refresh, and lets no attribute fall through', () => {
		const props = Object.assign({}, ...declared(dialogs[name], 'props'))
		expect(Object.keys(props).slice(0, 4).sort()).toEqual(['close', 'item', 'refresh', 'show'])
		expect(props.show).toEqual({ type: Boolean, default: false })
		expect(props.item).toEqual({ type: Object, default: null })
		expect(props.close.type).toBe(Function)
		expect(props.close.default()).toBeUndefined()
		expect(props.refresh).toEqual({ type: Function, default: null })
		expect(declared(dialogs[name], 'inheritAttrs')).toContain(false)
	})

	it('loads each time it opens, right away when it opens open', () => {
		const watchers = declared(dialogs[name], 'watch').map((watch) => watch.show).filter(Boolean)
		expect(watchers).toHaveLength(1)
		expect(watchers[0].immediate).toBe(true)
		const ctx = { load: vi.fn(), reset: vi.fn() }
		for (const methods of declared(dialogs[name], 'methods')) {
			for (const [method, fn] of Object.entries(methods)) {
				if (!(method in ctx)) {
					ctx[method] = fn.bind(ctx)
				}
			}
		}
		watchers[0].handler.call(ctx, false)
		expect(ctx.load).not.toHaveBeenCalled()
		expect(ctx.reset).not.toHaveBeenCalled()
		watchers[0].handler.call(ctx, true)
		expect(ctx[onOpen[name]]).toHaveBeenCalledTimes(1)
		expect(ctx[onOpen[name] === 'load' ? 'reset' : 'load']).not.toHaveBeenCalled()
	})
})

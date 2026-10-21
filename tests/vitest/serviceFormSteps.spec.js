// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Creating a service keeps the product of its composition steps
 * (round3-review-points, review point 3), now that a new service is made in
 * the same dialog as an edit and the steps are rows of the library row editor.
 * The resource and service forms edit their rows with CnSubObjectsField, and
 * the booking row editors are gone.
 *
 * @spec openspec/changes/round3-review-points/specs/appointment-booking/spec.md
 * @spec openspec/changes/pl2-booking-row-editors/specs/appointment-booking/spec.md#requirement-booking-rows-are-edited-with-the-library-row-editor
 */
import { existsSync } from 'node:fs'
import { resolve } from 'node:path'
import { beforeAll, describe, expect, it, vi } from 'vitest'

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailCard: {},
	CnDetailPage: {},
	CnFormDialog: {},
	CnStatusBadge: {},
	CnSubObjectsField: {},
	useObjectSubscription: vi.fn(),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: vi.fn(), showSuccess: vi.fn() }))
vi.mock('@nextcloud/vue', () => ({ NcButton: {} }))
vi.mock('@nextcloud/l10n', () => ({ translate: (_app, text) => text }))
vi.mock('@nextcloud/initial-state', () => ({ loadState: () => ({ currency: 'EUR' }) }))
vi.mock('../../src/dialogs/DeleteServiceDialog.vue', () => ({ default: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))

let methods
let serializeStep
let serviceStepsOverride
let resourceRowOverrides

beforeAll(async () => {
	globalThis.t = (_app, text) => text
	methods = (await import('../../src/views/bookings/ServiceDetail.vue')).default.methods;
	({ serializeStep, serviceStepsOverride } = await import('../../src/services/serviceSteps.js'));
	({ resourceRowOverrides } = await import('../../src/utils/resourceValidation.js'))
})

const step = {
	durationMinutes: 480,
	resourceType: 'staff',
	skillRequired: '',
	allowGap: false,
	productId: 'prod-1',
	quantity: 8,
	unit: 'hour',
}

describe('the New service dialog', () => {
	it('saves the product, quantity and unit of a step', async () => {
		const saveObject = vi.fn(async (_type, data) => ({ ...data, id: 'svc-1' }))
		const ctx = {
			$refs: { createDialog: { setResult: vi.fn() } },
			$router: { push: vi.fn() },
			objectStore: { saveObject },
			created: false,
		}
		await methods.onFormSave.call(ctx, { name: 'Implementatie', durationMinutes: 480, multiStep: [{ ...step, quantity: '8' }] })
		const [, payload] = saveObject.mock.calls[0]
		expect(payload.multiStep).toEqual([step])
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'ServiceDetail', params: { id: 'svc-1' } })
		methods.onFormCancel.call(ctx)
		expect(ctx.$router.push).toHaveBeenCalledTimes(1)
	})
})

describe('serializeStep', () => {
	it('leaves out an empty product, quantity and an unknown unit', () => {
		expect(
			serializeStep({
				durationMinutes: '15',
				productId: '',
				quantity: '',
				unit: 'weird',
			}),
		).toEqual({
			durationMinutes: 15,
			resourceType: 'staff',
			skillRequired: '',
			allowGap: false,
		})
		expect(serializeStep(null).durationMinutes).toBe(0)
	})
})

describe('the booking rows', () => {
	it('edits a service\'s steps as rows that add up against the duration, the product from the catalogue', () => {
		const override = serviceStepsOverride()
		expect(override.widget).toBe('sub-objects')
		expect(override.summary).toBe('{sum:durationMinutes} of {@object.durationMinutes} min')
		expect(override.itemOverrides.productId.optionsFrom).toEqual({ register: 'pipelinq', schema: 'product', labelField: 'name' })
	})

	it('edits working hours and vacations in the row, with their rules', () => {
		const { workingHours, vacations } = resourceRowOverrides()
		expect(workingHours).toMatchObject({ widget: 'sub-objects', inline: true })
		expect(workingHours.itemOverrides.openTime.format).toBe('time')
		expect(workingHours.rowRules[0].message).toBe('Open time must be before close time.')
		expect(vacations.rowRules[0].when.all[2]).toEqual({ field: 'startDate', op: 'gt', value: '@row.endDate' })
	})

	it.each([
		'src/components/bookings/BookingRowsEditor.vue',
		'src/components/bookings/ResourceHoursEditor.vue',
		'src/components/bookings/ResourceVacationsEditor.vue',
		'src/components/bookings/ServiceStepsEditor.vue',
		'src/views/bookings/ResourceForm.vue',
		'src/views/bookings/ServiceForm.vue',
	])('%s is deleted', (file) => {
		expect(existsSync(resolve(__dirname, '../..', file))).toBe(false)
	})
})

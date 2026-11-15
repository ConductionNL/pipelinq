// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * How the export destination and export job forms load an existing record,
 * save through the object store, test a destination's connection and offer
 * their options. The object store, export API and toasts are mocked.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const store = vi.hoisted(() => ({
	fetchObject: vi.fn(),
	saveObject: vi.fn(),
	fetchCollection: vi.fn(),
	getCollection: vi.fn(),
}))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))
const exportApi = vi.hoisted(() => ({ testDestination: vi.fn() }))

vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => store }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('../../src/services/exportApi.js', () => ({ exportApi }))
vi.mock('../../src/modals/ExportTestRunModal.vue', () => ({
	default: {
		name: 'ExportTestRunModal',
		render: () => h('div', { 'data-name': 'ExportTestRunModal' }),
	},
}))
vi.mock('@conduction/nextcloud-vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, this.$slots.default?.())
		},
	})
	return { CnDetailCard: stub('CnDetailCard'), CnDetailPage: stub('CnDetailPage') }
})
vi.mock('@nextcloud/vue', () => {
	const stub = (name) => ({
		name,
		inheritAttrs: false,
		render() {
			return h('div', { 'data-name': name }, this.$slots.default?.())
		},
	})
	return {
		NcButton: stub('NcButton'),
		NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
		NcSelect: stub('NcSelect'),
		NcTextField: stub('NcTextField'),
	}
})

globalThis.t = (_app, text) => text

const { default: ExportDestinationForm } =
	await import('../../src/views/export/ExportDestinationForm.vue')
const { default: ExportJobForm } =
	await import('../../src/views/export/ExportJobForm.vue')

/**
 * Mount a form with a router and route mock.
 *
 * @param {object} component The form.
 * @param {object} props The props.
 * @param {object} params The route params.
 * @return {{wrapper: object, push: Function}} The wrapper and router push.
 */
function mountForm(component, props = {}, params = {}) {
	const push = vi.fn()
	const wrapper = mount(component, {
		props,
		global: {
			mocks: {
				t: (_app, text) => text,
				$router: { push },
				$route: { params },
			},
		},
	})
	return { wrapper, push }
}

beforeEach(() => {
	for (const fn of [
		...Object.values(store),
		...Object.values(dialogs),
		exportApi.testDestination,
	]) {
		fn.mockReset()
	}
	store.fetchCollection.mockResolvedValue(undefined)
	store.getCollection.mockReturnValue([])
	store.saveObject.mockResolvedValue({})
})

describe('ExportDestinationForm', () => {
	it('starts as a new s3 destination without loading anything', async () => {
		const { wrapper } = mountForm(ExportDestinationForm)
		await flushPromises()
		expect(wrapper.vm.isEdit).toBe(false)
		expect(store.fetchObject).not.toHaveBeenCalled()
		expect(wrapper.vm.selectedType).toEqual({ id: 's3', label: 's3' })
		expect(wrapper.vm.selectedCompression).toEqual({ id: 'none', label: 'none' })
		expect(wrapper.vm.typeOptions.map((o) => o.id)).toEqual([
			's3',
			'azure',
			'gcs',
			'bigquery',
			'snowflake',
			'sftp',
			'postgres',
			'mail',
		])
		expect(wrapper.vm.compressionOptions.map((o) => o.id)).toEqual([
			'none',
			'gzip',
			'snappy',
			'zstd',
		])
	})

	it('takes its id from the route and merges the stored destination', async () => {
		store.fetchObject.mockResolvedValue({
			name: 'Lake',
			type: 'gcs',
			compression: 'zstd',
		})
		const { wrapper } = mountForm(ExportDestinationForm, {}, { id: 'd-1' })
		await flushPromises()
		expect(store.fetchObject).toHaveBeenCalledWith('exportDestination', 'd-1')
		expect(wrapper.vm.model).toMatchObject({
			name: 'Lake',
			type: 'gcs',
			compression: 'zstd',
			pathTemplate: '',
		})
		expect(wrapper.vm.selectedType.id).toBe('gcs')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('prefers the explicit prop over the route id', () => {
		const { wrapper } = mountForm(
			ExportDestinationForm,
			{ exportDestinationId: 'p-1', id: 'p-2' },
			{ id: 'r-1' },
		)
		expect(wrapper.vm.destinationId).toBe('p-1')
		const second = mountForm(ExportDestinationForm, { id: 'p-2' }, { id: 'r-1' })
		expect(second.wrapper.vm.destinationId).toBe('p-2')
	})

	it('has no selection for an unknown type or compression', () => {
		const { wrapper } = mountForm(ExportDestinationForm)
		wrapper.vm.model.type = 'ftp'
		wrapper.vm.model.compression = 'rar'
		expect(wrapper.vm.selectedType).toBeNull()
		expect(wrapper.vm.selectedCompression).toBeNull()
	})

	it('reports a failed load', async () => {
		store.fetchObject.mockRejectedValue(new Error('down'))
		const { wrapper } = mountForm(ExportDestinationForm, { id: 'd-1' })
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith(
			'Could not load the destination',
		)
		expect(wrapper.vm.loading).toBe(false)
	})

	it('saves an edit with its id and returns to the list', async () => {
		store.fetchObject.mockResolvedValue(null)
		const { wrapper, push } = mountForm(ExportDestinationForm, { id: 'd-1' })
		await flushPromises()
		wrapper.vm.model.name = 'Lake'
		await wrapper.vm.save()
		expect(store.saveObject).toHaveBeenCalledWith(
			'exportDestination',
			expect.objectContaining({ id: 'd-1', name: 'Lake', type: 's3' }),
		)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Destination saved')
		expect(push).toHaveBeenCalledWith({ name: 'ExportDestinations' })
		expect(wrapper.vm.busy).toBe(false)
	})

	it('saves a new destination without an id', async () => {
		const { wrapper } = mountForm(ExportDestinationForm)
		await wrapper.vm.save()
		expect(store.saveObject.mock.calls[0][1]).not.toHaveProperty('id')
	})

	it('stays on the form when saving fails', async () => {
		store.saveObject.mockRejectedValue(new Error('down'))
		const { wrapper, push } = mountForm(ExportDestinationForm)
		await wrapper.vm.save()
		expect(dialogs.showError).toHaveBeenCalledWith(
			'Could not save the destination',
		)
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('tells whether the connection test passed, failed or broke', async () => {
		store.fetchObject.mockResolvedValue(null)
		const { wrapper } = mountForm(ExportDestinationForm, { id: 'd-1' })
		await flushPromises()

		exportApi.testDestination.mockResolvedValueOnce({ valid: true })
		await wrapper.vm.testConnection()
		expect(exportApi.testDestination).toHaveBeenCalledWith('d-1')
		expect(dialogs.showSuccess).toHaveBeenLastCalledWith('Connection valid')

		exportApi.testDestination.mockResolvedValueOnce({ valid: false })
		await wrapper.vm.testConnection()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Connection failed')

		exportApi.testDestination.mockRejectedValueOnce(new Error('down'))
		await wrapper.vm.testConnection()
		expect(dialogs.showError).toHaveBeenLastCalledWith('Connection test failed')
		expect(wrapper.vm.busy).toBe(false)
	})

	it('goes back to the destination list', () => {
		const { wrapper, push } = mountForm(ExportDestinationForm)
		wrapper.vm.goBack()
		expect(push).toHaveBeenCalledWith({ name: 'ExportDestinations' })
	})
})

describe('ExportJobForm', () => {
	it('loads the destinations and marks the unverified ones', async () => {
		store.getCollection.mockReturnValue({
			results: [
				{ id: 'd-1', name: 'Lake', type: 's3', validationStatus: 'valid' },
				{
					id: 'd-2',
					name: 'Warehouse',
					type: 'bigquery',
					validationStatus: 'pending',
				},
			],
		})
		const { wrapper } = mountForm(ExportJobForm)
		await flushPromises()
		expect(store.fetchCollection).toHaveBeenCalledWith('exportDestination', {
			_limit: 200,
		})
		expect(wrapper.vm.destinationOptions).toEqual([
			{ id: 'd-1', label: 'Lake (s3)' },
			{ id: 'd-2', label: 'Warehouse (bigquery — unverified)' },
		])
		wrapper.vm.model.destinationId = 'd-2'
		expect(wrapper.vm.selectedDestination.id).toBe('d-2')
		expect(store.fetchObject).not.toHaveBeenCalled()
	})

	it('has no destinations when they cannot be read', async () => {
		store.fetchCollection.mockRejectedValue(new Error('down'))
		const { wrapper } = mountForm(ExportJobForm)
		await flushPromises()
		expect(wrapper.vm.destinations).toEqual([])
		expect(wrapper.vm.selectedDestination).toBeNull()
	})

	it('loads an existing job and shows its column allowlist as text', async () => {
		store.fetchObject.mockResolvedValue({
			name: 'Nightly',
			mode: 'incremental',
			columnAllowlist: ['id', 'name'],
		})
		const { wrapper } = mountForm(ExportJobForm, { exportJobId: 'j-1' })
		await flushPromises()
		expect(store.fetchObject).toHaveBeenCalledWith('exportJob', 'j-1')
		expect(wrapper.vm.allowlistText).toBe('id, name')
		expect(wrapper.vm.selectedMode).toEqual({
			id: 'incremental',
			label: 'Incremental',
		})
		expect(wrapper.vm.model.scheduleCron).toBe('0 2 * * *')
	})

	it('reports a failed load', async () => {
		store.fetchObject.mockRejectedValue(new Error('down'))
		const { wrapper } = mountForm(ExportJobForm, {}, { id: 'j-1' })
		await flushPromises()
		expect(dialogs.showError).toHaveBeenCalledWith('Could not load the job')
		expect(wrapper.vm.loading).toBe(false)
	})

	it('offers the exportable schemas, formats and modes', () => {
		const { wrapper } = mountForm(ExportJobForm)
		expect(wrapper.vm.schemaOptions.map((o) => o.id)).toEqual([
			'client',
			'contact',
			'lead',
			'ticket',
			'crmTask',
			'product',
		])
		expect(wrapper.vm.formatOptions.map((o) => o.id)).toEqual([
			'csv',
			'parquet',
			'jsonl',
		])
		expect(wrapper.vm.modeOptions).toEqual([
			{ id: 'full', label: 'Full refresh' },
			{ id: 'incremental', label: 'Incremental' },
		])
		expect(wrapper.vm.selectedFormat).toEqual({ id: 'csv', label: 'csv' })
		wrapper.vm.model.format = 'xml'
		expect(wrapper.vm.selectedFormat).toBeNull()
	})

	it('keeps the selected schemas as ids', () => {
		const { wrapper } = mountForm(ExportJobForm)
		wrapper.vm.onSchemasSelect([{ id: 'lead' }, { id: 'client' }])
		expect(wrapper.vm.model.sourceSchemas).toEqual(['lead', 'client'])
		expect(wrapper.vm.selectedSchemas.map((o) => o.id)).toEqual([
			'client',
			'lead',
		])
		wrapper.vm.onSchemasSelect(null)
		expect(wrapper.vm.model.sourceSchemas).toEqual([])
	})

	it('saves the allowlist as trimmed columns and returns to the list', async () => {
		store.fetchObject.mockResolvedValue(null)
		const { wrapper, push } = mountForm(ExportJobForm, { id: 'j-1' })
		await flushPromises()
		wrapper.vm.allowlistText = ' id , , name,'
		await wrapper.vm.save()
		expect(store.saveObject).toHaveBeenCalledWith(
			'exportJob',
			expect.objectContaining({
				id: 'j-1',
				columnAllowlist: ['id', 'name'],
				format: 'csv',
			}),
		)
		expect(dialogs.showSuccess).toHaveBeenCalledWith('Export job saved')
		expect(push).toHaveBeenCalledWith({ name: 'ExportJobs' })
	})

	it('stays on the form when saving fails', async () => {
		store.saveObject.mockRejectedValue(new Error('down'))
		const { wrapper, push } = mountForm(ExportJobForm)
		await flushPromises()
		await wrapper.vm.save()
		expect(store.saveObject.mock.calls[0][1]).not.toHaveProperty('id')
		expect(dialogs.showError).toHaveBeenCalledWith('Could not save the job')
		expect(push).not.toHaveBeenCalled()
		expect(wrapper.vm.busy).toBe(false)
	})

	it('only opens a test run for a saved job', async () => {
		const unsaved = mountForm(ExportJobForm)
		unsaved.wrapper.vm.openTestRunModal()
		expect(dialogs.showError).toHaveBeenCalledWith(
			'Save the job before running a test',
		)
		expect(unsaved.wrapper.vm.testRunOpen).toBe(false)

		store.fetchObject.mockResolvedValue(null)
		const saved = mountForm(ExportJobForm, { id: 'j-1' })
		await flushPromises()
		saved.wrapper.vm.openTestRunModal()
		await flushPromises()
		expect(saved.wrapper.vm.testRunOpen).toBe(true)
		expect(saved.wrapper.find('[data-name="ExportTestRunModal"]').exists()).toBe(
			true,
		)
	})

	it('goes back to the job list', () => {
		const { wrapper, push } = mountForm(ExportJobForm)
		wrapper.vm.goBack()
		expect(push).toHaveBeenCalledWith({ name: 'ExportJobs' })
	})
})

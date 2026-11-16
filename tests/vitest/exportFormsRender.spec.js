// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The BI export destination form, job form and run detail as they render:
 * the edit-only actions, the select bindings that write the model, the
 * incremental-only watermark field, and the run summary, manifest and
 * schema snapshots.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
 */

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { h } from 'vue'

const mocks = vi.hoisted(() => ({
	store: null,
	showError: vi.fn(),
	showSuccess: vi.fn(),
}))

/**
 * A stand-in that renders its slots, labels itself and re-emits the events the views listen to.
 *
 * @param {string} name The component name.
 * @param {string} tag The element to render.
 * @return {object} The component.
 */
function stub(name, tag = 'div') {
	return {
		name,
		inheritAttrs: false,
		props: ['modelValue', 'options', 'title', 'label', 'inputLabel', 'disabled', 'loading', 'status'],
		emits: ['update:modelValue', 'click', 'back'],
		render() {
			return h(tag, { 'data-stub': name, 'data-label': this.inputLabel || this.label || this.title || undefined, onClick: () => this.$emit('click') }, [
				this.$slots.header?.(),
				this.$slots.default?.(),
				this.$slots.actions?.(),
			])
		},
	}
}

vi.mock('@conduction/nextcloud-vue', () => ({
	CnDetailPage: stub('CnDetailPage', 'section'),
	CnDetailCard: stub('CnDetailCard'),
	CnStatusBadge: stub('CnStatusBadge', 'span'),
}))
vi.mock('@nextcloud/vue', () => ({
	NcButton: stub('NcButton', 'button'),
	NcSelect: stub('NcSelect'),
	NcTextField: stub('NcTextField'),
	NcCheckboxRadioSwitch: stub('NcCheckboxRadioSwitch'),
}))
vi.mock('@nextcloud/dialogs', () => ({ showError: mocks.showError, showSuccess: mocks.showSuccess }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => mocks.store }))
vi.mock('../../src/modals/ExportTestRunModal.vue', () => ({
	default: { name: 'ExportTestRunModal', props: ['jobId'], render: () => h('div', { 'data-stub': 'ExportTestRunModal' }) },
}))

globalThis.t = (app, text) => text
globalThis.OC = { requestToken: 'token' }

const mountOptions = (props = {}) => ({ props, global: { mocks: { t: globalThis.t, $router: { push: vi.fn() } } } })

const { default: ExportDestinationForm } = await import('../../src/views/export/ExportDestinationForm.vue')
const { default: ExportJobForm } = await import('../../src/views/export/ExportJobForm.vue')
const { default: ExportRunDetail } = await import('../../src/views/export/ExportRunDetail.vue')

/**
 * The buttons of a wrapper by their text.
 *
 * @param {object} wrapper The mounted view.
 * @return {Array<string>} The button texts.
 */
function buttons(wrapper) {
	return wrapper.findAll('[data-stub="NcButton"]').map((b) => b.text())
}

/**
 * The stub with that label.
 *
 * @param {object} wrapper The mounted view.
 * @param {string} label The label.
 * @return {object} The stub's component wrapper.
 */
function field(wrapper, label) {
	return wrapper.findAllComponents({ name: 'NcSelect' }).concat(wrapper.findAllComponents({ name: 'NcTextField' }))
		.find((c) => (c.props('inputLabel') || c.props('label')) === label)
}

beforeEach(() => {
	mocks.showError.mockReset()
	mocks.showSuccess.mockReset()
	mocks.store = {
		fetchObject: vi.fn(async () => null),
		fetchCollection: vi.fn(async () => undefined),
		getCollection: vi.fn(() => ({ results: [
			{ id: 'd-1', name: 'Lake', type: 's3', validationStatus: 'valid' },
			{ id: 'd-2', name: 'Box', type: 'sftp', validationStatus: 'pending' },
		] })),
		saveObject: vi.fn(async () => ({})),
	}
})

describe('ExportDestinationForm', () => {
	it('renders a new destination without a connection test and with save disabled until named', async () => {
		const wrapper = mount(ExportDestinationForm, mountOptions())
		await flushPromises()

		expect(wrapper.findComponent({ name: 'CnDetailPage' }).props('title')).toBe('New export destination')
		expect(buttons(wrapper)).toEqual(['Save', 'Cancel'])
		expect(wrapper.findAllComponents({ name: 'NcButton' })[0].props('disabled')).toBe(true)
		expect(mocks.store.fetchObject).not.toHaveBeenCalled()
	})

	it('loads an edited destination and offers the connection test', async () => {
		mocks.store.fetchObject.mockResolvedValue({ name: 'Lake', type: 'gcs', compression: 'zstd' })
		const wrapper = mount(ExportDestinationForm, mountOptions({ id: 'd-1' }))
		await flushPromises()

		expect(mocks.store.fetchObject).toHaveBeenCalledWith('exportDestination', 'd-1')
		expect(wrapper.findComponent({ name: 'CnDetailPage' }).props('title')).toBe('Edit destination')
		expect(buttons(wrapper)).toEqual(['Test connection', 'Save', 'Cancel'])
		expect(field(wrapper, 'Type').props('modelValue')).toEqual({ id: 'gcs', label: 'gcs' })
		expect(field(wrapper, 'Compression').props('modelValue')).toEqual({ id: 'zstd', label: 'zstd' })
		expect(wrapper.findAllComponents({ name: 'NcButton' })[1].props('disabled')).toBe(false)
	})

	it('writes the picked type and compression, and falls back when a pick is cleared', async () => {
		const wrapper = mount(ExportDestinationForm, mountOptions())
		await flushPromises()

		field(wrapper, 'Type').vm.$emit('update:modelValue', { id: 'bigquery' })
		field(wrapper, 'Compression').vm.$emit('update:modelValue', { id: 'gzip' })
		expect(wrapper.vm.model.type).toBe('bigquery')
		expect(wrapper.vm.model.compression).toBe('gzip')

		field(wrapper, 'Type').vm.$emit('update:modelValue', null)
		field(wrapper, 'Compression').vm.$emit('update:modelValue', null)
		expect(wrapper.vm.model.type).toBe('')
		expect(wrapper.vm.model.compression).toBe('none')
	})

	it('binds the text fields to the model', async () => {
		const wrapper = mount(ExportDestinationForm, mountOptions())
		await flushPromises()

		field(wrapper, 'Name').vm.$emit('update:modelValue', 'Warehouse')
		field(wrapper, 'Path template').vm.$emit('update:modelValue', 'out/{schema}')
		field(wrapper, 'OpenConnector source ID').vm.$emit('update:modelValue', 'src-9')
		field(wrapper, 'Naming convention').vm.$emit('update:modelValue', '{schema}')
		wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', true)
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.model).toMatchObject({ name: 'Warehouse', pathTemplate: 'out/{schema}', connectorSourceId: 'src-9', namingConvention: '{schema}', encryptionEnabled: true })
		expect(wrapper.findAllComponents({ name: 'NcButton' })[0].props('disabled')).toBe(false)
	})
})

describe('ExportJobForm', () => {
	it('lists the destinations, marking the unverified ones', async () => {
		const wrapper = mount(ExportJobForm, mountOptions())
		await flushPromises()

		expect(mocks.store.fetchCollection).toHaveBeenCalledWith('exportDestination', { _limit: 200 })
		expect(field(wrapper, 'Destination').props('options')).toEqual([
			{ id: 'd-1', label: 'Lake (s3)' },
			{ id: 'd-2', label: 'Box (sftp — unverified)' },
		])
		expect(wrapper.findComponent({ name: 'CnDetailPage' }).props('title')).toBe('New export job')
		expect(buttons(wrapper)).toEqual(['Save', 'Cancel'])
	})

	it('shows the watermark column only for an incremental job', async () => {
		const wrapper = mount(ExportJobForm, mountOptions())
		await flushPromises()
		expect(field(wrapper, 'Watermark column')).toBeUndefined()

		field(wrapper, 'Mode').vm.$emit('update:modelValue', { id: 'incremental' })
		await wrapper.vm.$nextTick()

		expect(wrapper.vm.model.mode).toBe('incremental')
		field(wrapper, 'Watermark column').vm.$emit('update:modelValue', 'updatedAt')
		expect(wrapper.vm.model.incrementalWatermarkColumn).toBe('updatedAt')
		field(wrapper, 'Mode').vm.$emit('update:modelValue', null)
		expect(wrapper.vm.model.mode).toBe('full')
	})

	it('writes the picked schemas, destination and format', async () => {
		const wrapper = mount(ExportJobForm, mountOptions())
		await flushPromises()

		field(wrapper, 'Source schemas').vm.$emit('update:modelValue', [{ id: 'lead' }, { id: 'client' }])
		field(wrapper, 'Destination').vm.$emit('update:modelValue', { id: 'd-2' })
		field(wrapper, 'Format').vm.$emit('update:modelValue', { id: 'parquet' })
		await wrapper.vm.$nextTick()
		expect(wrapper.vm.model).toMatchObject({ sourceSchemas: ['lead', 'client'], destinationId: 'd-2', format: 'parquet' })
		expect(field(wrapper, 'Destination').props('modelValue')).toEqual({ id: 'd-2', label: 'Box (sftp — unverified)' })

		field(wrapper, 'Destination').vm.$emit('update:modelValue', null)
		field(wrapper, 'Format').vm.$emit('update:modelValue', null)
		expect(wrapper.vm.model.destinationId).toBe('')
		expect(wrapper.vm.model.format).toBe('csv')
	})

	it('binds the text fields, offers a test run when editing and opens its modal', async () => {
		mocks.store.fetchObject.mockResolvedValue({ name: 'Nightly', columnAllowlist: ['id', 'name'] })
		const wrapper = mount(ExportJobForm, mountOptions({ exportJobId: 'j-1' }))
		await flushPromises()

		expect(field(wrapper, 'Column allowlist (optional, comma-separated)').props('modelValue')).toBe('id, name')
		expect(buttons(wrapper)).toEqual(['Test run', 'Save', 'Cancel'])
		for (const [label, value] of [['Name', 'Hourly'], ['Description', 'd'], ['Schedule (cron)', '0 * * * *'], ['Row filter (optional)', 'x = 1']]) {
			field(wrapper, label).vm.$emit('update:modelValue', value)
		}
		expect(wrapper.vm.model).toMatchObject({ name: 'Hourly', description: 'd', scheduleCron: '0 * * * *', rowFilterExpression: 'x = 1' })
		field(wrapper, 'Column allowlist (optional, comma-separated)').vm.$emit('update:modelValue', 'id, , email ')
		expect(wrapper.vm.parsedAllowlist()).toEqual(['id', 'email'])

		await wrapper.findAll('[data-stub="NcButton"]')[0].trigger('click')
		const modal = wrapper.findComponent({ name: 'ExportTestRunModal' })
		expect(modal.exists()).toBe(true)
		expect(modal.props('jobId')).toBe('j-1')
		modal.vm.$emit('close')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent({ name: 'ExportTestRunModal' }).exists()).toBe(false)
	})
})

describe('ExportRunDetail', () => {
	/**
	 * Answer the run endpoint with that body.
	 *
	 * @param {object} body The JSON body.
	 * @param {boolean} ok Whether the response is ok.
	 * @return {void}
	 */
	function answer(body, ok = true) {
		globalThis.fetch = vi.fn(async () => ({ ok, json: async () => body }))
	}

	it('renders the summary, manifest and drift of a failed run, with retry', async () => {
		answer({
			run: { status: 'failed', modeUsed: 'full', rowCount: 10, byteCount: 2048, fileCount: 1, startedAt: 's', endedAt: 'e', errorMessage: 'Bucket gone', fileManifestJson: [{ path: 'a.csv', size_bytes: 2048, rows_in_file: 10, sha256: 'abc', upload_status: 'failed' }] },
			snapshots: [{ pipelinqSchemaName: 'lead', comparedToPrevious: ['added email'] }, { pipelinqSchemaName: 'client', comparedToPrevious: [] }],
		})
		const wrapper = mount(ExportRunDetail, mountOptions({ id: 'r-1' }))
		await flushPromises()

		expect(globalThis.fetch.mock.calls[0][0]).toBe('/apps/pipelinq/api/export/runs/r-1')
		expect(wrapper.findComponent({ name: 'CnStatusBadge' }).props()).toMatchObject({ status: 'error', label: 'Failed' })
		expect(buttons(wrapper)).toEqual(['Back to list', 'Retry'])
		expect(wrapper.findAll('dd').map((d) => d.text())).toEqual(['Failed', 'full', '10', '2048', '1', 's', 'e'])
		expect(wrapper.find('.run-error').text()).toBe('Bucket gone')
		expect(wrapper.findAll('tbody td').map((d) => d.text())).toEqual(['a.csv', '2048', '10', 'abc', 'failed'])
		expect(wrapper.findAll('.run-drift li').map((li) => li.text())).toEqual(['added email'])
		expect(wrapper.find('.run-nodrift').text()).toBe('No schema changes detected')
	})

	it('renders an empty succeeded run without retry', async () => {
		answer({ run: { status: 'succeeded' } })
		const wrapper = mount(ExportRunDetail, mountOptions({ exportRunId: 'r-2' }))
		await flushPromises()

		expect(buttons(wrapper)).toEqual(['Back to list'])
		expect(wrapper.findAll('dd').map((d) => d.text())).toEqual(['Succeeded', '—', '0', '0', '0', '—', '—'])
		expect(wrapper.find('.run-error').exists()).toBe(false)
		expect(wrapper.findAll('.run-empty').map((p) => p.text())).toEqual(['No files in this run', 'No schema snapshots'])
	})

	it('reports a run that could not be read', async () => {
		answer({ error: 'No such run' }, false)
		const wrapper = mount(ExportRunDetail, mountOptions({ id: 'r-3' }))
		await flushPromises()

		expect(mocks.showError).toHaveBeenCalledWith('No such run')
		expect(wrapper.vm.loading).toBe(false)
		expect(wrapper.findComponent({ name: 'CnStatusBadge' }).props()).toMatchObject({ status: 'info', label: 'Unknown' })
	})
})

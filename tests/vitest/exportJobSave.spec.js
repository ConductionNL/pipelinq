// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The export job form saves through the export API, which validates the job,
 * stamps createdBy and createdAt and keeps it disabled until it is tested. It
 * used to save straight into the object store, which skipped all of that, so
 * the schema's required createdBy/createdAt refused the new job while the form
 * still said it was saved.
 *
 * @spec openspec/specs/bi-export-and-data-warehouse-sink/spec.md#requirement-destination-configuration-and-validation-req-bie-001
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ createJob: vi.fn(), updateJob: vi.fn() }))
const dialogs = vi.hoisted(() => ({ showError: vi.fn(), showSuccess: vi.fn() }))

vi.mock('../../src/services/exportApi.js', () => ({ exportApi: api }))
vi.mock('@nextcloud/dialogs', () => dialogs)
vi.mock('@nextcloud/vue', () => ({ NcButton: {}, NcCheckboxRadioSwitch: {}, NcSelect: {}, NcTextField: {} }))
vi.mock('@conduction/nextcloud-vue', () => ({ CnDetailCard: {}, CnDetailPage: {} }))
vi.mock('../../src/store/modules/object.js', () => ({ useObjectStore: () => ({}) }))
vi.mock('../../src/modals/ExportTestRunModal.vue', () => ({ default: {} }))

let form

beforeAll(async () => {
	globalThis.t = (app, text) => text
	form = (await import('../../src/views/export/ExportJobForm.vue')).default
})

beforeEach(() => {
	api.createJob.mockReset()
	api.updateJob.mockReset()
	dialogs.showError.mockReset()
	dialogs.showSuccess.mockReset()
})

/**
 * A form context.
 *
 * @param {string|null} jobId The job being edited, or null for a new one.
 * @return {object}
 */
function context(jobId) {
	const push = vi.fn()
	return {
		...form.data.call({ $route: { params: {} } }),
		jobId,
		isEdit: Boolean(jobId),
		model: { name: 'Nightly', sourceSchemas: ['lead'], destinationId: 'd1', format: 'csv', mode: 'full', scheduleCron: '0 2 * * *' },
		allowlistText: 'email, phone',
		parsedAllowlist: form.methods.parsedAllowlist,
		$router: { push },
		t: (app, text) => text,
	}
}

describe('ExportJobForm save', () => {
	it('creates a new job through the export API, never the object store', async () => {
		api.createJob.mockResolvedValue({ job: { id: 'j1' } })
		const ctx = context(null)
		await form.methods.save.call(ctx)
		expect(api.createJob).toHaveBeenCalledWith(expect.objectContaining({ name: 'Nightly', columnAllowlist: ['email', 'phone'] }))
		expect(api.updateJob).not.toHaveBeenCalled()
		expect(dialogs.showSuccess).toHaveBeenCalled()
		expect(ctx.$router.push).toHaveBeenCalledWith({ name: 'ExportJobs' })
	})

	it('updates an existing job through the export API', async () => {
		api.updateJob.mockResolvedValue({ job: { id: 'j1' } })
		const ctx = context('j1')
		await form.methods.save.call(ctx)
		expect(api.updateJob).toHaveBeenCalledWith('j1', expect.objectContaining({ name: 'Nightly' }))
	})

	it('shows the server\'s reason and stays on the form when the job is refused', async () => {
		api.createJob.mockRejectedValue(new Error('A destination is required'))
		const ctx = context(null)
		await form.methods.save.call(ctx)
		expect(dialogs.showError).toHaveBeenCalledWith('A destination is required')
		expect(dialogs.showSuccess).not.toHaveBeenCalled()
		expect(ctx.$router.push).not.toHaveBeenCalled()
	})
})

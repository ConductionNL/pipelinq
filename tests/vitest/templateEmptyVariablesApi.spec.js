// @vitest-environment jsdom
/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The client for GET /api/templates/{id}/empty-variables.
 *
 * @spec openspec/changes/marketing-mail-templates/specs/marketing-mail-templates/spec.md#REQ-MMT-002
 */

import axios from '@nextcloud/axios'
import { beforeEach, describe, expect, it, vi } from 'vitest'

vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { emptyVariables } = await import('../../src/services/templatePreviewApi.js')

beforeEach(() => {
	axios.get = vi.fn(async () => ({ data: { recipients: 12, gaps: [{ variable: 'city', count: 12 }] } }))
})

describe('emptyVariables', () => {
	it('asks for the template and the audience and returns the gaps', async () => {
		await expect(emptyVariables('tpl1', { segmentId: 'seg1' })).resolves.toEqual([{ variable: 'city', count: 12 }])
		expect(axios.get).toHaveBeenCalledWith('/apps/pipelinq/api/templates/tpl1/empty-variables', { params: { segmentId: 'seg1', listId: '' } })
	})

	it('asks nothing without a template or an audience', async () => {
		await expect(emptyVariables('tpl1', {})).resolves.toEqual([])
		await expect(emptyVariables('', { listId: 'l1' })).resolves.toEqual([])
		expect(axios.get).not.toHaveBeenCalled()
	})

	it('reads a malformed answer as no gaps', async () => {
		axios.get = vi.fn(async () => ({ data: {} }))
		await expect(emptyVariables('tpl1', { listId: 'l1' })).resolves.toEqual([])
	})
})

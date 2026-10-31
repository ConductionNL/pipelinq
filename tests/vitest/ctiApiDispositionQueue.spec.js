/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The wrap-up's escalation queue reaches the server: submitDisposition sends
 * the chosen queue with an escalated call (and only then), and the queues
 * are read from GET /api/cti/escalation-queues.
 *
 * @spec openspec/specs/cti-screenpop-adapter/spec.md#requirement-the-agent-chooses-the-escalation-queue-at-wrap-up
 */
import { beforeEach, describe, expect, it, vi } from 'vitest'

const axios = vi.hoisted(() => ({ post: vi.fn(), get: vi.fn() }))
vi.mock('@nextcloud/axios', () => ({ default: axios }))
vi.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

const { escalationQueues, submitDisposition } = await import('../../src/services/ctiApi.js')

describe('the disposition and its queue', () => {
	beforeEach(() => {
		axios.post.mockReset().mockResolvedValue({ data: { taskId: 't-1' } })
		axios.get.mockReset().mockResolvedValue({ data: { queues: [{ id: 'vergunningen', label: 'Vergunningen, tweede lijn' }] } })
	})

	it('sends the chosen queue with an escalated call', async () => {
		await submitDisposition('cm-1', { subject: 'Podium', outcome: 'escalated', notes: '', queue: 'vergunningen' })
		expect(axios.post).toHaveBeenCalledWith('/apps/pipelinq/api/cti/contactmoment/cm-1/disposition', { subject: 'Podium', outcome: 'escalated', notes: '', queue: 'vergunningen' })
	})

	it('leaves a queue out for any other outcome', async () => {
		await submitDisposition('cm-1', { subject: 'Podium', outcome: 'resolved', notes: '', queue: 'vergunningen' })
		expect(axios.post.mock.calls[0][1]).toEqual({ subject: 'Podium', outcome: 'resolved', notes: '' })
	})

	it('reads the queues', async () => {
		expect(await escalationQueues()).toEqual([{ id: 'vergunningen', label: 'Vergunningen, tweede lijn' }])
		expect(axios.get).toHaveBeenCalledWith('/apps/pipelinq/api/cti/escalation-queues')
	})
})

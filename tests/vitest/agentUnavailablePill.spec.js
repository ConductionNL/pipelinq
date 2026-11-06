/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Board PqBeheer (Agentprofielen): an agent who is not available carries a
 * "Niet beschikbaar" pill in the warning tone, the library status badge, not
 * a small red tag.
 *
 * @spec openspec/changes/agent-unavailable-pill/specs/agent-routing/spec.md#requirement-an-unavailable-agent-carries-a-warning-pill
 */

import fs from 'fs'
import path from 'path'
import { describe, expect, it } from 'vitest'

const source = fs.readFileSync(path.resolve(__dirname, '../../src/components/admin/AgentProfileSettings.vue'), 'utf8')

describe('agent profile availability pill', () => {
	it('draws the library status badge in the warning tone', () => {
		expect(source).toMatch(/<CnStatusBadge\s+v-if="profile\.isAvailable === false"\s+variant="warning"\s+:label="t\('pipelinq', 'Unavailable'\)"/)
	})

	it('drops the hand-made red tag', () => {
		expect(source).not.toContain('unavailable-tag')
		expect(source).not.toContain('background: var(--color-error);')
	})
})

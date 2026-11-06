/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * The badge colour of a pipeline stage, as the PqLeads and PqPipeline boards
 * draw it. Stage names are free text on the pipeline, so both the Dutch and
 * the English default names are listed; the badge matches without case and
 * an unknown stage keeps the neutral badge.
 *
 * @spec openspec/specs/lead-management/spec.md
 */
export const STAGE_COLORS = Object.freeze({
	nieuw: 'default',
	new: 'default',
	benaderd: 'info',
	contacted: 'info',
	gekwalificeerd: 'purple',
	qualified: 'purple',
	voorstel: 'warning',
	proposal: 'warning',
	onderhandeling: 'warning',
	negotiation: 'warning',
	gewonnen: 'success',
	won: 'success',
	verloren: 'error',
	lost: 'error',
})

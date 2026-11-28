// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

import { blastNote } from './blastNote.js'
import { share } from './shareFormatter.js'

/**
 * The marketing pages' cell formatters, merged into the app's registry by
 * createAppFormatters.
 *
 * @spec openspec/specs/marketing-analytics/spec.md#requirement-overview-metrics-table
 */
export const MARKETING_FORMATTERS = {
	blastNote,
	share,
}

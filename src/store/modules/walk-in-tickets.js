// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Walk-in ticket store module — re-exports the central object store.
 *
 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
 */
import { useObjectStore } from './object.js'

export { useObjectStore as useWalkInTicketStore }

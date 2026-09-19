// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Service store module — re-exports the central object store for service CRUD.
 *
 * The appointment-booking admin views call `useObjectStore()` with the
 * `'appointmentService'` type slug; this convenience module mirrors the product-store
 * pattern so callers can reach for a named import when readability matters.
 *
 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
 */
import { useObjectStore } from './object.js'

export { useObjectStore as useServiceStore }

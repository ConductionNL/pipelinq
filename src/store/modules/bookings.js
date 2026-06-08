// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
/**
 * Booking store module — re-exports the central object store for booking CRUD.
 *
 * @spec openspec/changes/appointment-booking-11-admin-ui/tasks.md
 */
import { useObjectStore } from './object.js'

export { useObjectStore as useBookingStore }

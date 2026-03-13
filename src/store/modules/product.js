/**
 * Product store module — re-exports the object store for product operations.
 *
 * All product CRUD is handled by the central object store via
 * registerObjectType('product', ...) in store.js. This module
 * provides a convenience reference.
 */
import { useObjectStore } from './object.js'

export { useObjectStore as useProductStore }

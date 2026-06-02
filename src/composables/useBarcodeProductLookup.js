/**
 * useBarcodeProductLookup — resolve a scanned barcode to a pipelinq product.
 *
 * Resolution is delegated to the server-authoritative, IDOR-safe endpoint
 * `POST /apps/pipelinq/api/products/barcode-lookup` (ProductCatalogService).
 * The backend matches the barcode against the product's own barcode and against
 * each ACTIVE variant barcode — scoped to this app's register + product schema —
 * and returns the matching product plus a zero-based `variantIndex` (null on a
 * top-level match). Resolving server-side avoids enumerating the whole catalogue
 * in the browser and keeps the scoping/authorisation on the server.
 *
 * The scanned value is untrusted; it is validated (length + charset) before any
 * request is sent, mirroring the server guard.
 *
 * Returns `{ product, variant, variantIndex, status }` where `status` is one of
 * `'found'`, `'not_found'`, `'ambiguous'`, or `'invalid'`.
 *
 * @spec openspec/changes/pos-barcode-scan/specs/pos-barcode-scan/spec.md#REQ-PBS-004
 * @spec openspec/changes/pos-barcode-scan/specs/pos-barcode-scan/spec.md#REQ-PBS-005
 */

import { generateUrl } from '@nextcloud/router'
import { isValidBarcode } from './useBarcodeScanner.js'

/**
 * Map a lookup endpoint response into the composable result shape.
 *
 * Kept pure so it is unit-testable without the network.
 *
 * @param {number} httpStatus The HTTP status code of the lookup response.
 * @param {object|null} body The parsed JSON body (or null).
 * @return {{product: object|null, variant: object|null, variantIndex: number|null, status: string}}
 *         The normalised lookup result.
 */
export function mapLookupResponse(httpStatus, body) {
	if (httpStatus === 404) {
		return { product: null, variant: null, variantIndex: null, status: 'not_found' }
	}
	if (httpStatus < 200 || httpStatus >= 300 || body === null || typeof body !== 'object') {
		return { product: null, variant: null, variantIndex: null, status: 'not_found' }
	}

	const product = body.product || null
	if (product === null) {
		return { product: null, variant: null, variantIndex: null, status: 'not_found' }
	}

	const variantIndex = (typeof body.variantIndex === 'number') ? body.variantIndex : null
	let variant = null
	if (variantIndex !== null && Array.isArray(product.variants)) {
		variant = product.variants[variantIndex] || null
	}

	return { product, variant, variantIndex, status: 'found' }
}

/**
 * The barcode product-lookup composable.
 *
 * @return {{lookupByBarcode: Function}} The lookup function.
 */
export function useBarcodeProductLookup() {
	/**
	 * Resolve a scanned barcode to a product (and variant, when applicable).
	 *
	 * @param {string} barcode The scanned barcode.
	 * @return {Promise<{product: object|null, variant: object|null, variantIndex: number|null, status: string}>}
	 *         The lookup result.
	 */
	async function lookupByBarcode(barcode) {
		if (!isValidBarcode(barcode)) {
			return { product: null, variant: null, variantIndex: null, status: 'invalid' }
		}

		const trimmed = barcode.trim()
		try {
			const response = await fetch(
				generateUrl('/apps/pipelinq/api/products/barcode-lookup'),
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						requesttoken: OC.requestToken,
						'OCS-APIREQUEST': 'true',
					},
					body: JSON.stringify({ barcode: trimmed }),
				},
			)

			let body = null
			try {
				body = await response.json()
			} catch (e) {
				body = null
			}

			return mapLookupResponse(response.status, body)
		} catch (e) {
			return { product: null, variant: null, variantIndex: null, status: 'not_found' }
		}
	}

	return { lookupByBarcode }
}

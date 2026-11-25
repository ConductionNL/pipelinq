<?php

/**
 * Pipelinq ProductCatalogController.
 *
 * Thin controller for POS product catalogue resolution: barcode lookup and
 * server-authoritative effective-price resolution. All business logic and
 * scoping live in ProductCatalogService.
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/specs/pos-product-catalogue/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\CallerObjectReaderInterface;
use OCA\Pipelinq\Kernel\Contracts\PosAccessPolicyInterface;
use OCA\Pipelinq\Kernel\Contracts\ProductCatalogServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for POS product catalogue endpoints.
 *
 * Authorization model: barcode lookup requires an authenticated POS operator (a
 * POS-group member or admin), enforced via PosAccessPolicy — the barcode
 * search is a cashier capability. Price resolution is also open to any signed-in
 * user who may read the product (products-quote-from-lead task 1): such a
 * caller names the product by id, the server reads it with the caller's own
 * rights (404 when it cannot be read) and prices the stored product, never the
 * payload. Lookup and price
 * resolution are scoped inside ProductCatalogService to this app's own register
 * + product schema, so a caller can never reach objects outside the catalogue
 * (no IDOR). The effective price and tax rate are resolved server-side from the
 * persisted product and are never trusted from the client.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/pos-product-catalogue/spec.md
 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#4.2
 */
class ProductCatalogController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProductCatalogServiceInterface $service The product catalogue service.
	 * @param IUserSession $userSession The user session.
	 * @param PosAccessPolicyInterface $policy The shared POS access policy.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 * @param CallerObjectReaderInterface $reader Reads a client with the caller's rights.
	 */
	public function __construct(
		IRequest $request,
		private ProductCatalogServiceInterface $service,
		private IUserSession $userSession,
		private PosAccessPolicyInterface $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
		private CallerObjectReaderInterface $reader,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Look up a product by barcode (product or variant barcode).
	 *
	 * @return JSONResponse The matching product, or 404 when none matches.
	 *
	 * @spec openspec/specs/pos-product-catalogue/spec.md
	 */
	#[NoAdminRequired]
	public function lookupBarcode(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$barcode = (string)$this->request->getParam('barcode', '');

		return $this->run(
			action: function () use ($barcode): array {
				$product = $this->service->lookupByBarcode(barcode: $barcode);
				if ($product === null) {
					throw new OCSNotFoundException(
						$this->l10n->t('No product found for barcode %s', [$barcode])
					);
				}

				// Surface the matched variant index (null on a top-level match)
				// so the client can address the variant without re-searching.
				$variantIndex = null;
				if (isset($product['matchedVariantIndex']) === true) {
					$variantIndex = (int)$product['matchedVariantIndex'];
				}

				return ['product' => $product, 'variantIndex' => $variantIndex];
			},
			label: 'lookupBarcode'
		);
	}//end lookupBarcode()

	/**
	 * Resolve the server-authoritative effective unit price for a product.
	 *
	 * Accepts a product object payload plus quantity and optional variantSku,
	 * and returns the resolved unitPrice, source, tax rate and BTW class. The
	 * client-supplied price is ignored; the figure is derived from the product's
	 * own tiers / variant overrides and BTW class. With a clientId (and an
	 * optional currency) the client's price lists go first; a client the
	 * caller cannot read gives 404.
	 *
	 * @return JSONResponse The resolved price.
	 *
	 * A POS operator may send the product object itself (the till holds it).
	 * Any other signed-in user names the product by id (`productId`, or the id
	 * of the `product` object); it is read with the caller's rights and the
	 * stored product is priced, so a caller who may read the product may
	 * resolve its price and nobody else.
	 *
	 * @spec openspec/specs/pos-product-catalogue/spec.md
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/tasks.md#task-1
	 */
	#[NoAdminRequired]
	public function resolvePrice(): JSONResponse {
		$uid = $this->posResponses()->userIdOr401(user: $this->userSession->getUser());
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$product = $this->productToPrice(uid: $uid);
		if ($product instanceof JSONResponse) {
			return $product;
		}

		$quantity = (float)$this->request->getParam('quantity', 1);

		// 🔴 A NEGATIVE QUANTITY IS A BAD REQUEST, NOT A ZERO.
		//
		// ProductCatalogService clamps with max(0.0, $quantity), which is right
		// for a PRICE but wrong for an ordered quantity: -5 was silently priced
		// as 0 and answered 200, so a caller that had computed a quantity wrong
		// got a confident answer instead of an error. Rejecting at the boundary
		// leaves the service's own clamp alone, where it still guards the
		// prices it was written for.
		if ($quantity < 0.0) {
			return new JSONResponse(
				['error' => 'quantity must not be negative'],
				Http::STATUS_BAD_REQUEST
			);
		}
		$variantSku = $this->optionalStringParam(name: 'variantSku');

		$client = $this->clientParam();
		if ($client instanceof JSONResponse) {
			return $client;
		}

		$currency = $this->optionalStringParam(name: 'currency');

		return $this->run(
			action: fn (): array => $this->service->resolveEffectivePrice(
				product: $product,
				quantity: $quantity,
				variantSku: $variantSku,
				client: $client,
				currency: $currency
			),
			label: 'resolvePrice'
		);
	}//end resolvePrice()

	/**
	 * The product a price is resolved for.
	 *
	 * A POS operator's product object is priced as sent (the till's
	 * catalogue). For anyone else the product is the stored one, read by id
	 * with the caller's rights: no id is a 403 (only the till prices an
	 * object it sends), an id the caller cannot read is a 404.
	 *
	 * @param string $uid The signed-in user.
	 *
	 * @return array<string, mixed>|JSONResponse The product, or the refusal.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/tasks.md#task-1
	 */
	private function productToPrice(string $uid): array|JSONResponse {
		$product = (array)$this->request->getParam('product', []);
		if ($this->policy->isPosUser(userId: $uid) === true) {
			return $product;
		}

		$productId = $this->productIdOf(product: $product);
		if ($productId === '') {
			return new JSONResponse(
				['error' => $this->l10n->t('POS access is required for the product catalogue')],
				Http::STATUS_FORBIDDEN
			);
		}

		$stored = $this->reader->read(id: $productId, schemaKey: 'product_schema');
		if ($stored === null) {
			return new JSONResponse(['error' => $this->l10n->t('Product not found')], Http::STATUS_NOT_FOUND);
		}

		return $stored;
	}//end productToPrice()

	/**
	 * The id of the product to price: the productId parameter, else the id
	 * the product object carries.
	 *
	 * @param array<string, mixed> $product The product object sent, if any.
	 *
	 * @return string The id; empty when none is given.
	 *
	 * @spec openspec/changes/archive/2026-11-25-products-quote-from-lead/tasks.md#task-1
	 */
	private function productIdOf(array $product): string {
		$candidates = [
			$this->request->getParam('productId', ''),
			($product['id'] ?? ''),
			($product['@self']['id'] ?? ''),
			($product['uuid'] ?? ''),
		];
		foreach ($candidates as $candidate) {
			if (is_scalar($candidate) === true && trim((string)$candidate) !== '') {
				return trim((string)$candidate);
			}
		}

		return '';
	}//end productIdOf()

	/**
	 * A request parameter as a string, or null when it is absent.
	 *
	 * @param string $name The parameter.
	 *
	 * @return string|null The value.
	 */
	private function optionalStringParam(string $name): ?string {
		$value = $this->request->getParam($name);
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end optionalStringParam()

	/**
	 * The client named by the clientId parameter, read with the caller's rights.
	 *
	 * A client prices from its price lists (products-price-lists D3). A client
	 * the caller cannot read is answered as not found, and never priced.
	 *
	 * @return array<string, mixed>|JSONResponse|null The client; null when no
	 *                                                clientId is given; a 404
	 *                                                when it cannot be read.
	 *
	 * @spec openspec/changes/products-price-lists/specs/product-price-lists/spec.md#requirement-a-quote-line-is-priced-from-the-matching-list-req-ppl-002
	 */
	private function clientParam(): array|JSONResponse|null {
		$clientId = trim((string)$this->request->getParam('clientId', ''));
		if ($clientId === '') {
			return null;
		}

		$client = $this->reader->read(id: $clientId, schemaKey: 'client_schema');
		if ($client === null) {
			return new JSONResponse(['error' => $this->l10n->t('Client not found')], Http::STATUS_NOT_FOUND);
		}

		return $client;
	}//end clientParam()

	/**
	 * Require an authenticated POS operator, returning their UID.
	 *
	 * Returns a 401 JSONResponse when no user is in the session, and a 403 when
	 * the caller is not a POS-group member or admin. The catalogue surface is a
	 * cashier capability, not an any-authenticated-user one, so it is gated to
	 * POS operators (closing the over-broad #[NoAdminRequired] exposure).
	 *
	 * @return string|JSONResponse The acting user UID, or a 401/403 response.
	 *
	 * @spec openspec/changes/archive/2026-06-01-pos-lifecycle-guard-adoption/tasks.md#4.2
	 */
	private function requireUserId(): string|JSONResponse {
		$uid = $this->posResponses()->userIdOr401(user: $this->userSession->getUser());
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		if ($this->policy->isPosUser(userId: $uid) === false) {
			return new JSONResponse(
				['error' => $this->l10n->t('POS access is required for the product catalogue')],
				Http::STATUS_FORBIDDEN
			);
		}

		return $uid;
	}//end requireUserId()

	/**
	 * Run an action with shared error handling.
	 *
	 * @param callable $action The action to run.
	 * @param string $label A short label for log context.
	 *
	 * @return JSONResponse The response.
	 */
	private function run(callable $action, string $label): JSONResponse {
		return $this->posResponses()->run(
			respond: fn (): JSONResponse => new JSONResponse($action()),
			logLabel: 'ProductCatalogController::' . $label
		);
	}//end run()

	/**
	 * The login check and error mapping shared by the POS controllers.
	 *
	 * @return PosResponses The shared responses.
	 */
	private function posResponses(): PosResponses {
		return new PosResponses(l10n: $this->l10n, logger: $this->logger);
	}//end posResponses()
}//end class

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

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for POS product catalogue endpoints.
 *
 * Authorization model: every action requires an authenticated POS operator (a
 * POS-group member or admin), enforced via PosAccessPolicy — the catalogue is a
 * cashier capability, not an any-authenticated-user one. Lookup and price
 * resolution are scoped inside ProductCatalogService to this app's own register
 * + product schema, so a caller can never reach objects outside the catalogue
 * (no IDOR). The effective price and tax rate are resolved server-side from the
 * persisted product and are never trusted from the client.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/pos-product-catalogue/spec.md
 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.2
 */
class ProductCatalogController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ProductCatalogService $service The product catalogue service.
	 * @param IUserSession $userSession The user session.
	 * @param PosAccessPolicy $policy The shared POS access policy.
	 * @param IL10N $l10n The localization service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		IRequest $request,
		private ProductCatalogService $service,
		private IUserSession $userSession,
		private PosAccessPolicy $policy,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
	 * own tiers / variant overrides and BTW class.
	 *
	 * @return JSONResponse The resolved price.
	 *
	 * @spec openspec/specs/pos-product-catalogue/spec.md
	 */
	#[NoAdminRequired]
	public function resolvePrice(): JSONResponse {
		$uid = $this->requireUserId();
		if ($uid instanceof JSONResponse) {
			return $uid;
		}

		$product = (array)$this->request->getParam('product', []);
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
		$variantSku = $this->request->getParam('variantSku');
		if ($variantSku !== null) {
			$variantSku = (string)$variantSku;
		}

		return $this->run(
			action: fn (): array => $this->service->resolveEffectivePrice(
				product: $product,
				quantity: $quantity,
				variantSku: $variantSku
			),
			label: 'resolvePrice'
		);
	}//end resolvePrice()

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
	 * @spec openspec/changes/pos-lifecycle-guard-adoption/tasks.md#4.2
	 */
	private function requireUserId(): string|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(
				['error' => $this->l10n->t('Authentication required')],
				Http::STATUS_UNAUTHORIZED
			);
		}

		$uid = $user->getUID();
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
		try {
			return new JSONResponse($action());
		} catch (OCSNotFoundException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (\Throwable $e) {
			$this->logger->error('ProductCatalogController::' . $label . ' failed', ['exception' => $e->getMessage()]);
			return new JSONResponse(
				['error' => $this->l10n->t('An unexpected error occurred')],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end run()
}//end class

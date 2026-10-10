<?php

/**
 * Pipelinq ProductStockController.
 *
 * The Stock tracked line on the product page: how many of a product are
 * left, read from shillinq on every page load (products-stock-on-hand).
 *
 * @category Controller
 * @package  OCA\Pipelinq\Controller
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Controller;

use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\Service\ShillinqStockReader;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * GET /api/products/{id}/stock.
 *
 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-the-product-page-shows-the-available-stock-req-pst-001
 */
class ProductStockController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param ShillinqStockReader $reader Reads shillinq's stock as the user.
	 * @param ContainerInterface $container For the lazy OpenRegister object service.
	 * @param IAppConfig $appConfig For the register and product schema slugs.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private ShillinqStockReader $reader,
		private ContainerInterface $container,
		private IAppConfig $appConfig,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The stock of one product.
	 *
	 * The product is read through OpenRegister's RBAC first, so a product the
	 * caller cannot read answers 404 before shillinq is asked anything. An
	 * untracked product asks shillinq nothing.
	 *
	 * @param string $id The product's OpenRegister id.
	 *
	 * @return JSONResponse `{state, tracked, available, onHand, reserved, unit, locations}`.
	 *
	 * @spec openspec/changes/products-stock-on-hand/specs/product-stock/spec.md#requirement-untracked-products-and-missing-stock-read-plainly-req-pst-002
	 */
	#[NoAdminRequired]
	public function show(string $id = ''): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(['state' => 'unauthorized'], Http::STATUS_UNAUTHORIZED);
		}

		$product = $this->loadProduct(id: $id);
		if ($product === null) {
			return new JSONResponse(['state' => 'not-found'], Http::STATUS_NOT_FOUND);
		}

		$unit = (string)($product['unitOfMeasure'] ?? ($product['unit'] ?? ''));
		if (($product['stockTracked'] ?? false) !== true) {
			return new JSONResponse(
				[
					'state' => 'untracked',
					'tracked' => false,
					'available' => 0,
					'onHand' => 0,
					'reserved' => 0,
					'unit' => $unit,
					'locations' => [],
				]
			);
		}

		$stock = $this->reader->stockFor(productId: (string)($product['productId'] ?? ''), sku: (string)($product['sku'] ?? ''));

		return new JSONResponse(array_merge($stock, ['tracked' => true, 'unit' => $unit]));
	}//end show()

	/**
	 * Read the product as the caller, or null when it cannot be read.
	 *
	 * @param string $id The product id.
	 *
	 * @return array<string, mixed>|null The product.
	 */
	private function loadProduct(string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			$objectService = $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
			$entity = $objectService->find(
				id: $id,
				register: $this->slug(key: 'register', default: 'pipelinq'),
				schema: $this->slug(key: 'product_schema', default: 'product')
			);
		} catch (Throwable $e) {
			return null;
		}

		if (is_array($entity) === true) {
			return $entity;
		}

		if (is_object($entity) === true && method_exists($entity, 'jsonSerialize') === true) {
			$data = $entity->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return null;
	}//end loadProduct()

	/**
	 * An app-config slug, never empty.
	 *
	 * @param string $key The app-config key.
	 * @param string $default The built-in slug.
	 *
	 * @return string The slug.
	 */
	private function slug(string $key, string $default): string {
		$value = $this->appConfig->getValueString(Application::APP_ID, $key, '');
		if ($value === '') {
			return $default;
		}

		return $value;
	}//end slug()
}//end class

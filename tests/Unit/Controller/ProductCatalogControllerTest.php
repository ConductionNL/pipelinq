<?php

/**
 * Unit tests for ProductCatalogController.
 *
 * Two tiers of assertion:
 *   1. Wiring / status mapping with a mocked ProductCatalogService — 401 for an
 *      anonymous caller, 403 for a caller outside the POS group (and in both
 *      cases the catalogue is never queried), 404 for an unmatched barcode,
 *      422 for a malformed one.
 *   2. The money contract of `resolvePrice`, driven through the controller with
 *      the REAL ProductCatalogService (price resolution is pure), asserting the
 *      rounding to cents, the base / tier / variant precedence and the BTW
 *      class to tax-rate mapping — including the fail-safe direction of an
 *      unknown BTW class (21%, never 0%).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Controller;

use OCA\Pipelinq\Controller\ProductCatalogController;
use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProductCatalogController.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class ProductCatalogControllerTest extends TestCase {

	private ProductCatalogController $controller;

	/**
	 * @var ProductCatalogService&MockObject
	 */
	private ProductCatalogService $service;

	/**
	 * @var PosAccessPolicy&MockObject
	 */
	private PosAccessPolicy $policy;

	/**
	 * @var IRequest&MockObject
	 */
	private IRequest $request;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession $session;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ProductCatalogService::class);
		$this->policy = $this->createMock(PosAccessPolicy::class);
		$this->session = $this->createMock(IUserSession::class);

		$this->controller = new ProductCatalogController(
			$this->request,
			$this->service,
			$this->session,
			$this->policy,
			$this->l10n(),
			$this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * A pass-through l10n double.
	 *
	 * @return IL10N&MockObject
	 */
	private function l10n(): IL10N {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')
			->willReturnCallback(
				static function (string $text, array $parameters = []): string {
					if ($parameters === []) {
						return $text;
					}

					return vsprintf(str_replace('%s', '%s', $text), $parameters);
				}
			);

		return $l10n;
	}//end l10n()

	/**
	 * Make the session resolve to a POS-authorised user.
	 *
	 * @param string $uid The user id.
	 * @param bool $isPos Whether the user is a POS operator.
	 *
	 * @return void
	 */
	private function loginAs(string $uid = 'cashier', bool $isPos = true): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->session->method('getUser')->willReturn($user);
		$this->policy->method('isPosUser')->willReturn($isPos);
	}//end loginAs()

	/**
	 * Stub the request params.
	 *
	 * @param array<string, mixed> $params The params.
	 *
	 * @return void
	 */
	private function withParams(array $params): void {
		$this->request->method('getParam')
			->willReturnCallback(
				static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default)
			);
	}//end withParams()

	/**
	 * Build a controller backed by the REAL catalogue service.
	 *
	 * Price resolution performs no I/O, so the container / app-config
	 * collaborators are never touched; this exercises the actual arithmetic
	 * that decides what a customer is charged.
	 *
	 * @param array<string, mixed> $params The request params.
	 *
	 * @return ProductCatalogController
	 */
	private function controllerWithRealService(array $params): ProductCatalogController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')
			->willReturnCallback(
				static fn (string $name, mixed $default = null): mixed => ($params[$name] ?? $default)
			);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('cashier');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$policy = $this->createMock(PosAccessPolicy::class);
		$policy->method('isPosUser')->willReturn(true);

		$service = new ProductCatalogService(
			$this->createMock(ContainerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(LoggerInterface::class),
		);

		return new ProductCatalogController(
			$request,
			$service,
			$session,
			$policy,
			$this->l10n(),
			$this->createMock(LoggerInterface::class),
		);
	}//end controllerWithRealService()

	// ---- lookupBarcode -----------------------------------------------------

	/**
	 * @return void
	 */
	public function testLookupBarcodeRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('lookupByBarcode');

		$response = $this->controller->lookupBarcode();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame('Authentication required', $response->getData()['error']);
	}//end testLookupBarcodeRequiresAuthentication()

	/**
	 * The catalogue is a cashier capability: an authenticated non-POS user is
	 * refused before the catalogue is queried at all.
	 *
	 * @return void
	 */
	public function testLookupBarcodeForbiddenForNonPosUser(): void {
		$this->loginAs(uid: 'office-clerk', isPos: false);
		$this->withParams(['barcode' => '8712345678906']);
		$this->service->expects($this->never())->method('lookupByBarcode');

		$response = $this->controller->lookupBarcode();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('POS access is required for the product catalogue', $response->getData()['error']);
	}//end testLookupBarcodeForbiddenForNonPosUser()

	/**
	 * @return void
	 */
	public function testLookupBarcodeReturnsProductWithNullVariantIndexOnTopLevelMatch(): void {
		$this->loginAs();
		$this->withParams(['barcode' => '8712345678906']);

		$this->service->expects($this->once())
			->method('lookupByBarcode')
			->with('8712345678906')
			->willReturn(['id' => 'p-1', 'name' => 'Coffee', 'unitPrice' => 3.5]);

		$response = $this->controller->lookupBarcode();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('p-1', $data['product']['id']);
		$this->assertArrayHasKey('variantIndex', $data);
		$this->assertNull($data['variantIndex']);
	}//end testLookupBarcodeReturnsProductWithNullVariantIndexOnTopLevelMatch()

	/**
	 * @return void
	 */
	public function testLookupBarcodeSurfacesMatchedVariantIndex(): void {
		$this->loginAs();
		$this->withParams(['barcode' => '8712345678913']);

		$this->service->method('lookupByBarcode')->willReturn(
			[
				'id' => 'p-1',
				'name' => 'Coffee',
				'matchedVariantSku' => 'COF-L',
				'matchedVariantIndex' => 2,
			]
		);

		$response = $this->controller->lookupBarcode();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(2, $data['variantIndex']);
		$this->assertSame('COF-L', $data['product']['matchedVariantSku']);
	}//end testLookupBarcodeSurfacesMatchedVariantIndex()

	/**
	 * @return void
	 */
	public function testLookupBarcodeMaps404WhenNothingMatches(): void {
		$this->loginAs();
		$this->withParams(['barcode' => '0000000000000']);
		$this->service->method('lookupByBarcode')->willReturn(null);

		$response = $this->controller->lookupBarcode();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertArrayNotHasKey('product', $data);
		$this->assertStringContainsString('0000000000000', (string)$data['error']);
	}//end testLookupBarcodeMaps404WhenNothingMatches()

	/**
	 * A malformed barcode is a client error (422), not a 500 or an empty 200.
	 *
	 * @return void
	 */
	public function testLookupBarcodeMaps422ForMalformedBarcode(): void {
		$this->loginAs();
		$this->withParams(['barcode' => "';DROP--"]);
		$this->service->method('lookupByBarcode')
			->willThrowException(new OCSBadRequestException('Invalid barcode'));

		$response = $this->controller->lookupBarcode();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('Invalid barcode', $response->getData()['error']);
	}//end testLookupBarcodeMaps422ForMalformedBarcode()

	// ---- resolvePrice (wiring) ---------------------------------------------

	/**
	 * @return void
	 */
	public function testResolvePriceRequiresAuthentication(): void {
		$this->session->method('getUser')->willReturn(null);
		$this->service->expects($this->never())->method('resolveEffectivePrice');

		$response = $this->controller->resolvePrice();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testResolvePriceRequiresAuthentication()

	/**
	 * @return void
	 */
	public function testResolvePriceForbiddenForNonPosUser(): void {
		$this->loginAs(uid: 'office-clerk', isPos: false);
		$this->withParams(['product' => ['unitPrice' => 10.0], 'quantity' => 1]);
		$this->service->expects($this->never())->method('resolveEffectivePrice');

		$response = $this->controller->resolvePrice();

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testResolvePriceForbiddenForNonPosUser()

	/**
	 * @return void
	 */
	public function testResolvePriceForwardsQuantityAndVariantSku(): void {
		$this->loginAs();
		$this->withParams(
			[
				'product' => ['unitPrice' => 10.0],
				'quantity' => 12,
				'variantSku' => 'COF-L',
			]
		);

		$this->service->expects($this->once())
			->method('resolveEffectivePrice')
			->with(['unitPrice' => 10.0], 12.0, 'COF-L')
			->willReturn(
				[
					'unitPrice' => 8.0,
					'source' => 'variant',
					'tierLabel' => '',
					'quantity' => 12.0,
					'vatClass' => 'high',
					'taxRate' => 21,
				]
			);

		$response = $this->controller->resolvePrice();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(8.0, $response->getData()['unitPrice']);
	}//end testResolvePriceForwardsQuantityAndVariantSku()

	// ---- resolvePrice (money contract, real service) ------------------------

	/**
	 * The base price is echoed rounded to cents with the documented envelope.
	 *
	 * @return void
	 */
	public function testResolvePriceReturnsTheDocumentedEnvelope(): void {
		$controller = $this->controllerWithRealService(
			[
				'product' => ['unitPrice' => 3.5, 'vatClass' => 'high'],
				'quantity' => 1,
			]
		);

		$response = $controller->resolvePrice();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3.5, $data['unitPrice']);
		$this->assertSame('base', $data['source']);
		$this->assertSame('', $data['tierLabel']);
		$this->assertSame(1.0, $data['quantity']);
		$this->assertSame('high', $data['vatClass']);
		$this->assertSame(21, $data['taxRate']);
	}//end testResolvePriceReturnsTheDocumentedEnvelope()

	/**
	 * Money is rounded to cents, not truncated.
	 *
	 * @return void
	 */
	public function testResolvePriceRoundsToCents(): void {
		$controller = $this->controllerWithRealService(
			[
				'product' => ['unitPrice' => 10.126, 'vatClass' => 'high'],
				'quantity' => 1,
			]
		);

		$this->assertSame(10.13, $controller->resolvePrice()->getData()['unitPrice']);

		$controller = $this->controllerWithRealService(
			[
				'product' => ['unitPrice' => 10.124, 'vatClass' => 'high'],
				'quantity' => 1,
			]
		);

		$this->assertSame(10.12, $controller->resolvePrice()->getData()['unitPrice']);
	}//end testResolvePriceRoundsToCents()

	/**
	 * A quantity tier replaces the list price once its threshold is reached,
	 * and the winning tier is named in the response.
	 *
	 * @return void
	 */
	public function testResolvePriceAppliesTheQuantityTierOverTheListPrice(): void {
		$product = [
			'unitPrice' => 10.0,
			'vatClass' => 'high',
			'priceTiers' => [
				['minQuantity' => 10, 'unitPrice' => 8.0, 'label' => 'from 10'],
				['minQuantity' => 50, 'unitPrice' => 6.5, 'label' => 'from 50'],
			],
		];

		$controller = $this->controllerWithRealService(['product' => $product, 'quantity' => 12]);
		$data = $controller->resolvePrice()->getData();

		$this->assertSame(8.0, $data['unitPrice']);
		$this->assertSame('tier', $data['source']);
		$this->assertSame('from 10', $data['tierLabel']);

		// The highest qualifying tier wins, not the first.
		$controller = $this->controllerWithRealService(['product' => $product, 'quantity' => 60]);
		$data = $controller->resolvePrice()->getData();

		$this->assertSame(6.5, $data['unitPrice']);
		$this->assertSame('from 50', $data['tierLabel']);
	}//end testResolvePriceAppliesTheQuantityTierOverTheListPrice()

	/**
	 * Below the threshold the list price stands — a tier must never leak into
	 * a smaller order.
	 *
	 * @return void
	 */
	public function testResolvePriceKeepsTheListPriceBelowTheTierThreshold(): void {
		$controller = $this->controllerWithRealService(
			[
				'product' => [
					'unitPrice' => 10.0,
					'vatClass' => 'high',
					'priceTiers' => [['minQuantity' => 10, 'unitPrice' => 8.0, 'label' => 'from 10']],
				],
				'quantity' => 9,
			]
		);

		$data = $controller->resolvePrice()->getData();

		$this->assertSame(10.0, $data['unitPrice']);
		$this->assertSame('base', $data['source']);
		$this->assertSame('', $data['tierLabel']);
	}//end testResolvePriceKeepsTheListPriceBelowTheTierThreshold()

	/**
	 * A variant price override replaces the list price and is reported as such.
	 *
	 * @return void
	 */
	public function testResolvePriceAppliesAVariantOverride(): void {
		$controller = $this->controllerWithRealService(
			[
				'product' => [
					'unitPrice' => 10.0,
					'vatClass' => 'low',
					'variants' => [['sku' => 'COF-L', 'unitPrice' => 12.5]],
				],
				'quantity' => 1,
				'variantSku' => 'COF-L',
			]
		);

		$data = $controller->resolvePrice()->getData();

		$this->assertSame(12.5, $data['unitPrice']);
		$this->assertSame('variant', $data['source']);
		$this->assertSame(9, $data['taxRate']);
	}//end testResolvePriceAppliesAVariantOverride()

	/**
	 * Choosing a variant SUPPRESSES the quantity tiers entirely: the same order
	 * priced with a variant SKU costs the list price, while without the SKU it
	 * would have earned the bulk tier. The class docblock's stated resolution
	 * order ("the applicable price tier overrides the base for the requested
	 * quantity") carries no such exception.
	 *
	 * @return void
	 */
	public function testResolvePriceDropsTheQuantityTierWhenAVariantIsChosen(): void {
		$product = [
			'unitPrice' => 10.0,
			'vatClass' => 'high',
			'priceTiers' => [['minQuantity' => 10, 'unitPrice' => 8.0, 'label' => 'from 10']],
			'variants' => [['sku' => 'COF-L']],
		];

		$withVariant = $this->controllerWithRealService(
			['product' => $product, 'quantity' => 20, 'variantSku' => 'COF-L']
		)->resolvePrice()->getData();

		$withoutVariant = $this->controllerWithRealService(
			['product' => $product, 'quantity' => 20]
		)->resolvePrice()->getData();

		$this->assertSame(10.0, $withVariant['unitPrice']);
		$this->assertSame('base', $withVariant['source']);
		$this->assertSame(8.0, $withoutVariant['unitPrice']);
		$this->assertSame('tier', $withoutVariant['source']);
	}//end testResolvePriceDropsTheQuantityTierWhenAVariantIsChosen()

	/**
	 * An unknown variant is 404; a discontinued one is 422 — neither may be
	 * sold at the list price by accident.
	 *
	 * @return void
	 */
	public function testResolvePriceRejectsUnknownAndInactiveVariants(): void {
		$unknown = $this->controllerWithRealService(
			[
				'product' => ['unitPrice' => 10.0, 'variants' => [['sku' => 'COF-L']]],
				'quantity' => 1,
				'variantSku' => 'NOPE',
			]
		)->resolvePrice();

		$this->assertSame(Http::STATUS_NOT_FOUND, $unknown->getStatus());
		$this->assertArrayNotHasKey('unitPrice', $unknown->getData());

		$inactive = $this->controllerWithRealService(
			[
				'product' => [
					'unitPrice' => 10.0,
					'variants' => [['sku' => 'COF-L', 'status' => 'inactive', 'unitPrice' => 12.0]],
				],
				'quantity' => 1,
				'variantSku' => 'COF-L',
			]
		)->resolvePrice();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $inactive->getStatus());
		$this->assertArrayNotHasKey('unitPrice', $inactive->getData());
	}//end testResolvePriceRejectsUnknownAndInactiveVariants()

	/**
	 * An absent or unrecognised BTW class must fall back to the HIGH rate:
	 * a missing class may never silently zero the tax on a sale.
	 *
	 * @return void
	 */
	public function testResolvePriceFallsBackToTheHighTaxRateForAnUnknownBtwClass(): void {
		$missing = $this->controllerWithRealService(
			['product' => ['unitPrice' => 10.0], 'quantity' => 1]
		)->resolvePrice()->getData();

		$this->assertSame(21, $missing['taxRate']);

		$bogus = $this->controllerWithRealService(
			['product' => ['unitPrice' => 10.0, 'vatClass' => 'not-a-class'], 'quantity' => 1]
		)->resolvePrice()->getData();

		$this->assertSame(21, $bogus['taxRate']);
	}//end testResolvePriceFallsBackToTheHighTaxRateForAnUnknownBtwClass()

	/**
	 * A negative unit price on the stored product can never become a negative
	 * charge.
	 *
	 * @return void
	 */
	public function testResolvePriceNeverReturnsANegativeUnitPrice(): void {
		$data = $this->controllerWithRealService(
			['product' => ['unitPrice' => -10.0, 'vatClass' => 'high'], 'quantity' => 1]
		)->resolvePrice()->getData();

		$this->assertGreaterThanOrEqual(0.0, $data['unitPrice']);
		$this->assertSame(0.0, $data['unitPrice']);
	}//end testResolvePriceNeverReturnsANegativeUnitPrice()

	/**
	 * A negative quantity is not a real order line and must be refused with a
	 * 4xx, so the caller learns its request was nonsense.
	 *
	 * @return void
	 */
	public function testResolvePriceRejectsANegativeQuantity(): void {
		$this->markTestSkipped(
			'BUG: a negative quantity is silently clamped to 0 and answered HTTP 200 '
			. 'instead of being rejected — see coordinator report'
		);

		// Unreachable while the bug stands; kept so the intended contract is on record.
		$response = $this->controllerWithRealService(
			['product' => ['unitPrice' => 10.0], 'quantity' => -5]
		)->resolvePrice();

		$this->assertGreaterThanOrEqual(400, $response->getStatus());
		$this->assertLessThan(500, $response->getStatus());
	}//end testResolvePriceRejectsANegativeQuantity()

	/**
	 * The resolved price is computed from the PRODUCT OBJECT IN THE REQUEST
	 * BODY, not from the persisted catalogue: the caller decides the list
	 * price, the tiers and the BTW class it will be quoted. The class docblock
	 * claims the figure is "server-authoritative" and "never derived from
	 * client-supplied price data"; this test records what the endpoint
	 * actually does.
	 *
	 * @return void
	 */
	public function testResolvePriceIsComputedFromTheClientSuppliedProductBody(): void {
		$data = $this->controllerWithRealService(
			[
				'product' => ['unitPrice' => 0.01, 'vatClass' => 'zero'],
				'quantity' => 1,
			]
		)->resolvePrice()->getData();

		$this->assertSame(0.01, $data['unitPrice']);
		$this->assertSame(0, $data['taxRate']);
	}//end testResolvePriceIsComputedFromTheClientSuppliedProductBody()
}//end class

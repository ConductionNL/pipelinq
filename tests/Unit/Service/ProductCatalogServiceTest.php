<?php

/**
 * Unit tests for ProductCatalogService.
 *
 * Covers the server-authoritative catalogue resolution core: BTW class to tax
 * rate mapping, quantity price-tier selection, per-variant price override, and
 * variant SKU uniqueness. The barcode lookup that touches the OpenRegister
 * ObjectService is exercised at the integration level in CI (ObjectService is
 * not autoloadable in the unit container); the pure matching logic it relies on
 * is covered here via findVariant.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ProductCatalogService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for ProductCatalogService.
 */
class ProductCatalogServiceTest extends TestCase
{

    /**
     * The service under test.
     *
     * @var ProductCatalogService
     */
    private ProductCatalogService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $appConfig = $this->createMock(IAppConfig::class);
        $logger    = $this->createMock(LoggerInterface::class);

        $this->service = new ProductCatalogService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * btwClassToRate maps each valid class to its authoritative rate.
     *
     * @return void
     */
    public function testBtwClassToRateMapsAllClasses(): void
    {
        $this->assertSame(21, $this->service->btwClassToRate('hoog'));
        $this->assertSame(9, $this->service->btwClassToRate('laag'));
        $this->assertSame(0, $this->service->btwClassToRate('nul'));
        $this->assertSame(0, $this->service->btwClassToRate('vrijgesteld'));
    }//end testBtwClassToRateMapsAllClasses()

    /**
     * btwClassToRate falls back to the high rate for unknown / empty input,
     * so a missing class can never silently drop tax to zero.
     *
     * @return void
     */
    public function testBtwClassToRateFailsClosedToHigh(): void
    {
        $this->assertSame(21, $this->service->btwClassToRate(null));
        $this->assertSame(21, $this->service->btwClassToRate(''));
        $this->assertSame(21, $this->service->btwClassToRate('onzin'));
    }//end testBtwClassToRateFailsClosedToHigh()

    /**
     * isValidBtwClass accepts exactly the four canonical classes.
     *
     * @return void
     */
    public function testIsValidBtwClass(): void
    {
        $this->assertTrue($this->service->isValidBtwClass('hoog'));
        $this->assertTrue($this->service->isValidBtwClass('vrijgesteld'));
        $this->assertFalse($this->service->isValidBtwClass('high'));
        $this->assertFalse($this->service->isValidBtwClass(null));
    }//end testIsValidBtwClass()

    /**
     * sortPriceTiers sorts ascending and drops invalid tiers.
     *
     * @return void
     */
    public function testSortPriceTiersSortsAndCleans(): void
    {
        $tiers = [
            ['minQuantity' => 10, 'unitPrice' => 4.25, 'label' => 'C'],
            ['minQuantity' => 1, 'unitPrice' => 5.49, 'label' => 'A'],
            ['minQuantity' => 0, 'unitPrice' => 9.99, 'label' => 'invalid'],
            ['minQuantity' => 5, 'unitPrice' => 4.75, 'label' => 'B'],
        ];

        $sorted = $this->service->sortPriceTiers($tiers);

        $this->assertCount(3, $sorted);
        $this->assertSame(1, $sorted[0]['minQuantity']);
        $this->assertSame(5, $sorted[1]['minQuantity']);
        $this->assertSame(10, $sorted[2]['minQuantity']);
    }//end testSortPriceTiersSortsAndCleans()

    /**
     * resolveTier selects the highest tier at or below the quantity.
     *
     * @return void
     */
    public function testResolveTierSelectsCorrectTier(): void
    {
        $tiers = [
            ['minQuantity' => 1, 'unitPrice' => 5.49, 'label' => 'A'],
            ['minQuantity' => 5, 'unitPrice' => 4.75, 'label' => 'B'],
            ['minQuantity' => 10, 'unitPrice' => 4.25, 'label' => 'C'],
        ];

        $this->assertSame('A', $this->service->resolveTier($tiers, 2.0)['label']);
        $this->assertSame('B', $this->service->resolveTier($tiers, 6.0)['label']);
        $this->assertSame('C', $this->service->resolveTier($tiers, 12.0)['label']);
    }//end testResolveTierSelectsCorrectTier()

    /**
     * resolveTier returns null below the lowest tier threshold.
     *
     * @return void
     */
    public function testResolveTierReturnsNullBelowLowest(): void
    {
        $tiers = [['minQuantity' => 5, 'unitPrice' => 4.75, 'label' => 'B']];

        $this->assertNull($this->service->resolveTier($tiers, 2.0));
    }//end testResolveTierReturnsNullBelowLowest()

    /**
     * resolveEffectivePrice applies the qty>=5 tier (spec REQ-PPC-003 example:
     * A4 Papier 500 vel at quantity 6 => EUR 4.75).
     *
     * @return void
     */
    public function testResolveEffectivePriceAppliesTier(): void
    {
        $product = [
            'unitPrice'  => 5.49,
            'btwClass'   => 'hoog',
            'priceTiers' => [
                ['minQuantity' => 1, 'unitPrice' => 5.49, 'label' => 'Losse verpakking'],
                ['minQuantity' => 5, 'unitPrice' => 4.75, 'label' => 'Doos (5 pakken)'],
                ['minQuantity' => 10, 'unitPrice' => 4.25, 'label' => 'Pallet (10+ pakken)'],
            ],
        ];

        $result = $this->service->resolveEffectivePrice($product, 6.0);

        $this->assertSame(4.75, $result['unitPrice']);
        $this->assertSame('tier', $result['source']);
        $this->assertSame('Doos (5 pakken)', $result['tierLabel']);
        $this->assertSame(21, $result['taxRate']);
    }//end testResolveEffectivePriceAppliesTier()

    /**
     * resolveEffectivePrice falls back to the base price below the lowest tier.
     *
     * @return void
     */
    public function testResolveEffectivePriceFallsBackToBase(): void
    {
        $product = [
            'unitPrice'  => 5.49,
            'btwClass'   => 'hoog',
            'priceTiers' => [
                ['minQuantity' => 1, 'unitPrice' => 5.49, 'label' => 'Losse verpakking'],
                ['minQuantity' => 5, 'unitPrice' => 4.75, 'label' => 'Doos'],
            ],
        ];

        $result = $this->service->resolveEffectivePrice($product, 2.0);

        $this->assertSame(5.49, $result['unitPrice']);
        $this->assertSame('tier', $result['source']);
    }//end testResolveEffectivePriceFallsBackToBase()

    /**
     * resolveEffectivePrice honours a per-variant price override.
     *
     * @return void
     */
    public function testResolveEffectivePriceUsesVariantOverride(): void
    {
        $product = [
            'unitPrice' => 19.95,
            'btwClass'  => 'hoog',
            'variants'  => [
                ['sku' => 'TSH-L-WIT', 'unitPrice' => 19.95, 'status' => 'active'],
                ['sku' => 'TSH-L-ZWA', 'unitPrice' => 21.95, 'status' => 'active'],
            ],
        ];

        $result = $this->service->resolveEffectivePrice($product, 1.0, 'TSH-L-ZWA');

        $this->assertSame(21.95, $result['unitPrice']);
        $this->assertSame('variant', $result['source']);
    }//end testResolveEffectivePriceUsesVariantOverride()

    /**
     * resolveEffectivePrice rejects an inactive variant.
     *
     * @return void
     */
    public function testResolveEffectivePriceRejectsInactiveVariant(): void
    {
        $product = [
            'unitPrice' => 19.95,
            'variants'  => [
                ['sku' => 'TSH-L-ZWA', 'unitPrice' => 21.95, 'status' => 'inactive'],
            ],
        ];

        $this->expectException(OCSBadRequestException::class);
        $this->service->resolveEffectivePrice($product, 1.0, 'TSH-L-ZWA');
    }//end testResolveEffectivePriceRejectsInactiveVariant()

    /**
     * A vrijgesteld product resolves to a zero tax rate.
     *
     * @return void
     */
    public function testResolveEffectivePriceVrijgesteldZeroRate(): void
    {
        $product = ['unitPrice' => 125.00, 'btwClass' => 'vrijgesteld'];

        $result = $this->service->resolveEffectivePrice($product, 1.0);

        $this->assertSame(0, $result['taxRate']);
        $this->assertSame('vrijgesteld', $result['btwClass']);
    }//end testResolveEffectivePriceVrijgesteldZeroRate()

    /**
     * findVariant returns the matching variant or null.
     *
     * @return void
     */
    public function testFindVariant(): void
    {
        $product = [
            'variants' => [
                ['sku' => 'TSH-S-WIT', 'barcode' => '8712345600002'],
                ['sku' => 'TSH-M-ZWA', 'barcode' => '8712345600008'],
            ],
        ];

        $this->assertSame('8712345600008', $this->service->findVariant($product, 'TSH-M-ZWA')['barcode']);
        $this->assertNull($this->service->findVariant($product, 'NOPE'));
    }//end testFindVariant()

    /**
     * variantSkusUnique detects duplicate and empty SKUs.
     *
     * @return void
     */
    public function testVariantSkusUnique(): void
    {
        $this->assertTrue(
            $this->service->variantSkusUnique(
                    [
                        ['sku' => 'A'],
                        ['sku' => 'B'],
                    ]
                    )
        );

        $this->assertFalse(
            $this->service->variantSkusUnique(
                    [
                        ['sku' => 'A'],
                        ['sku' => 'A'],
                    ]
                    )
        );

        $this->assertFalse(
            $this->service->variantSkusUnique(
                    [
                        ['sku' => ''],
                    ]
                    )
        );
    }//end testVariantSkusUnique()

    /**
     * lookupByBarcode rejects an empty barcode before touching OpenRegister.
     *
     * @return void
     */
    public function testLookupByBarcodeRejectsEmpty(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->lookupByBarcode('   ');
    }//end testLookupByBarcodeRejectsEmpty()
}//end class

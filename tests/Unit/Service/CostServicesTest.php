<?php

/**
 * Unit tests for the cost capture, estimation and exchange-rate services.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\CostCaptureService;
use OCA\Pipelinq\Service\CostEstimationService;
use OCA\Pipelinq\Service\ExchangeRateService;
use OCA\Pipelinq\Service\Messaging\DeliveryUpdate;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for cost capture, estimation and conversion.
 */
class CostServicesTest extends TestCase
{
    /**
     * The price-table estimator estimates per category and country.
     *
     * @return void
     */
    public function testEstimationByCategoryAndCountry(): void
    {
        $estimator = new CostEstimationService();

        $this->assertEqualsWithDelta(0.0120, $estimator->estimate('utility', 'NL'), 0.0001);
        $this->assertEqualsWithDelta(0.0768, $estimator->estimate('marketing', 'NL'), 0.0001);
        // Unknown country falls back to DEFAULT.
        $this->assertEqualsWithDelta(0.0150, $estimator->estimate('utility', 'ZZ'), 0.0001);
        // Unknown category falls back to utility.
        $this->assertEqualsWithDelta(0.0120, $estimator->estimate('nonsense', 'NL'), 0.0001);
    }//end testEstimationByCategoryAndCountry()

    /**
     * Country is derived from the E.164 dialling prefix.
     *
     * @return void
     */
    public function testCountryFromE164(): void
    {
        $estimator = new CostEstimationService();

        $this->assertSame('NL', $estimator->countryFromE164('+31699998888'));
        $this->assertSame('BE', $estimator->countryFromE164('+32499998888'));
        $this->assertSame('', $estimator->countryFromE164('+99912345678'));
    }//end testCountryFromE164()

    /**
     * ECB conversion uses the cached rate map; EUR passes through.
     *
     * @return void
     */
    public function testExchangeRateConversion(): void
    {
        $appConfig = $this->createMock(IAppConfig::class);
        $today     = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d');
        $appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'ecb_rate_cache_date', '', $today],
                ['pipelinq', 'ecb_rate_cache', '{}', '{"USD":1.08}'],
            ]
        );

        $service = new ExchangeRateService(
            $this->createMock(IClientService::class),
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );

        $this->assertEqualsWithDelta(0.0069, $service->toEur(0.0075, 'USD'), 0.0001);
        $this->assertEqualsWithDelta(5.0, $service->toEur(5.0, 'EUR'), 0.0001);
        // Unknown currency yields null (deferred for reconciliation).
        $this->assertNull($service->toEur(1.0, 'JPY'));
    }//end testExchangeRateConversion()

    /**
     * Cost capture converts an exposed Twilio price to EUR.
     *
     * @return void
     */
    public function testCaptureConvertsExposedCost(): void
    {
        $exchange = $this->createMock(ExchangeRateService::class);
        $exchange->method('toEur')->willReturn(0.0069);

        $capture = new CostCaptureService($exchange, new CostEstimationService());
        $update  = new DeliveryUpdate('SM1', 'delivered', 0.0075, 'USD');

        $result = $capture->resolve($update, 'utility', '+31699998888');

        $this->assertEqualsWithDelta(0.0069, $result['costEur'], 0.0001);
        $this->assertFalse($result['estimated']);
        $this->assertFalse($result['currencyPending']);
    }//end testCaptureConvertsExposedCost()

    /**
     * When the rate is unavailable, the cost is held pending reconciliation.
     *
     * @return void
     */
    public function testCaptureDefersWhenRateUnavailable(): void
    {
        $exchange = $this->createMock(ExchangeRateService::class);
        $exchange->method('toEur')->willReturn(null);

        $capture = new CostCaptureService($exchange, new CostEstimationService());
        $update  = new DeliveryUpdate('SM1', 'delivered', 0.0075, 'USD');

        $result = $capture->resolve($update, 'utility', '+31699998888');

        $this->assertNull($result['costEur']);
        $this->assertTrue($result['currencyPending']);
        $this->assertEqualsWithDelta(0.0075, $result['sourceAmount'], 0.0001);
        $this->assertSame('USD', $result['sourceCurrency']);
    }//end testCaptureDefersWhenRateUnavailable()

    /**
     * When no cost is exposed (Meta), the cost is estimated and flagged.
     *
     * @return void
     */
    public function testCaptureEstimatesWhenNoCostExposed(): void
    {
        $capture = new CostCaptureService(
            $this->createMock(ExchangeRateService::class),
            new CostEstimationService()
        );
        $update  = new DeliveryUpdate('wamid.x', 'delivered');

        $result = $capture->resolve($update, 'utility', '+31699998888');

        $this->assertTrue($result['estimated']);
        $this->assertFalse($result['currencyPending']);
        $this->assertEqualsWithDelta(0.0120, $result['costEur'], 0.0001);
    }//end testCaptureEstimatesWhenNoCostExposed()
}//end class

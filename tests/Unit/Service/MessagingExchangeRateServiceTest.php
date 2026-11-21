<?php

/**
 * MessagingExchangeRateService: every way a rate is found.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\MessagingExchangeRateService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The configured rates win, the built-in ones are the fallback.
 */
class MessagingExchangeRateServiceTest extends TestCase {

	/**
	 * A service over the given override and logger.
	 *
	 * @param string               $override The configured rates JSON.
	 * @param LoggerInterface|null $logger   The logger.
	 *
	 * @return MessagingExchangeRateService
	 */
	private function service(string $override, ?LoggerInterface $logger = null): MessagingExchangeRateService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($app === 'pipelinq' && $key === 'messaging.exchange_rates_json' ? $override : $default)
		);

		return new MessagingExchangeRateService($appConfig, ($logger ?? $this->createMock(LoggerInterface::class)));
	}//end service()

	/**
	 * Every way a rate is found: a blank currency has none; the currency is
	 * trimmed and upper-cased; a numeric configured rate wins; a missing or
	 * non-numeric one falls back to the built-in rate; invalid JSON is logged
	 * and falls back; an unknown currency has none; euro amounts convert as
	 * they are.
	 *
	 * @return void
	 */
	public function testEveryWayARateIsFound(): void {
		$service = $this->service('{"USD": "0.5", "GBP": "lots", "JPY": 0.006}');
		$this->assertNull($service->getRate(currency: ' '));
		$this->assertSame(0.5, $service->getRate(currency: ' usd '));
		$this->assertSame(1.17, $service->getRate(currency: 'GBP'));
		$this->assertSame(0.006, $service->getRate(currency: 'jpy'));
		$this->assertSame(1.04, $service->getRate(currency: 'CHF'));
		$this->assertNull($service->getRate(currency: 'SEK'));
		$this->assertSame(1.0, $service->getRate(currency: 'EUR'));

		$this->assertSame(0.92, $this->service('')->getRate(currency: 'USD'));
		$this->assertSame(0.92, $this->service('[1, 2]')->getRate(currency: 'USD'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('MessagingExchangeRateService.getRate: invalid exchange_rates_json', ['exception' => 'Syntax error']);
		$this->assertSame(0.92, $this->service('{bad', $logger)->getRate(currency: 'USD'));

		$this->assertSame(10.0, $service->toEur(amount: 10.0, currency: ' eur '));
		$this->assertSame(10.0, $service->toEur(amount: 10.0, currency: ''));
		$this->assertSame(5.0, $service->toEur(amount: 10.0, currency: 'usd'));
		$this->assertNull($service->toEur(amount: 10.0, currency: 'SEK'));
	}//end testEveryWayARateIsFound()
}//end class

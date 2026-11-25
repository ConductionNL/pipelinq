<?php

/**
 * Unit tests for EcbRateReader.
 *
 * integriq is never installed here: OpenRegister's object service is a fake
 * that answers with rows of the asked currency, says the register does not
 * exist, or fails.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\EcbRateReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for reading the ECB rates integriq keeps.
 */
class EcbRateReaderTest extends TestCase {

	/**
	 * Build a reader over a fake object service.
	 *
	 * @param array<int, array<string, mixed>> $rows    The exchange_rate rows integriq holds.
	 * @param string|null                      $failure An exception message the read throws, or null.
	 * @param array<int, array<string, mixed>> $asked   Receives every query made.
	 *
	 * @return EcbRateReader The reader.
	 */
	private function build(array $rows = [], ?string $failure = null, array &$asked = []): EcbRateReader {
		$service = new class ($rows, $failure, $asked) {

			/**
			 * @param array<int, array<string, mixed>> $rows    The rows.
			 * @param string|null                      $failure The exception message, or null.
			 * @param array<int, array<string, mixed>> $asked   Receives every query made.
			 */
			public function __construct(private array $rows, private ?string $failure, private array &$asked) {
			}//end __construct()

			/**
			 * Answer with the rows of the asked currency, ignoring the access flags.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         Access flag.
			 * @param bool                 $_multitenancy Access flag.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->asked[] = $config;
				if ($this->failure !== null) {
					throw new RuntimeException($this->failure);
				}

				$currency = $config['filters']['currency'];
				return array_values(array_filter($this->rows, static fn (array $row): bool => $row['currency'] === $currency));
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service): object {
				if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $service;
				}

				throw new RuntimeException('not registered: '.$id);
			}
		);

		return new EcbRateReader(container: $container, logger: $this->createMock(LoggerInterface::class));
	}//end build()

	/**
	 * One exchange_rate row as integriq writes it.
	 *
	 * @param string $currency The currency.
	 * @param float  $rate     Units of the currency per euro.
	 * @param string $date     The rate date.
	 * @param string $source   The source.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $currency, float $rate, string $date, string $source = 'ecb'): array {
		return ['baseCurrency' => 'EUR', 'currency' => $currency, 'rateDate' => $date, 'rate' => $rate, 'source' => $source];
	}//end row()

	/**
	 * Rates present: a euro rate is inverted to units of euro per unit, and
	 * the query names register, schema, currency and newest first.
	 *
	 * @return void
	 */
	public function testRateToTheEuroFromIntegriqRows(): void {
		$asked = [];
		$reader = $this->build(rows: [$this->row('USD', 1.0950, '2026-10-30')], asked: $asked);

		$rate = $reader->rateFor('USD', 'EUR', '2026-10-30');
		$this->assertSame(['rate' => 0.913242, 'date' => '2026-10-30'], $rate);
		$this->assertSame('integriq', $asked[0]['filters']['register']);
		$this->assertSame('exchange_rate', $asked[0]['filters']['schema']);
		$this->assertSame('USD', $asked[0]['filters']['currency']);
		$this->assertSame(['rateDate' => 'desc'], $asked[0]['sort']);
	}//end testRateToTheEuroFromIntegriqRows()

	/**
	 * From the euro the rate is the stored rate itself.
	 *
	 * @return void
	 */
	public function testRateFromTheEuro(): void {
		$reader = $this->build(rows: [$this->row('GBP', 0.8421, '2026-10-30')]);
		$this->assertSame(['rate' => 0.8421, 'date' => '2026-10-30'], $reader->rateFor('EUR', 'GBP', '2026-10-30'));
	}//end testRateFromTheEuro()

	/**
	 * Two non-euro currencies cross through the euro with the older date.
	 *
	 * @return void
	 */
	public function testCrossRateThroughTheEuro(): void {
		$reader = $this->build(rows: [$this->row('USD', 1.10, '2026-10-30'), $this->row('GBP', 0.88, '2026-10-29')]);
		$this->assertSame(['rate' => 0.8, 'date' => '2026-10-29'], $reader->rateFor('USD', 'GBP', '2026-10-30'));
	}//end testCrossRateThroughTheEuro()

	/**
	 * No rate on the day itself (a weekend): the newest date on or before it
	 * is taken, and a later date is never used.
	 *
	 * @return void
	 */
	public function testNewestRateOnOrBeforeTheDay(): void {
		$reader = $this->build(
			rows: [
				$this->row('USD', 1.20, '2026-11-02'),
				$this->row('USD', 1.00, '2026-10-29'),
				$this->row('USD', 1.25, '2026-10-30'),
			]
		);

		$this->assertSame(['rate' => 0.8, 'date' => '2026-10-30'], $reader->rateFor('USD', 'EUR', '2026-11-01'));
	}//end testNewestRateOnOrBeforeTheDay()

	/**
	 * Rows of another source or base, and unusable rows, are skipped.
	 *
	 * @return void
	 */
	public function testUnusableRowsSkipped(): void {
		$reader = $this->build(
			rows: [
				$this->row('USD', 1.30, '2026-10-30', 'manual'),
				['baseCurrency' => 'USD', 'currency' => 'USD', 'rateDate' => '2026-10-30', 'rate' => 1.0, 'source' => 'ecb'],
				$this->row('USD', 0.0, '2026-10-30'),
				$this->row('USD', 1.25, 'yesterday'),
			]
		);

		$this->assertNull($reader->rateFor('USD', 'EUR', '2026-10-30'));
	}//end testUnusableRowsSkipped()

	/**
	 * integriq absent: OpenRegister knows no such register, so no rate.
	 *
	 * @return void
	 */
	public function testNoSourceGivesNoRate(): void {
		$reader = $this->build(failure: 'Register not found');
		$this->assertNull($reader->rateFor('USD', 'EUR', '2026-10-30'));
	}//end testNoSourceGivesNoRate()

	/**
	 * OpenRegister unavailable: no rate, no error.
	 *
	 * @return void
	 */
	public function testObjectServiceMissingGivesNoRate(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('Service unavailable'));
		$reader = new EcbRateReader(container: $container, logger: $this->createMock(LoggerInterface::class));

		$this->assertNull($reader->rateFor('USD', 'EUR', '2026-10-30'));
	}//end testObjectServiceMissingGivesNoRate()

	/**
	 * One read per currency and day within a request.
	 *
	 * @return void
	 */
	public function testLookupsAreRemembered(): void {
		$asked = [];
		$reader = $this->build(rows: [$this->row('USD', 1.10, '2026-10-30')], asked: $asked);
		$reader->rateFor('USD', 'EUR', '2026-10-30');
		$reader->rateFor('USD', 'EUR', '2026-10-30');

		$this->assertCount(1, $asked);
	}//end testLookupsAreRemembered()
}//end class

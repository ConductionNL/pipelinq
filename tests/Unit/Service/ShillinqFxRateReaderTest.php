<?php

/**
 * Unit tests for ShillinqFxRateReader.
 *
 * Shillinq is never installed here: the presence probe is a protected
 * method the test answers for, and OpenRegister's object service is a fake
 * that filters rows by the pair it is asked for.
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

use OCA\Pipelinq\Service\ShillinqFxRateReader;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for reading shillinq's exchange rates.
 */
class ShillinqFxRateReaderTest extends TestCase {

	/**
	 * Build a reader over a fake object service.
	 *
	 * @param bool $installed What the presence probe answers.
	 * @param array<int, array<string, mixed>> $rows The FxRate rows shillinq holds.
	 * @param bool $broken Whether the container cannot build the object service.
	 *
	 * @return ShillinqFxRateReader The reader.
	 */
	private function build(bool $installed = true, array $rows = [], bool $broken = false): ShillinqFxRateReader {
		$service = new class ($rows) {

			/**
			 * @param array<int, array<string, mixed>> $rows The rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * Answer with the rows of the asked pair, ignoring the access flags.
			 *
			 * @param array<string, mixed> $config The query.
			 * @param bool $_rbac Access flag.
			 * @param bool $_multitenancy Access flag.
			 *
			 * @return array<int, array<string, mixed>> The rows.
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$filters = $config['filters'];
				return array_values(
					array_filter(
						$this->rows,
						static fn (array $row): bool => $row['transactionCurrency'] === $filters['transactionCurrency']
							&& $row['baseCurrency'] === $filters['baseCurrency']
					)
				);
			}//end findAll()
		};

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($service, $broken): object {
				if ($broken === false && $id === 'OCA\\OpenRegister\\Service\\ObjectService') {
					return $service;
				}

				throw new \RuntimeException('not registered: ' . $id);
			}
		);

		return new class ($installed, $container, $this->createMock(LoggerInterface::class)) extends ShillinqFxRateReader {

			/**
			 * @param bool $installed What the probe answers.
			 * @param mixed ...$args The real constructor arguments.
			 */
			public function __construct(private bool $installed, ...$args) {
				parent::__construct(...$args);
			}//end __construct()

			/**
			 * @return bool
			 */
			protected function probe(): bool {
				return $this->installed;
			}//end probe()
		};
	}//end build()

	/**
	 * One FxRate row.
	 *
	 * @param string $from Transaction currency.
	 * @param string $to Base currency.
	 * @param float $rate The rate.
	 * @param string $date The date.
	 * @param string $source The shillinq source.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $from, string $to, float $rate, string $date, string $source): array {
		return ['transactionCurrency' => $from, 'baseCurrency' => $to, 'rate' => $rate, 'date' => $date, 'source' => $source];
	}//end row()

	/**
	 * Shillinq absent: no rate.
	 *
	 * @return void
	 */
	public function testShillinqAbsentGivesNoRate(): void {
		$reader = $this->build(installed: false, rows: [$this->row('USD', 'EUR', 0.91, '2026-10-30', 'ecb')]);
		$this->assertNull($reader->rateFor('USD', 'EUR'));
	}//end testShillinqAbsentGivesNoRate()

	/**
	 * OpenRegister unavailable: no rate, no error.
	 *
	 * @return void
	 */
	public function testObjectServiceMissingGivesNoRate(): void {
		$this->assertNull($this->build(broken: true)->rateFor('USD', 'EUR'));
	}//end testObjectServiceMissingGivesNoRate()

	/**
	 * Same date: ecb beats bank-feed beats manual.
	 *
	 * @return void
	 */
	public function testEcbBeatsManualOnTheSameDay(): void {
		$reader = $this->build(
			rows: [
				$this->row('USD', 'EUR', 0.95, '2026-10-30', 'manual'),
				$this->row('USD', 'EUR', 0.9132, '2026-10-30', 'ecb'),
				$this->row('USD', 'EUR', 0.93, '2026-10-30', 'bank-feed'),
			]
		);

		$this->assertSame(['rate' => 0.9132, 'date' => '2026-10-30', 'origin' => 'ecb'], $reader->rateFor('usd', 'eur'));
	}//end testEcbBeatsManualOnTheSameDay()

	/**
	 * The newest date wins over a more trusted but older source.
	 *
	 * @return void
	 */
	public function testNewestDateWins(): void {
		$reader = $this->build(
			rows: [
				$this->row('USD', 'EUR', 0.90, '2026-10-01', 'ecb'),
				$this->row('USD', 'EUR', 0.92, '2026-10-29', 'manual'),
			]
		);

		$this->assertSame('2026-10-29', $reader->rateFor('USD', 'EUR')['date']);
	}//end testNewestDateWins()

	/**
	 * Only the reverse pair is recorded: its inverse is used.
	 *
	 * @return void
	 */
	public function testReversePairInverted(): void {
		$reader = $this->build(rows: [$this->row('EUR', 'USD', 1.25, '2026-10-30', 'ecb')]);
		$this->assertSame(0.8, $reader->rateFor('USD', 'EUR')['rate']);
	}//end testReversePairInverted()

	/**
	 * USD to GBP goes through the euro and carries the older date.
	 *
	 * @return void
	 */
	public function testCrossRateThroughTheEuro(): void {
		$reader = $this->build(
			rows: [
				$this->row('USD', 'EUR', 0.9, '2026-10-30', 'ecb'),
				$this->row('GBP', 'EUR', 1.2, '2026-10-29', 'ecb'),
			]
		);

		$this->assertSame(['rate' => 0.75, 'date' => '2026-10-29', 'origin' => 'ecb'], $reader->rateFor('USD', 'GBP'));
	}//end testCrossRateThroughTheEuro()

	/**
	 * Unusable rows (zero rate, no date, another pair) are skipped.
	 *
	 * @return void
	 */
	public function testUnusableRowsSkipped(): void {
		$reader = $this->build(
			rows: [
				$this->row('USD', 'EUR', 0.0, '2026-10-30', 'ecb'),
				$this->row('USD', 'EUR', 0.9, '', 'ecb'),
			]
		);

		$this->assertNull($reader->rateFor('USD', 'EUR'));
	}//end testUnusableRowsSkipped()
}//end class

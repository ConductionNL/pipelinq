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
	/**
	 * A reader that sees shillinq installed, over the given object service and logger.
	 *
	 * @param mixed           $service The object service.
	 * @param LoggerInterface $logger  The logger.
	 *
	 * @return ShillinqFxRateReader
	 */
	private function over(mixed $service, LoggerInterface $logger): ShillinqFxRateReader {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return new class ($container, $logger) extends ShillinqFxRateReader {
			/**
			 * @return bool
			 */
			protected function probe(): bool {
				return true;
			}//end probe()
		};
	}//end over()

	/**
	 * Every way a rate read ends: input is trimmed and upper-cased; an empty
	 * or identical pair reads nothing; each pair is read once, with access
	 * off and the exact filters; rows are matched case-insensitively, dated
	 * by their first ten characters, and skipped without a positive numeric
	 * rate or a valid date; a cross rate takes the older date and the from
	 * leg's origin; a failed read is logged and reads as none; a non-list
	 * answer reads as none.
	 *
	 * @return void
	 */
	public function testEveryWayARateReadEnds(): void {
		$rows = [
			'USD>EUR' => [
				['transactionCurrency' => 'usd', 'baseCurrency' => 'eur', 'rate' => '0.5', 'date' => '2026-03-02T10:00:00Z', 'source' => 'manual'],
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'rate' => 0, 'date' => '2026-03-09', 'source' => 'ecb'],
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'rate' => 'x', 'date' => '2026-03-09', 'source' => 'ecb'],
				['transactionCurrency' => 'USD', 'baseCurrency' => 'EUR', 'rate' => 2, 'date' => '9 March', 'source' => 'ecb'],
				['transactionCurrency' => 'GBP', 'baseCurrency' => 'EUR', 'rate' => 9, 'date' => '2026-03-09', 'source' => 'ecb'],
			],
			'GBP>EUR' => [['transactionCurrency' => 'GBP', 'baseCurrency' => 'EUR', 'rate' => 0.25, 'date' => '2026-03-01', 'source' => 'ecb']],
		];
		$service = new class ($rows) {
			/** @var array<int, array<string, mixed>> */
			public array $calls = [];

			/**
			 * @param array<string, array<int, array<string, mixed>>> $rows Pair => rows.
			 */
			public function __construct(private array $rows) {
			}//end __construct()

			/**
			 * The rows of the pair, recording the call.
			 *
			 * @param array<string, mixed> $config        The query.
			 * @param bool                 $_rbac         Access flag.
			 * @param bool                 $_multitenancy Access flag.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = [$config, $_rbac, $_multitenancy];
				return ($this->rows[$config['filters']['transactionCurrency'].'>'.$config['filters']['baseCurrency']] ?? []);
			}//end findAll()
		};
		$reader = $this->over($service, $this->createMock(LoggerInterface::class));

		$this->assertNull($reader->rateFor(' ', 'EUR'));
		$this->assertNull($reader->rateFor('eur', ' EUR'));
		$this->assertSame([], $service->calls);

		$this->assertSame(['rate' => 0.5, 'date' => '2026-03-02', 'origin' => 'manual'], $reader->rateFor(' usd', 'eur '));
		$this->assertSame(
			[[['filters' => ['register' => 'shillinq', 'schema' => 'FxRate', 'transactionCurrency' => 'USD', 'baseCurrency' => 'EUR'], 'limit' => 100], false, false]],
			$service->calls
		);
		$this->assertSame(['rate' => 2.0, 'date' => '2026-03-02', 'origin' => 'manual'], $reader->rateFor('EUR', 'USD'));
		$this->assertSame(['rate' => 2.0, 'date' => '2026-03-01', 'origin' => 'manual'], $reader->rateFor('USD', 'GBP'));
		$pairs = array_map(static fn (array $call): string => $call[0]['filters']['transactionCurrency'].'>'.$call[0]['filters']['baseCurrency'], $service->calls);
		$this->assertSame(['USD>EUR', 'EUR>USD', 'USD>GBP', 'GBP>USD', 'GBP>EUR'], $pairs);
		$this->assertNull($reader->rateFor('USD', 'JPY'));

		$logger = $this->createMock(LoggerInterface::class);
		$warnings = [];
		$logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
			static function (string $message, array $context) use (&$warnings): void {
				$warnings[] = [$message, $context];
			}
		);
		$failing = new class {
			/**
			 * Refuse the read.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return array<int, mixed>
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): array {
				throw new \RuntimeException('denied');
			}//end findAll()
		};
		$reader = $this->over($failing, $logger);
		$this->assertNull($reader->rateFor('USD', 'EUR'));
		$this->assertNull($reader->rateFor('USD', 'EUR'));
		$this->assertSame(
			[
				['ShillinqFxRateReader: reading exchange rates failed', ['pair' => 'USD>EUR', 'exception' => 'denied']],
				['ShillinqFxRateReader: reading exchange rates failed', ['pair' => 'EUR>USD', 'exception' => 'denied']],
			],
			$warnings
		);

		$scalar = new class {
			/**
			 * Answer with no list.
			 *
			 * @param array<string, mixed> $config The query.
			 *
			 * @return mixed
			 */
			public function findAll(array $config, bool $_rbac = true, bool $_multitenancy = true): mixed {
				return 'nothing';
			}//end findAll()
		};
		$this->assertNull($this->over($scalar, $this->createMock(LoggerInterface::class))->rateFor('USD', 'EUR'));
	}//end testEveryWayARateReadEnds()
}//end class

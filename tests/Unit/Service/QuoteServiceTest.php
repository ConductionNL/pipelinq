<?php

/**
 * Unit tests for QuoteService.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/products-quote-from-lead/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\LineTotals\LineTotalMath;
use OCA\Pipelinq\Service\ProductCatalogService;
use OCA\Pipelinq\Service\QuoteNumberSequence;
use OCA\Pipelinq\Service\QuoteService;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A quote number sequence whose counter row lives in memory instead of the appconfig table.
 */
final class QuoteServiceTestSequence extends QuoteNumberSequence {

	/**
	 * The counter row.
	 *
	 * @var int|null
	 */
	public ?int $counter = null;

	/**
	 * Compare and set the in-memory counter.
	 *
	 * @param int $expected The value it must hold.
	 * @param int $next     The value it gets.
	 *
	 * @return int The rows affected.
	 */
	protected function casCounter(int $expected, int $next): int {
		if ($this->counter === null || $this->counter !== $expected) {
			return 0;
		}

		$this->counter = $next;
		return 1;
	}//end casCounter()

	/**
	 * Seed the in-memory counter at 1 when there is none.
	 *
	 * @return int The rows affected.
	 */
	protected function seedCounter(): int {
		if ($this->counter !== null) {
			return 0;
		}

		$this->counter = 1;
		return 1;
	}//end seedCounter()
}//end class

/**
 * Totals per VAT rate, the quote number and the recipient of a quote sent from a lead.
 */
class QuoteServiceTest extends TestCase {

	/**
	 * The stored app config integers, by key.
	 *
	 * @var array<string, int>
	 */
	private array $ints = [];

	/**
	 * The sequence behind the service.
	 *
	 * @var QuoteServiceTestSequence
	 */
	private QuoteServiceTestSequence $sequence;

	/**
	 * The service under test, over a real catalogue with the default VAT rates.
	 *
	 * @var QuoteService
	 */
	private QuoteService $service;

	/**
	 * Build the service.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default=''): string => $default
		);
		// The counter row is the sequence's own, as the appconfig table is in production.
		$appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default=0): int {
				if ($key === 'quote_number_counter') {
					return ($this->sequence->counter ?? $default);
				}

				return ($this->ints[$key] ?? $default);
			}
		);
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value): bool {
				if ($key === 'quote_number_counter') {
					$this->sequence->counter = $value;
					return true;
				}

				$this->ints[$key] = $value;

				return true;
			}
		);

		$catalog = new ProductCatalogService(
			$appConfig,
			$this->createMock(LoggerInterface::class),
			objectService: $this->createMock(ObjectServiceInterface::class)
		);

		$this->sequence = new QuoteServiceTestSequence(
			db: $this->createMock(IDBConnection::class),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
		$this->service  = new QuoteService(catalog: $catalog, numbers: $this->sequence, math: new LineTotalMath());
	}//end setUp()

	/**
	 * One line of 1000 at 21 percent and one of 500 at 9 percent give VAT 210
	 * and 45, 1500 excluding and 1755 including VAT, the higher rate first.
	 *
	 * @return void
	 */
	public function testGroupsVatPerRate(): void {
		$totals = $this->service->vatTotals(
			[
				['total' => 500, 'product' => ['vatClass' => 'low']],
				['total' => '1000.00', 'product' => ['vatClass' => 'high']],
			]
		);

		self::assertSame(
			[
				'rates'        => [
					['rate' => 21, 'net' => 1000.0, 'vat' => 210.0, 'gross' => 1210.0],
					['rate' => 9, 'net' => 500.0, 'vat' => 45.0, 'gross' => 545.0],
				],
				'totalExclVat' => 1500.0,
				'totalVat'     => 255.0,
				'totalInclVat' => 1755.0,
			],
			$totals
		);
	}//end testGroupsVatPerRate()

	/**
	 * VAT is rounded per line before it is added up: two lines of 0.05 at 9
	 * percent carry 0.00 VAT each, not 0.01 together.
	 *
	 * @return void
	 */
	public function testRoundsVatPerLine(): void {
		$totals = $this->service->vatTotals(
			[
				['total' => 0.05, 'vatRate' => 9],
				['total' => 0.05, 'vatRate' => 9],
				['total' => 10.07, 'vatRate' => 21],
			]
		);

		self::assertSame(['rate' => 9, 'net' => 0.1, 'vat' => 0.0, 'gross' => 0.1], $totals['rates'][1]);
		self::assertSame(['rate' => 21, 'net' => 10.07, 'vat' => 2.11, 'gross' => 12.18], $totals['rates'][0]);
		self::assertSame(12.28, $totals['totalInclVat']);
	}//end testRoundsVatPerLine()

	/**
	 * A line without a materialised total is worked out from quantity, unit
	 * price and discount; a line without a VAT class is taxed at the standard rate.
	 *
	 * @return void
	 */
	public function testWorksOutAMissingTotalAndDefaultsTheRate(): void {
		$totals = $this->service->vatTotals(
			[
				['quantity' => 25, 'unitPrice' => 10, 'discount' => 10, 'product' => ['vatClass' => '']],
				['quantity' => 1, 'unitPrice' => 100, 'vatRate' => '0'],
				['quantity' => 2, 'unitPrice' => 50, 'product' => 'product-uuid'],
			]
		);

		self::assertSame(
			[
				['rate' => 21, 'net' => 325.0, 'vat' => 68.25, 'gross' => 393.25],
				['rate' => 0, 'net' => 100.0, 'vat' => 0.0, 'gross' => 100.0],
			],
			$totals['rates']
		);
		self::assertSame(425.0, $totals['totalExclVat']);
	}//end testWorksOutAMissingTotalAndDefaultsTheRate()

	/**
	 * A fractional rate stays a fraction, and no lines give zero totals.
	 *
	 * @return void
	 */
	public function testKeepsAFractionalRateAndHandlesNoLines(): void {
		self::assertSame(
			['rate' => 5.5, 'net' => 200.0, 'vat' => 11.0, 'gross' => 211.0],
			$this->service->vatTotals([['total' => 200, 'vatRate' => 5.5]])['rates'][0]
		);
		self::assertSame(
			['rates' => [], 'totalExclVat' => 0.0, 'totalVat' => 0.0, 'totalInclVat' => 0.0],
			$this->service->vatTotals([])
		);
	}//end testKeepsAFractionalRateAndHandlesNoLines()

	/**
	 * Each send takes the next number; a new year starts again at 0001.
	 *
	 * @return void
	 */
	public function testNumbersPerYearAndRollsOver(): void {
		$year = (int) date('Y');
		$this->ints = ['quote_number_counter_year' => ($year - 1)];
		$this->sequence->counter = 41;

		self::assertSame(sprintf('OFF-%04d-0001', $year), $this->service->nextNumber());
		self::assertSame(sprintf('OFF-%04d-0002', $year), $this->service->nextNumber());
		self::assertSame($year, $this->ints['quote_number_counter_year']);
	}//end testNumbersPerYearAndRollsOver()

	/**
	 * The contact gets the quote when it has an email address.
	 *
	 * @return void
	 */
	public function testSendsToTheContactFirst(): void {
		self::assertSame(
			['email' => 'kees@bakkerij.example', 'name' => 'Kees Bakker', 'source' => 'contact'],
			$this->service->recipient(
				['firstName' => 'Kees', 'lastName' => 'Bakker', 'email' => ' kees@bakkerij.example '],
				['name' => 'Bakkerij De Graaf', 'email' => 'info@bakkerij.example']
			)
		);
	}//end testSendsToTheContactFirst()

	/**
	 * Without a usable contact email the client gets it.
	 *
	 * @return void
	 */
	public function testFallsBackToTheClient(): void {
		self::assertSame(
			['email' => 'info@bakkerij.example', 'name' => 'Bakkerij De Graaf', 'source' => 'client'],
			$this->service->recipient(['name' => 'Kees', 'email' => 'not an address'], ['name' => 'Bakkerij De Graaf', 'email' => 'info@bakkerij.example'])
		);
		self::assertSame('client', $this->service->recipient(null, ['email' => 'info@bakkerij.example'])['source']);
	}//end testFallsBackToTheClient()

	/**
	 * Without any email address there is no recipient, and nothing can be sent.
	 *
	 * @return void
	 */
	public function testHasNoRecipientWithoutAnEmailAddress(): void {
		self::assertNull($this->service->recipient(null, ['name' => 'Bakkerij De Graaf', 'email' => '']));
		self::assertNull($this->service->recipient(null, null));
	}//end testHasNoRecipientWithoutAnEmailAddress()
}//end class

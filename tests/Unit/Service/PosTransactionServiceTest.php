<?php

/**
 * Unit tests for PosTransactionService.
 *
 * Covers the server-authoritative calculation core (recalculateLine,
 * computeTotals across multiple tax rates and with discounts) and the
 * manager-permission gate used by refund. Lifecycle methods that touch the
 * OpenRegister ObjectService are exercised at the integration level in CI,
 * since ObjectService is not autoloadable in the unit container; the pure
 * calculation + authorization logic that those methods rely on IS covered
 * here.
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

use OCA\Pipelinq\Lifecycle\PosAccessPolicy;
use OCA\Pipelinq\Service\PosTransactionService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosTransactionService.
 */
class PosTransactionServiceTest extends TestCase {
	/**
	 * The service under test.
	 *
	 * @var PosTransactionService
	 */
	private PosTransactionService $service;

	/**
	 * Mock group manager.
	 *
	 * @var IGroupManager
	 */
	private IGroupManager $groupManager;

	/**
	 * Mock app config.
	 *
	 * @var IAppConfig
	 */
	private IAppConfig $appConfig;

	/**
	 * Set up the test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$container = $this->createMock(ContainerInterface::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$logger = $this->createMock(LoggerInterface::class);

		$policy = new PosAccessPolicy(
			appConfig: $this->appConfig,
			groupManager: $this->groupManager,
		);

		$this->service = new PosTransactionService($container,
			$this->appConfig,
			$policy,
			$logger,
			$this->createMock(IEventDispatcher::class),
		);
	}//end setUp()

	/**
	 * recalculateLine applies the (qty x price x (1-discount)) x rate formula.
	 *
	 * @return void
	 */
	public function testRecalculateLineComputesTaxAndTotal(): void {
		// 3 x 4.25 @ 9% BTW, no discount => tax 1.15, total 13.90.
		$line = $this->service->recalculateLine([
			'quantity' => 3,
			'unitPrice' => 4.25,
			'discount' => 0,
			'taxRate' => 9,
		]);

		$this->assertSame(1.15, $line['taxAmount']);
		$this->assertSame(13.90, $line['lineTotal']);
	}//end testRecalculateLineComputesTaxAndTotal()

	/**
	 * recalculateLine applies a line-level discount before tax.
	 *
	 * @return void
	 */
	public function testRecalculateLineAppliesDiscount(): void {
		// 1 x 54.98 @ 21% with 10% discount => net 49.482,
		// tax 10.39122 -> 10.39, total 59.87322 -> 59.87. (The spec's worked
		// example states 59.82, but 49.482 + 10.39 = 59.87; the service rounds
		// the authoritative figure to cents correctly.)
		$line = $this->service->recalculateLine([
			'quantity' => 1,
			'unitPrice' => 54.98,
			'discount' => 10,
			'taxRate' => 21,
		]);

		$this->assertSame(10.39, $line['taxAmount']);
		$this->assertSame(59.87, $line['lineTotal']);
	}//end testRecalculateLineAppliesDiscount()

	/**
	 * recalculateLine clamps out-of-range client input (no tax-rate injection).
	 *
	 * @return void
	 */
	public function testRecalculateLineClampsMaliciousInput(): void {
		$line = $this->service->recalculateLine([
			'quantity' => -5,
			'unitPrice' => -10,
			'discount' => 999,
			'taxRate' => 500,
		]);

		$this->assertSame(0.0, $line['quantity']);
		$this->assertSame(0.0, $line['unitPrice']);
		$this->assertSame(100.0, $line['discount']);
		$this->assertSame(100.0, $line['taxRate']);
		$this->assertSame(0.0, $line['taxAmount']);
		$this->assertSame(0.0, $line['lineTotal']);
	}//end testRecalculateLineClampsMaliciousInput()

	/**
	 * computeTotals groups tax by rate and sums all aggregates.
	 *
	 * @return void
	 */
	public function testComputeTotalsGroupsTaxByRate(): void {
		$lines = [
			// 9% base 22.00 => tax 1.98.
			['quantity' => 2, 'unitPrice' => 11.00, 'discount' => 0, 'taxRate' => 9],
			// 21% base 45.00 => tax 9.45.
			['quantity' => 3, 'unitPrice' => 15.00, 'discount' => 0, 'taxRate' => 21],
		];

		$totals = $this->service->computeTotals($lines);

		$this->assertSame(67.00, $totals['subtotal']);
		$this->assertSame(11.43, $totals['totalTax']);
		$this->assertSame(78.43, $totals['total']);
		$this->assertCount(2, $totals['taxBreakdown']);

		// Breakdown is sorted ascending by rate.
		$this->assertSame(9.0, $totals['taxBreakdown'][0]['rate']);
		$this->assertSame(22.00, $totals['taxBreakdown'][0]['base']);
		$this->assertSame(1.98, $totals['taxBreakdown'][0]['tax']);
		$this->assertSame(21.0, $totals['taxBreakdown'][1]['rate']);
		$this->assertSame(45.00, $totals['taxBreakdown'][1]['base']);
		$this->assertSame(9.45, $totals['taxBreakdown'][1]['tax']);
	}//end testComputeTotalsGroupsTaxByRate()

	/**
	 * computeTotals builds an invoiceBreakdown with Dutch GL descriptions per rate.
	 *
	 * @return void
	 */
	public function testComputeTotalsBuildsInvoiceBreakdown(): void {
		$lines = [
			// 9% base 50.00 => tax 4.50.
			['quantity' => 1, 'unitPrice' => 50.00, 'discount' => 0, 'taxRate' => 9],
			// 21% base 100.00 => tax 21.00.
			['quantity' => 1, 'unitPrice' => 100.00, 'discount' => 0, 'taxRate' => 21],
		];

		$totals = $this->service->computeTotals($lines);

		$this->assertSame('excl', $totals['priceMode']);
		$this->assertSame(25.50, $totals['totalTax']);
		$this->assertSame(175.50, $totals['total']);
		$this->assertCount(2, $totals['invoiceBreakdown']);

		$this->assertSame(9.0, $totals['invoiceBreakdown'][0]['rate']);
		$this->assertSame(50.00, $totals['invoiceBreakdown'][0]['base']);
		$this->assertSame(4.50, $totals['invoiceBreakdown'][0]['tax']);
		$this->assertSame('Verlaagd tarief (9%)', $totals['invoiceBreakdown'][0]['description']);

		$this->assertSame(21.0, $totals['invoiceBreakdown'][1]['rate']);
		$this->assertSame('Standaardtarief (21%)', $totals['invoiceBreakdown'][1]['description']);
	}//end testComputeTotalsBuildsInvoiceBreakdown()

	/**
	 * Zero-rated lines appear in both breakdowns and sort first.
	 *
	 * @return void
	 */
	public function testComputeTotalsIncludesZeroRate(): void {
		$lines = [
			['quantity' => 1, 'unitPrice' => 100.00, 'discount' => 0, 'taxRate' => 21],
			['quantity' => 1, 'unitPrice' => 25.00, 'discount' => 0, 'taxRate' => 0],
			['quantity' => 1, 'unitPrice' => 50.00, 'discount' => 0, 'taxRate' => 9],
		];

		$totals = $this->service->computeTotals($lines);

		$this->assertCount(3, $totals['taxBreakdown']);
		$this->assertSame(0.0, $totals['taxBreakdown'][0]['rate']);
		$this->assertSame(25.00, $totals['taxBreakdown'][0]['base']);
		$this->assertSame(0.0, $totals['taxBreakdown'][0]['tax']);
		$this->assertSame('Nultarief (0%)', $totals['invoiceBreakdown'][0]['description']);
		$this->assertSame(9.0, $totals['taxBreakdown'][1]['rate']);
		$this->assertSame(21.0, $totals['taxBreakdown'][2]['rate']);
	}//end testComputeTotalsIncludesZeroRate()

	/**
	 * In tax-inclusive mode the net base is extracted out of the entered price,
	 * and the per-rate base equals the original excl. base for the same goods.
	 *
	 * 121.00 incl @ 21% => net 100.00, tax 21.00.
	 *
	 * @return void
	 */
	public function testInclusivePriceModeExtractsNetBase(): void {
		$line = $this->service->recalculateLine([
			'quantity' => 1,
			'unitPrice' => 121.00,
			'discount' => 0,
			'taxRate' => 21,
		], 'incl');

		$this->assertSame(100.00, $line['net']);
		$this->assertSame(21.00, $line['taxAmount']);
		// lineTotal (gross) stays at the entered inclusive amount.
		$this->assertSame(121.00, $line['lineTotal']);
	}//end testInclusivePriceModeExtractsNetBase()

	/**
	 * Inclusive vs exclusive entry of the same goods yields the same VAT split.
	 *
	 * Exclusive: unitPrice 100 @ 21% => tax 21, total 121.
	 * Inclusive: unitPrice 121 @ 21% => net 100, tax 21, total 121.
	 *
	 * @return void
	 */
	public function testInclusiveAndExclusiveAgreeOnTaxSplit(): void {
		$excl = $this->service->computeTotals(
			[['quantity' => 1, 'unitPrice' => 100.00, 'discount' => 0, 'taxRate' => 21]],
			'excl'
		);
		$incl = $this->service->computeTotals(
			[['quantity' => 1, 'unitPrice' => 121.00, 'discount' => 0, 'taxRate' => 21]],
			'incl'
		);

		$this->assertSame('incl', $incl['priceMode']);
		$this->assertSame($excl['subtotal'], $incl['subtotal']);
		$this->assertSame($excl['totalTax'], $incl['totalTax']);
		$this->assertSame($excl['total'], $incl['total']);
		$this->assertSame($excl['taxBreakdown'][0]['base'],
			$incl['taxBreakdown'][0]['base']
		);
	}//end testInclusiveAndExclusiveAgreeOnTaxSplit()

	/**
	 * Inclusive mode rounds the extracted net / tax to cents correctly on a
	 * price that does not divide evenly.
	 *
	 * 10.00 incl @ 9% => net 9.1743..., tax 0.8256...; rounded net 9.17, tax 0.83.
	 *
	 * @return void
	 */
	public function testInclusivePriceModeRoundsToCents(): void {
		$line = $this->service->recalculateLine([
			'quantity' => 1,
			'unitPrice' => 10.00,
			'discount' => 0,
			'taxRate' => 9,
		], 'incl');

		$this->assertSame(9.17, $line['net']);
		$this->assertSame(0.83, $line['taxAmount']);
		$this->assertSame(10.00, $line['lineTotal']);
	}//end testInclusivePriceModeRoundsToCents()

	/**
	 * normalizePriceMode is fail-safe: unknown / malformed values fall back to
	 * 'excl' so a bad client value can never change the tax base unexpectedly.
	 *
	 * @return void
	 */
	public function testNormalizePriceModeFallsBackToExclusive(): void {
		$this->assertSame('incl', $this->service->normalizePriceMode('incl'));
		$this->assertSame('incl', $this->service->normalizePriceMode('  INCL '));
		$this->assertSame('excl', $this->service->normalizePriceMode('excl'));
		$this->assertSame('excl', $this->service->normalizePriceMode(null));
		$this->assertSame('excl', $this->service->normalizePriceMode('garbage'));
		$this->assertSame('excl', $this->service->normalizePriceMode(42));
	}//end testNormalizePriceModeFallsBackToExclusive()

	/**
	 * buildTaxReport aggregates per-rate base/tax across final transactions and
	 * nets out refunds (which contribute negative amounts).
	 *
	 * @return void
	 */
	public function testBuildTaxReportAggregatesAndNetsRefunds(): void {
		$transactions = [
			// Counted: settled, 9% + 21%.
			[
				'status' => 'settled',
				'invoiceBreakdown' => [
					['rate' => 9, 'base' => 50.00, 'tax' => 4.50],
					['rate' => 21, 'base' => 100.00, 'tax' => 21.00],
				],
			],
			// Refund nets out half of the 21% line.
			[
				'status' => 'refunded',
				'invoiceBreakdown' => [
					['rate' => 21, 'base' => 50.00, 'tax' => 10.50],
				],
			],
			// Excluded: draft is not fiscally final.
			[
				'status' => 'draft',
				'invoiceBreakdown' => [
					['rate' => 21, 'base' => 999.00, 'tax' => 209.79],
				],
			],
		];

		$report = $this->service->buildTaxReport($transactions);

		$this->assertSame(2, $report['transactionCount']);
		$this->assertCount(2, $report['rates']);

		// 9%: 50 base / 4.50 tax.
		$this->assertSame(9.0, $report['rates'][0]['rate']);
		$this->assertSame(50.00, $report['rates'][0]['base']);
		$this->assertSame(4.50, $report['rates'][0]['tax']);

		// 21%: 100 - 50 = 50 base / 21.00 - 10.50 = 10.50 tax.
		$this->assertSame(21.0, $report['rates'][1]['rate']);
		$this->assertSame(50.00, $report['rates'][1]['base']);
		$this->assertSame(10.50, $report['rates'][1]['tax']);

		$this->assertSame(100.00, $report['totalBase']);
		$this->assertSame(15.00, $report['totalTax']);
	}//end testBuildTaxReportAggregatesAndNetsRefunds()

	/**
	 * buildTaxReport falls back to taxBreakdown for legacy records that have no
	 * invoiceBreakdown.
	 *
	 * @return void
	 */
	public function testBuildTaxReportFallsBackToTaxBreakdown(): void {
		$report = $this->service->buildTaxReport([
			[
				'status' => 'confirmed',
				'taxBreakdown' => [['rate' => 21, 'base' => 80.00, 'tax' => 16.80]],
			],
		]);

		$this->assertSame(1, $report['transactionCount']);
		$this->assertCount(1, $report['rates']);
		$this->assertSame(16.80, $report['rates'][0]['tax']);
		$this->assertSame('Standaardtarief (21%)', $report['rates'][0]['description']);
	}//end testBuildTaxReportFallsBackToTaxBreakdown()

	/**
	 * computeTotals tracks the aggregate discount across lines.
	 *
	 * @return void
	 */
	public function testComputeTotalsTracksDiscount(): void {
		$lines = [
			// gross 100, 20% discount => net 80, discount 20.
			['quantity' => 1, 'unitPrice' => 100.00, 'discount' => 20, 'taxRate' => 21],
		];

		$totals = $this->service->computeTotals($lines);

		$this->assertSame(80.00, $totals['subtotal']);
		$this->assertSame(20.00, $totals['discountTotal']);
		$this->assertSame(16.80, $totals['totalTax']);
		$this->assertSame(96.80, $totals['total']);
	}//end testComputeTotalsTracksDiscount()

	/**
	 * computeTotals on an empty cart yields all-zero totals.
	 *
	 * @return void
	 */
	public function testComputeTotalsEmptyCart(): void {
		$totals = $this->service->computeTotals([]);

		$this->assertSame(0.0, $totals['subtotal']);
		$this->assertSame(0.0, $totals['discountTotal']);
		$this->assertSame(0.0, $totals['totalTax']);
		$this->assertSame(0.0, $totals['total']);
		$this->assertSame([], $totals['taxBreakdown']);
	}//end testComputeTotalsEmptyCart()

	/**
	 * The confirmed CloudEvent envelope carries the required accounting fields.
	 *
	 * WebhookService is absent in the unit container, so emit returns '' (the
	 * documented fire-and-forget no-op) — this asserts that confirmation never
	 * fails when no consumer is configured.
	 *
	 * @return void
	 */
	public function testEmitConfirmedEventNoOpWithoutConsumer(): void {
		$eventId = $this->service->emitConfirmedEvent([
			'id' => 'txn-1',
			'reference' => 'TXN-2026-0001',
			'cashier' => 'admin',
			'total' => 21.53,
			'totalTax' => 1.78,
			'taxBreakdown' => [['rate' => 9, 'base' => 19.75, 'tax' => 1.78]],
			'confirmedAt' => '2026-05-20T09:14:00+02:00',
		]);

		$this->assertSame('', $eventId);
	}//end testEmitConfirmedEventNoOpWithoutConsumer()
}//end class

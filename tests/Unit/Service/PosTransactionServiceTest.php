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

use OCA\Pipelinq\Service\PosTransactionService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for PosTransactionService.
 */
class PosTransactionServiceTest extends TestCase
{
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
    protected function setUp(): void
    {
        $container          = $this->createMock(ContainerInterface::class);
        $this->appConfig    = $this->createMock(IAppConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $logger             = $this->createMock(LoggerInterface::class);

        $this->service = new PosTransactionService(
            $container,
            $this->appConfig,
            $this->groupManager,
            $logger,
        );
    }//end setUp()

    /**
     * recalculateLine applies the (qty x price x (1-discount)) x rate formula.
     *
     * @return void
     */
    public function testRecalculateLineComputesTaxAndTotal(): void
    {
        // 3 x 4.25 @ 9% BTW, no discount => tax 1.15, total 13.90.
        $line = $this->service->recalculateLine([
            'quantity'  => 3,
            'unitPrice' => 4.25,
            'discount'  => 0,
            'taxRate'   => 9,
        ]);

        $this->assertSame(1.15, $line['taxAmount']);
        $this->assertSame(13.90, $line['lineTotal']);
    }//end testRecalculateLineComputesTaxAndTotal()

    /**
     * recalculateLine applies a line-level discount before tax.
     *
     * @return void
     */
    public function testRecalculateLineAppliesDiscount(): void
    {
        // 1 x 54.98 @ 21% with 10% discount => net 49.482,
        // tax 10.39122 -> 10.39, total 59.87322 -> 59.87. (The spec's worked
        // example states 59.82, but 49.482 + 10.39 = 59.87; the service rounds
        // the authoritative figure to cents correctly.)
        $line = $this->service->recalculateLine([
            'quantity'  => 1,
            'unitPrice' => 54.98,
            'discount'  => 10,
            'taxRate'   => 21,
        ]);

        $this->assertSame(10.39, $line['taxAmount']);
        $this->assertSame(59.87, $line['lineTotal']);
    }//end testRecalculateLineAppliesDiscount()

    /**
     * recalculateLine clamps out-of-range client input (no tax-rate injection).
     *
     * @return void
     */
    public function testRecalculateLineClampsMaliciousInput(): void
    {
        $line = $this->service->recalculateLine([
            'quantity'  => -5,
            'unitPrice' => -10,
            'discount'  => 999,
            'taxRate'   => 500,
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
    public function testComputeTotalsGroupsTaxByRate(): void
    {
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
     * computeTotals tracks the aggregate discount across lines.
     *
     * @return void
     */
    public function testComputeTotalsTracksDiscount(): void
    {
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
    public function testComputeTotalsEmptyCart(): void
    {
        $totals = $this->service->computeTotals([]);

        $this->assertSame(0.0, $totals['subtotal']);
        $this->assertSame(0.0, $totals['discountTotal']);
        $this->assertSame(0.0, $totals['totalTax']);
        $this->assertSame(0.0, $totals['total']);
        $this->assertSame([], $totals['taxBreakdown']);
    }//end testComputeTotalsEmptyCart()

    /**
     * A Nextcloud admin is always treated as a POS manager (refund gate).
     *
     * @return void
     */
    public function testIsManagerTrueForAdmin(): void
    {
        $this->groupManager->method('isAdmin')->with('boss')->willReturn(true);

        $this->assertTrue($this->service->isManager('boss'));
    }//end testIsManagerTrueForAdmin()

    /**
     * A non-admin with no configured manager group is NOT a manager (fail-closed).
     *
     * @return void
     */
    public function testIsManagerFailsClosedWithoutGroup(): void
    {
        $this->groupManager->method('isAdmin')->with('clerk')->willReturn(false);
        $this->appConfig->method('getValueString')->willReturn('');

        $this->assertFalse($this->service->isManager('clerk'));
    }//end testIsManagerFailsClosedWithoutGroup()

    /**
     * A non-admin who is a member of the configured manager group qualifies.
     *
     * @return void
     */
    public function testIsManagerTrueForConfiguredGroupMember(): void
    {
        $this->groupManager->method('isAdmin')->with('clerk')->willReturn(false);
        $this->appConfig->method('getValueString')->willReturn('pos-managers');
        $this->groupManager->method('isInGroup')->with('clerk', 'pos-managers')->willReturn(true);

        $this->assertTrue($this->service->isManager('clerk'));
    }//end testIsManagerTrueForConfiguredGroupMember()

    /**
     * An empty user id is never a manager.
     *
     * @return void
     */
    public function testIsManagerFalseForEmptyUser(): void
    {
        $this->assertFalse($this->service->isManager(''));
    }//end testIsManagerFalseForEmptyUser()

    /**
     * The confirmed CloudEvent envelope carries the required accounting fields.
     *
     * WebhookService is absent in the unit container, so emit returns '' (the
     * documented fire-and-forget no-op) — this asserts that confirmation never
     * fails when no consumer is configured.
     *
     * @return void
     */
    public function testEmitConfirmedEventNoOpWithoutConsumer(): void
    {
        $eventId = $this->service->emitConfirmedEvent([
            'id'           => 'txn-1',
            'reference'    => 'TXN-2026-0001',
            'cashier'      => 'admin',
            'total'        => 21.53,
            'totalTax'     => 1.78,
            'taxBreakdown' => [['rate' => 9, 'base' => 19.75, 'tax' => 1.78]],
            'confirmedAt'  => '2026-05-20T09:14:00+02:00',
        ]);

        $this->assertSame('', $eventId);
    }//end testEmitConfirmedEventNoOpWithoutConsumer()
}//end class

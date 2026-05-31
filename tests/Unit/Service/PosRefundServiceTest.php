<?php

/**
 * Unit tests for PosRefundService.
 *
 * Covers the server-authoritative proportional refund calculation
 * (recalculateLine), the cumulative over-refund cap, the manager-permission gate
 * (fail closed), the confirm / reject lifecycle and the reversal + stock-movement
 * CloudEvent shapes. The OpenRegister ObjectService is faked through the DI
 * container so the full confirm/reject paths can be exercised without a live OR.
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

use OCA\Pipelinq\Service\PosRefundService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A fake OpenRegister ObjectService capturing saves and answering finds from
 * an in-memory store keyed by schema id + object id.
 */
class FakeObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /** @var array<int, array<string, mixed>> */
    public array $saves = [];

    /**
     * @param array<string, mixed> $object
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = $config['filters'] ?? [];
        $schema  = (string) ($filters['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        return array_values(array_filter($rows, function (array $row) use ($filters): bool {
            foreach (['refund', 'transaction', 'originalTransaction', 'status'] as $key) {
                if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array
    {
        $object['id'] = $uuid;
        $this->store[$schema][$uuid] = $object;
        $this->saves[] = ['schema' => $schema, 'uuid' => $uuid, 'object' => $object];

        return $object;
    }
}

/**
 * A fake WebhookService capturing dispatched CloudEvents.
 */
class FakeWebhookService
{
    /** @var array<int, array{eventName: string, payload: array<string, mixed>}> */
    public array $events = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchEvent(object $_event, string $eventName, array $payload): void
    {
        $this->events[] = ['eventName' => $eventName, 'payload' => $payload];
    }
}

/**
 * Tests for PosRefundService.
 */
class PosRefundServiceTest extends TestCase
{
    private PosRefundService $service;

    private IGroupManager $groupManager;

    private IAppConfig $appConfig;

    private FakeObjectService $objects;

    private FakeWebhookService $webhooks;

    /**
     * Build the service with fakes wired into the container.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects  = new FakeObjectService();
        $this->webhooks = new FakeWebhookService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) {
            if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                return $this->objects;
            }
            if ($id === 'OCA\OpenRegister\Service\WebhookService') {
                return $this->webhooks;
            }
            throw new \RuntimeException('unknown service '.$id);
        });

        $this->appConfig = $this->createMock(IAppConfig::class);
        // Resolve register + every *_schema key to a stable id (the key itself).
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default = '') {
                if ($key === 'register') {
                    return 'reg';
                }
                if ($key === 'pos_manager_group') {
                    return $default;
                }
                return $key;
            }
        );

        $this->groupManager = $this->createMock(IGroupManager::class);

        $this->service = new PosRefundService(
            $container,
            $this->appConfig,
            $this->groupManager,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Seed a transaction, an original line, a refund and a refund line.
     *
     * @param array<string, mixed> $refundOverrides     Overrides for the refund.
     * @param array<string, mixed> $refundLineOverrides Overrides for the refund line.
     * @param array<string, mixed> $originalOverrides   Overrides for the original line.
     * @param array<string, mixed> $txnOverrides        Overrides for the transaction.
     *
     * @return void
     */
    private function seed(
        array $refundOverrides = [],
        array $refundLineOverrides = [],
        array $originalOverrides = [],
        array $txnOverrides = []
    ): void {
        $this->objects->store['posTransaction_schema']['txn-1'] = array_merge([
            'id'     => 'txn-1',
            'total'  => 121.00,
            'status' => 'settled',
        ], $txnOverrides);

        $this->objects->store['posTransactionLine_schema']['line-1'] = array_merge([
            'id'        => 'line-1',
            'quantity'  => 2,
            'taxAmount' => 21.00,
            'lineTotal' => 121.00,
            'product'   => 'prod-1',
        ], $originalOverrides);

        $this->objects->store['posRefund_schema']['ref-1'] = array_merge([
            'id'                  => 'ref-1',
            'reference'           => 'RET-1',
            'originalTransaction' => 'txn-1',
            'cashier'             => 'admin',
            'status'              => 'pending',
            'refundReason'        => 'reason-damaged',
            'paymentMethod'       => 'card_visa',
            'paymentReference'    => 'VISA-1',
        ], $refundOverrides);

        $this->objects->store['posRefundLine_schema']['rl-1'] = array_merge([
            'id'               => 'rl-1',
            'refund'           => 'ref-1',
            'originalLine'     => 'line-1',
            'returnedQuantity' => 1,
            'returnReason'     => 'reason-damaged',
            'restock'          => true,
        ], $refundLineOverrides);
    }//end seed()

    /**
     * recalculateLine computes the proportional tax and total for a partial qty.
     *
     * Original: qty 2, tax 21.00, total 121.00. Returning 1 => half.
     *
     * @return void
     */
    public function testRecalculateLinePartialProportion(): void
    {
        $computed = $this->service->recalculateLine([
            'quantity'  => 2,
            'taxAmount' => 21.00,
            'lineTotal' => 121.00,
        ], 1.0);

        $this->assertSame(0.5, $computed['ratio']);
        $this->assertSame(10.50, $computed['taxAmount']);
        $this->assertSame(60.50, $computed['lineTotal']);
    }//end testRecalculateLinePartialProportion()

    /**
     * recalculateLine clamps a returnedQty above the original quantity to the
     * full line so an over-quantity return can never inflate the refund.
     *
     * @return void
     */
    public function testRecalculateLineClampsExcessQuantity(): void
    {
        $computed = $this->service->recalculateLine([
            'quantity'  => 2,
            'taxAmount' => 21.00,
            'lineTotal' => 121.00,
        ], 99.0);

        $this->assertSame(1.0, $computed['ratio']);
        $this->assertSame(21.00, $computed['taxAmount']);
        $this->assertSame(121.00, $computed['lineTotal']);
    }//end testRecalculateLineClampsExcessQuantity()

    /**
     * recalculateTotals aggregates the refund lines into refundAmount + totalTax
     * computed from the persisted original-line tax, ignoring client values.
     *
     * @return void
     */
    public function testRecalculateTotalsAggregatesServerSide(): void
    {
        // Client lies that taxAmount is 999 — it must be overwritten.
        $this->seed([], ['taxAmount' => 999, 'lineTotal' => 999]);

        $refund = $this->service->recalculateTotals('ref-1');

        // Half of (tax 21, total 121) => tax 10.50, gross 60.50, net 50.00.
        $this->assertSame(50.00, $refund['refundAmount']);
        $this->assertSame(10.50, $refund['totalTax']);
    }//end testRecalculateTotalsAggregatesServerSide()

    /**
     * A full-quantity refund yields the full line tax and total.
     *
     * @return void
     */
    public function testConfirmFullRefund(): void
    {
        $this->seed([], ['returnedQuantity' => 2]);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $refund = $this->service->confirmRefund('ref-1', 'boss');

        $this->assertSame('completed', $refund['status']);
        $this->assertSame(100.00, $refund['refundAmount']);
        $this->assertSame(21.00, $refund['totalTax']);
        $this->assertNotEmpty($refund['confirmedAt']);
    }//end testConfirmFullRefund()

    /**
     * Confirm rejects when the refund exceeds the original transaction total
     * (over-refund cap, REQ-REF-014).
     *
     * @return void
     */
    public function testConfirmRejectsOverRefund(): void
    {
        // Original transaction total only 10; line total 121 => exceeds.
        $this->seed([], ['returnedQuantity' => 2], [], ['total' => 10.00]);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->expectException(OCSBadRequestException::class);
        $this->expectExceptionMessageMatches('/overschrijdt originele totaal/');

        $this->service->confirmRefund('ref-1', 'boss');
    }//end testConfirmRejectsOverRefund()

    /**
     * The cumulative cap counts already-completed refunds for the same
     * transaction: a second partial refund that tips over the total is rejected.
     *
     * @return void
     */
    public function testConfirmRejectsCumulativeOverRefund(): void
    {
        $this->seed([], ['returnedQuantity' => 1]);
        $this->groupManager->method('isAdmin')->willReturn(true);

        // A prior completed refund for txn-1 already used 70.00 of the 121 total.
        $this->objects->store['posRefund_schema']['ref-prior'] = [
            'id'                  => 'ref-prior',
            'originalTransaction' => 'txn-1',
            'status'              => 'completed',
            'refundAmount'        => 60.00,
            'totalTax'            => 10.00,
        ];

        // This refund is half the line = gross 60.50; 70 + 60.50 = 130.50 > 121.
        $this->expectException(OCSBadRequestException::class);
        $this->service->confirmRefund('ref-1', 'boss');
    }//end testConfirmRejectsCumulativeOverRefund()

    /**
     * A second partial refund within the remaining cap is accepted.
     *
     * @return void
     */
    public function testConfirmAcceptsCumulativeWithinCap(): void
    {
        $this->seed([], ['returnedQuantity' => 1]);
        $this->groupManager->method('isAdmin')->willReturn(true);

        // Prior completed refund used only 30.00; remaining is 91 > 60.50 gross.
        $this->objects->store['posRefund_schema']['ref-prior'] = [
            'id'                  => 'ref-prior',
            'originalTransaction' => 'txn-1',
            'status'              => 'completed',
            'refundAmount'        => 25.00,
            'totalTax'            => 5.00,
        ];

        $refund = $this->service->confirmRefund('ref-1', 'boss');
        $this->assertSame('completed', $refund['status']);
    }//end testConfirmAcceptsCumulativeWithinCap()

    /**
     * Confirm is manager-gated and fails closed for a non-manager.
     *
     * @return void
     */
    public function testConfirmManagerGateFailsClosed(): void
    {
        $this->seed();
        $this->groupManager->method('isAdmin')->willReturn(false);
        // No manager group configured (appConfig returns the default '').

        $this->expectException(OCSForbiddenException::class);
        $this->service->confirmRefund('ref-1', 'clerk');
    }//end testConfirmManagerGateFailsClosed()

    /**
     * Confirm rejects when the original transaction does not exist (orphan).
     *
     * @return void
     */
    public function testConfirmRejectsOrphanTransaction(): void
    {
        $this->seed(['originalTransaction' => 'missing-txn']);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->expectException(\OCP\AppFramework\OCS\OCSNotFoundException::class);
        $this->service->confirmRefund('ref-1', 'boss');
    }//end testConfirmRejectsOrphanTransaction()

    /**
     * Confirm rejects a non-pending refund (immutability of completed refunds).
     *
     * @return void
     */
    public function testConfirmRejectsNonPending(): void
    {
        $this->seed(['status' => 'completed']);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->expectException(OCSBadRequestException::class);
        $this->service->confirmRefund('ref-1', 'boss');
    }//end testConfirmRejectsNonPending()

    /**
     * Confirm emits a reversal CloudEvent with negative amounts and the
     * accounting fields from the design envelope.
     *
     * @return void
     */
    public function testConfirmEmitsReversalEvent(): void
    {
        $this->seed([], ['returnedQuantity' => 2]);
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->service->confirmRefund('ref-1', 'boss');

        $reversal = array_values(array_filter(
            $this->webhooks->events,
            fn (array $e): bool => $e['eventName'] === PosRefundService::EVENT_REFUND_COMPLETED
        ));
        $this->assertCount(1, $reversal);

        $data = $reversal[0]['payload']['data'];
        $this->assertSame('1.0', $reversal[0]['payload']['specversion']);
        $this->assertSame('ref-1', $data['refundId']);
        $this->assertSame('txn-1', $data['originalTransactionId']);
        $this->assertSame('RET-1', $data['reference']);
        $this->assertSame(100.00, $data['refundAmount']);
        $this->assertSame(21.00, $data['totalTax']);
        $this->assertSame(-100.00, $data['reversalAmount']);
        $this->assertSame(-21.00, $data['reversalTax']);
        $this->assertSame('card_visa', $data['paymentMethod']);
        $this->assertSame('VISA-1', $data['paymentReference']);
    }//end testConfirmEmitsReversalEvent()

    /**
     * Only restock=true lines emit a stock-movement event, with a positive
     * quantity and the design envelope fields.
     *
     * @return void
     */
    public function testConfirmEmitsStockMovementOnlyForRestockedLines(): void
    {
        $this->seed([], ['returnedQuantity' => 2, 'restock' => true]);
        // Add a second, non-restock line on the same refund.
        $this->objects->store['posTransactionLine_schema']['line-2'] = [
            'id'        => 'line-2',
            'quantity'  => 1,
            'taxAmount' => 0.0,
            'lineTotal' => 0.0,
            'product'   => 'prod-2',
        ];
        $this->objects->store['posRefundLine_schema']['rl-2'] = [
            'id'               => 'rl-2',
            'refund'           => 'ref-1',
            'originalLine'     => 'line-2',
            'returnedQuantity' => 1,
            'restock'          => false,
        ];

        $this->groupManager->method('isAdmin')->willReturn(true);
        $this->service->confirmRefund('ref-1', 'boss');

        $movements = array_values(array_filter(
            $this->webhooks->events,
            fn (array $e): bool => $e['eventName'] === PosRefundService::EVENT_STOCK_MOVEMENT
        ));
        $this->assertCount(1, $movements);

        $data = $movements[0]['payload']['data'];
        $this->assertSame('refund_return', $data['transactionType']);
        $this->assertSame('ref-1', $data['refundId']);
        $this->assertSame('prod-1', $data['productId']);
        $this->assertSame(2.0, $data['quantity']);
        $this->assertSame('piece', $data['unit']);
    }//end testConfirmEmitsStockMovementOnlyForRestockedLines()

    /**
     * Reject is manager-gated, requires a reason, and sets the rejected state
     * without emitting any events.
     *
     * @return void
     */
    public function testRejectSetsStateAndEmitsNoEvents(): void
    {
        $this->seed();
        $this->groupManager->method('isAdmin')->willReturn(true);

        $refund = $this->service->rejectRefund('ref-1', 'Retourperiode verstreken', 'boss');

        $this->assertSame('rejected', $refund['status']);
        $this->assertSame('Retourperiode verstreken', $refund['rejectionReason']);
        $this->assertNotEmpty($refund['rejectedAt']);
        $this->assertCount(0, $this->webhooks->events);
    }//end testRejectSetsStateAndEmitsNoEvents()

    /**
     * Reject fails closed for a non-manager.
     *
     * @return void
     */
    public function testRejectManagerGateFailsClosed(): void
    {
        $this->seed();
        $this->groupManager->method('isAdmin')->willReturn(false);

        $this->expectException(OCSForbiddenException::class);
        $this->service->rejectRefund('ref-1', 'reason', 'clerk');
    }//end testRejectManagerGateFailsClosed()

    /**
     * Reject requires a non-empty reason.
     *
     * @return void
     */
    public function testRejectRequiresReason(): void
    {
        $this->seed();
        $this->groupManager->method('isAdmin')->willReturn(true);

        $this->expectException(OCSBadRequestException::class);
        $this->service->rejectRefund('ref-1', '   ', 'boss');
    }//end testRejectRequiresReason()

    /**
     * A Nextcloud admin is always a manager; a non-admin without a configured
     * group is never one (fail closed).
     *
     * @return void
     */
    public function testIsManagerGate(): void
    {
        $this->groupManager->method('isAdmin')->willReturnMap([
            ['boss', true],
            ['clerk', false],
        ]);

        $this->assertTrue($this->service->isManager('boss'));
        $this->assertFalse($this->service->isManager('clerk'));
        $this->assertFalse($this->service->isManager(''));
    }//end testIsManagerGate()
}//end class

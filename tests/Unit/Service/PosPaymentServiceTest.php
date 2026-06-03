<?php

/**
 * Unit tests for PosPaymentService.
 *
 * Covers the server-authoritative multi-tender reconciliation (calculateChange /
 * validateTenderSum: exact cover, under-tender, over-tender with and without a
 * change-allowing cash tender, rounding, multi-tender ordering) and the
 * add/remove tender lifecycle (access/IDOR, settled-transaction refusal, amount
 * floor, required-reference enforcement, server-side glAccount copy). A fake
 * ObjectService provides an in-memory store; a fake WebhookService captures the
 * per-tender GL CloudEvents. No live OpenRegister is required.
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
use OCA\Pipelinq\Service\PosPaymentService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An in-memory ObjectService capturing saves/deletes and answering finds, keyed
 * by schema id + object id. Mirrors the subset of the OR ObjectService API the
 * payment service uses (find / findAll / saveObject / deleteObject).
 */
class FakePaymentObjectService
{
    /** @var array<string, array<string, array<string, mixed>>> */
    public array $store = [];

    /** @var array<int, string> */
    public array $deleted = [];

    /**
     * @return array<string, mixed>|null
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

        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach (['transaction', 'tenderType'] as $key) {
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

        return $object;
    }

    public function deleteObject(string $uuid, string $register, string $schema): bool
    {
        unset($this->store[$schema][$uuid]);
        $this->deleted[] = $uuid;

        return true;
    }
}

/**
 * A fake WebhookService capturing dispatched CloudEvents.
 */
class FakePaymentWebhookService
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
 * Tests for PosPaymentService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The multi-tender concern has many
 *  small, single-purpose behaviours each asserted independently.
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Wires the fakes the payment
 *  service legitimately exercises.
 */
class PosPaymentServiceTest extends TestCase
{
    private PosPaymentService $service;

    private FakePaymentObjectService $objects;

    private FakePaymentWebhookService $webhooks;

    private IGroupManager $groupManager;

    /**
     * The acting cashier uid used throughout (owner of the seeded transaction).
     *
     * @var string
     */
    private string $cashier = 'cashier-1';

    /**
     * Wire the service with fakes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects  = new FakePaymentObjectService();
        $this->webhooks = new FakePaymentWebhookService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default = '') {
                if ($key === 'register') {
                    return 'reg';
                }
                if ($key === 'pos_group') {
                    return 'pos';
                }
                return $key;
            }
        );

        $this->groupManager = $this->createMock(IGroupManager::class);
        // The seeded transaction's own cashier always has access via ownership;
        // nobody is an admin or pos-group member unless a test arranges it.
        $this->groupManager->method('isAdmin')->willReturn(false);
        $this->groupManager->method('isInGroup')->willReturn(false);

        $policy = new PosAccessPolicy(appConfig: $appConfig, groupManager: $this->groupManager);

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

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new PosPaymentService($container, $appConfig, $policy, $logger);
    }//end setUp()

    /**
     * Seed a transaction and the three default tender types.
     *
     * @param array<string, mixed> $txnOverrides Overrides for the transaction.
     *
     * @return void
     */
    private function seed(array $txnOverrides = []): void
    {
        $this->objects->store['posTransaction_schema']['txn-1'] = array_merge([
            'id'        => 'txn-1',
            'reference' => 'TXN-2026-0001',
            'cashier'   => $this->cashier,
            'status'    => 'confirmed',
            'total'     => 97.97,
        ], $txnOverrides);

        $this->objects->store['posTenderType_schema']['type-cash'] = [
            'id'                => 'type-cash',
            'name'              => 'Contant',
            'code'              => 'CASH',
            'glAccount'         => '1100',
            'allowsChange'      => true,
            'requiresReference' => false,
            'isActive'          => true,
            'sortOrder'         => 1,
        ];
        $this->objects->store['posTenderType_schema']['type-card'] = [
            'id'                => 'type-card',
            'name'              => 'Betaalpas',
            'code'              => 'CARD',
            'glAccount'         => '1200',
            'allowsChange'      => false,
            'requiresReference' => true,
            'isActive'          => true,
            'sortOrder'         => 2,
        ];
        $this->objects->store['posTenderType_schema']['type-voucher'] = [
            'id'                => 'type-voucher',
            'name'              => 'Cadeaubon',
            'code'              => 'VOUCHER',
            'glAccount'         => '2100',
            'allowsChange'      => false,
            'requiresReference' => true,
            'isActive'          => false,
            'sortOrder'         => 3,
        ];
    }//end seed()

    /**
     * Directly seed a persisted tender row.
     *
     * @param string               $id        The tender id.
     * @param array<string, mixed> $overrides The tender fields.
     *
     * @return void
     */
    private function seedTender(string $id, array $overrides): void
    {
        $this->objects->store['posTender_schema'][$id] = array_merge(
            ['id' => $id, 'transaction' => 'txn-1', 'sortOrder' => 0],
            $overrides
        );
    }//end seedTender()

    // ---- calculateChange ----------------------------------------------------

    /**
     * calculateChange returns the difference on overpayment.
     *
     * @return void
     */
    public function testCalculateChangeOnOverpayment(): void
    {
        $this->assertSame(22.80, $this->service->calculateChange(50.00, 27.20));
    }//end testCalculateChangeOnOverpayment()

    /**
     * calculateChange returns 0 on exact payment.
     *
     * @return void
     */
    public function testCalculateChangeOnExactPayment(): void
    {
        $this->assertSame(0.0, $this->service->calculateChange(27.20, 27.20));
    }//end testCalculateChangeOnExactPayment()

    /**
     * calculateChange returns 0 on underpayment.
     *
     * @return void
     */
    public function testCalculateChangeOnUnderpayment(): void
    {
        $this->assertSame(0.0, $this->service->calculateChange(20.00, 27.20));
    }//end testCalculateChangeOnUnderpayment()

    /**
     * calculateChange rounds to cents.
     *
     * @return void
     */
    public function testCalculateChangeRoundsToCents(): void
    {
        $this->assertSame(0.01, $this->service->calculateChange(10.005, 9.9999));
    }//end testCalculateChangeRoundsToCents()

    // ---- validateTenderSum --------------------------------------------------

    /**
     * Exact split (cash + card) reconciles with zero variance and no change.
     *
     * @return void
     */
    public function testValidateTenderSumExactSplitReconciles(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00, 'sortOrder' => 1]);
        $this->seedTender('t2', ['tenderType' => 'type-card', 'amount' => 47.97, 'sortOrder' => 2]);

        $result = $this->service->validateTenderSum('txn-1');

        $this->assertSame(97.97, $result['tenderSum']);
        $this->assertSame(97.97, $result['transactionTotal']);
        $this->assertSame(0.0, $result['variance']);
        $this->assertSame(0.0, $result['changeDue']);
        $this->assertTrue($result['reconciled']);
    }//end testValidateTenderSumExactSplitReconciles()

    /**
     * Under-tender does not reconcile and reports a positive variance.
     *
     * @return void
     */
    public function testValidateTenderSumUnderpaymentNotReconciled(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00]);

        $result = $this->service->validateTenderSum('txn-1');

        $this->assertSame(50.00, $result['tenderSum']);
        $this->assertSame(47.97, $result['variance']);
        $this->assertFalse($result['reconciled']);
        $this->assertSame(0.0, $result['changeDue']);
    }//end testValidateTenderSumUnderpaymentNotReconciled()

    /**
     * Over-tender by CARD only (no change-allowing tender) does NOT reconcile.
     *
     * @return void
     */
    public function testValidateTenderSumCardOverpaymentNotReconciled(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-card', 'amount' => 100.00]);

        $result = $this->service->validateTenderSum('txn-1');

        $this->assertSame(100.00, $result['tenderSum']);
        $this->assertSame(-2.03, $result['variance']);
        $this->assertFalse($result['hasChangeTender']);
        $this->assertFalse($result['reconciled']);
    }//end testValidateTenderSumCardOverpaymentNotReconciled()

    /**
     * Over-tender by CASH (change-allowing) reconciles and reports change due.
     *
     * @return void
     */
    public function testValidateTenderSumCashOverpaymentReconcilesWithChange(): void
    {
        $this->seed(['total' => 27.20]);
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00]);

        $result = $this->service->validateTenderSum('txn-1');

        $this->assertSame(50.00, $result['tenderSum']);
        $this->assertSame(27.20, $result['transactionTotal']);
        $this->assertSame(-22.80, $result['variance']);
        $this->assertTrue($result['hasChangeTender']);
        $this->assertTrue($result['reconciled']);
        $this->assertSame(22.80, $result['changeDue']);
    }//end testValidateTenderSumCashOverpaymentReconcilesWithChange()

    /**
     * Split where card exactly covers and cash gives change reconciles: card
     * 20.00 + cash 10.00 on a 27.20 total → 2.80 change from the cash portion.
     *
     * @return void
     */
    public function testValidateTenderSumMixedWithCashChangeReconciles(): void
    {
        $this->seed(['total' => 27.20]);
        $this->seedTender('t1', ['tenderType' => 'type-card', 'amount' => 20.00]);
        $this->seedTender('t2', ['tenderType' => 'type-cash', 'amount' => 10.00]);

        $result = $this->service->validateTenderSum('txn-1');

        $this->assertSame(30.00, $result['tenderSum']);
        $this->assertSame(-2.80, $result['variance']);
        $this->assertTrue($result['reconciled']);
        $this->assertSame(2.80, $result['changeDue']);
    }//end testValidateTenderSumMixedWithCashChangeReconciles()

    /**
     * Penny-level rounding does not block a balancing split (epsilon tolerance).
     *
     * @return void
     */
    public function testValidateTenderSumToleratesPennyRounding(): void
    {
        $this->seed(['total' => 10.00]);
        $this->seedTender('t1', ['tenderType' => 'type-card', 'amount' => 3.33]);
        $this->seedTender('t2', ['tenderType' => 'type-card', 'amount' => 3.33]);
        $this->seedTender('t3', ['tenderType' => 'type-card', 'amount' => 3.34]);

        $result = $this->service->validateTenderSum('txn-1');

        $this->assertSame(10.00, $result['tenderSum']);
        $this->assertTrue($result['reconciled']);
    }//end testValidateTenderSumToleratesPennyRounding()

    // ---- addTender ----------------------------------------------------------

    /**
     * A valid tender is created with the glAccount copied from the type and a
     * client-supplied glAccount ignored.
     *
     * @return void
     */
    public function testAddTenderCopiesGlAccountServerSide(): void
    {
        $this->seed();

        $created = $this->service->addTender(
            'txn-1',
            ['tenderType' => 'type-cash', 'amount' => 50.00, 'glAccount' => '9999-EVIL'],
            $this->cashier
        );

        $this->assertSame('1100', $created['glAccount']);
        $this->assertSame(50.00, $created['amount']);
        $this->assertSame('txn-1', $created['transaction']);
    }//end testAddTenderCopiesGlAccountServerSide()

    /**
     * Amount below the floor is rejected.
     *
     * @return void
     */
    public function testAddTenderRejectsTooSmallAmount(): void
    {
        $this->seed();

        $this->expectException(OCSBadRequestException::class);
        $this->service->addTender('txn-1', ['tenderType' => 'type-cash', 'amount' => 0.0], $this->cashier);
    }//end testAddTenderRejectsTooSmallAmount()

    /**
     * A tender type that requires a reference rejects a missing reference.
     *
     * @return void
     */
    public function testAddTenderRequiresReference(): void
    {
        $this->seed();

        $this->expectException(OCSBadRequestException::class);
        $this->expectExceptionMessage('Referentie is vereist');
        $this->service->addTender('txn-1', ['tenderType' => 'type-card', 'amount' => 10.00], $this->cashier);
    }//end testAddTenderRequiresReference()

    /**
     * A tender referencing an inactive type is rejected.
     *
     * @return void
     */
    public function testAddTenderRejectsInactiveType(): void
    {
        $this->seed();

        $this->expectException(OCSBadRequestException::class);
        $this->service->addTender(
            'txn-1',
            ['tenderType' => 'type-voucher', 'amount' => 10.00, 'reference' => 'V1'],
            $this->cashier
        );
    }//end testAddTenderRejectsInactiveType()

    /**
     * A tender cannot be added to a settled transaction (409 semantics).
     *
     * @return void
     */
    public function testAddTenderRejectedOnSettledTransaction(): void
    {
        $this->seed(['status' => 'settled']);

        $this->expectException(OCSBadRequestException::class);
        $this->expectExceptionMessage('afgeronde transactie');
        $this->service->addTender('txn-1', ['tenderType' => 'type-cash', 'amount' => 10.00], $this->cashier);
    }//end testAddTenderRejectedOnSettledTransaction()

    /**
     * A non-owner with no group/admin access cannot add a tender (IDOR closed).
     *
     * @return void
     */
    public function testAddTenderDeniedForNonOwner(): void
    {
        $this->seed();

        $this->expectException(OCSForbiddenException::class);
        $this->service->addTender('txn-1', ['tenderType' => 'type-cash', 'amount' => 10.00], 'intruder');
    }//end testAddTenderDeniedForNonOwner()

    // ---- removeTender -------------------------------------------------------

    /**
     * A tender is removed from an unsettled transaction.
     *
     * @return void
     */
    public function testRemoveTenderDeletesIt(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00]);

        $this->service->removeTender('txn-1', 't1', $this->cashier);

        $this->assertContains('t1', $this->objects->deleted);
        $this->assertArrayNotHasKey('t1', $this->objects->store['posTender_schema'] ?? []);
    }//end testRemoveTenderDeletesIt()

    /**
     * A tender belonging to another transaction cannot be removed via this one.
     *
     * @return void
     */
    public function testRemoveTenderRejectsForeignTender(): void
    {
        $this->seed();
        $this->seedTender('t1', ['transaction' => 'other-txn', 'tenderType' => 'type-cash', 'amount' => 5.0]);

        $this->expectException(OCSNotFoundException::class);
        $this->service->removeTender('txn-1', 't1', $this->cashier);
    }//end testRemoveTenderRejectsForeignTender()

    /**
     * A tender cannot be removed from a settled transaction.
     *
     * @return void
     */
    public function testRemoveTenderRejectedOnSettledTransaction(): void
    {
        $this->seed(['status' => 'settled']);
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00]);

        $this->expectException(OCSBadRequestException::class);
        $this->service->removeTender('txn-1', 't1', $this->cashier);
    }//end testRemoveTenderRejectedOnSettledTransaction()

    // ---- getters ------------------------------------------------------------

    /**
     * Tenders are returned sorted by sortOrder ascending.
     *
     * @return void
     */
    public function testGetTendersForTransactionIsSorted(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 1.0, 'sortOrder' => 3]);
        $this->seedTender('t2', ['tenderType' => 'type-cash', 'amount' => 2.0, 'sortOrder' => 1]);
        $this->seedTender('t3', ['tenderType' => 'type-cash', 'amount' => 3.0, 'sortOrder' => 2]);

        $tenders = $this->service->getTendersForTransaction('txn-1');
        $order   = array_map(static fn (array $t): string => $t['id'], $tenders);

        $this->assertSame(['t2', 't3', 't1'], $order);
    }//end testGetTendersForTransactionIsSorted()

    /**
     * Only active tender types are returned, sorted by sortOrder.
     *
     * @return void
     */
    public function testGetActiveTenderTypesFiltersAndSorts(): void
    {
        $this->seed();

        $types = $this->service->getActiveTenderTypes();
        $codes = array_map(static fn (array $t): string => $t['code'], $types);

        // VOUCHER is inactive in the seed and excluded; CASH (1) before CARD (2).
        $this->assertSame(['CASH', 'CARD'], $codes);
    }//end testGetActiveTenderTypesFiltersAndSorts()

    /**
     * getTenderTypeByCode resolves an existing type and throws for an unknown one.
     *
     * @return void
     */
    public function testGetTenderTypeByCode(): void
    {
        $this->seed();

        $this->assertSame('type-cash', $this->service->getTenderTypeByCode('CASH')['id']);

        $this->expectException(OCSNotFoundException::class);
        $this->service->getTenderTypeByCode('NOPE');
    }//end testGetTenderTypeByCode()

    /**
     * countTendersForType counts only tenders of the requested type.
     *
     * @return void
     */
    public function testCountTendersForType(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 1.0]);
        $this->seedTender('t2', ['tenderType' => 'type-cash', 'amount' => 2.0]);
        $this->seedTender('t3', ['tenderType' => 'type-card', 'amount' => 3.0]);

        $this->assertSame(2, $this->service->countTendersForType('type-cash'));
        $this->assertSame(1, $this->service->countTendersForType('type-card'));
    }//end testCountTendersForType()

    // ---- GL CloudEvents -----------------------------------------------------

    /**
     * One tender.posted CloudEvent is emitted per tender, with the correct
     * payload shape (code, amount, glAccount, transaction reference).
     *
     * @return void
     */
    public function testEmitTenderPostedEventsPerTender(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00, 'glAccount' => '1100']);
        $this->seedTender('t2', ['tenderType' => 'type-card', 'amount' => 47.97, 'glAccount' => '1200', 'reference' => 'A1']);

        $transaction = $this->objects->store['posTransaction_schema']['txn-1'];
        $emitted     = $this->service->emitTenderPostedEvents($transaction);

        $this->assertSame(2, $emitted);
        $this->assertCount(2, $this->webhooks->events);

        $first = $this->webhooks->events[0];
        $this->assertSame('nl.pipelinq.pos.tender.posted', $first['eventName']);
        $this->assertSame('nl.pipelinq.pos.tender.posted', $first['payload']['type']);
        $this->assertSame('TXN-2026-0001', $first['payload']['data']['transactionReference']);
        $this->assertSame('CASH', $first['payload']['data']['tenderType']);
        $this->assertSame(50.00, $first['payload']['data']['amount']);
        $this->assertSame('1100', $first['payload']['data']['glAccount']);
    }//end testEmitTenderPostedEventsPerTender()

    /**
     * A successful emission flags each tender glPosted=true and increments the
     * attempts counter (so the retry job will skip it).
     *
     * @return void
     */
    public function testEmitTenderPostedFlagsTendersPosted(): void
    {
        $this->seed();
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 50.00, 'glAccount' => '1100']);

        $transaction = $this->objects->store['posTransaction_schema']['txn-1'];
        $this->service->emitTenderPostedEvents($transaction);

        $stored = $this->objects->store['posTender_schema']['t1'];
        $this->assertTrue($stored['glPosted']);
        $this->assertSame(1, $stored['glPostAttempts']);
    }//end testEmitTenderPostedFlagsTendersPosted()

    /**
     * retryAllUnpostedTenders re-posts only the tenders not yet posted, for
     * settled transactions, and respects the attempt cap.
     *
     * @return void
     */
    public function testRetryAllUnpostedTendersRepostsOnlyUnposted(): void
    {
        $this->seed(['status' => 'settled']);
        // t1 already posted → skipped; t2 unposted → reposted; t3 over cap → skipped.
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 10.0, 'glPosted' => true, 'glPostAttempts' => 1]);
        $this->seedTender('t2', ['tenderType' => 'type-cash', 'amount' => 10.0, 'glPosted' => false, 'glPostAttempts' => 0]);
        $this->seedTender('t3', ['tenderType' => 'type-cash', 'amount' => 10.0, 'glPosted' => false, 'glPostAttempts' => 10]);

        $reposted = $this->service->retryAllUnpostedTenders(maxAttempts: 10);

        $this->assertSame(1, $reposted);
        $this->assertTrue($this->objects->store['posTender_schema']['t2']['glPosted']);
        // t3 stayed unposted (over the cap) and was not attempted again.
        $this->assertFalse($this->objects->store['posTender_schema']['t3']['glPosted']);
        $this->assertSame(10, $this->objects->store['posTender_schema']['t3']['glPostAttempts']);
    }//end testRetryAllUnpostedTendersRepostsOnlyUnposted()

    /**
     * retryUnpostedTenders does nothing for a transaction that is not settled.
     *
     * @return void
     */
    public function testRetrySkipsUnsettledTransaction(): void
    {
        $this->seed(['status' => 'confirmed']);
        $this->seedTender('t1', ['tenderType' => 'type-cash', 'amount' => 10.0, 'glPosted' => false]);

        $this->assertSame(0, $this->service->retryUnpostedTenders('txn-1', 10));
        $this->assertCount(0, $this->webhooks->events);
    }//end testRetrySkipsUnsettledTransaction()
}//end class

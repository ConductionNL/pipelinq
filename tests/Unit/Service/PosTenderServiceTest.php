<?php

/**
 * Unit tests for PosTenderService.
 *
 * Exercises the tender-domain business rules (REQ-PST-001..006) against an
 * in-memory ObjectService fake: tender-type CRUD with code-uniqueness +
 * active-reference guards, per-transaction tender add / remove / list with
 * the settled-state invariant, server-authoritative tender-sum validation
 * with the change-tender overpayment rule, the cash-change calculation
 * helper, and the CloudEvent emission path which must persist the event-id
 * and increment glPostAttempts on the tender.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#10.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\InvalidTenderException;
use OCA\Pipelinq\Service\PosTenderService;
use OCA\Pipelinq\Service\TenderTypeNotFoundException;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory fake of the OR ObjectService for the tender tests.
 *
 * Keyed by schema then by uuid; findAll filters on the optional `filters`
 * entry; saveObject auto-assigns a uuid when empty.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Mirrors the real ObjectService signature.
 */
class TenderFakeObjectService
{

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $store = [];

    /**
     * @var integer
     */
    private int $seq = 0;

    /**
     * @var boolean
     */
    public bool $throwOnSave = false;

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $id, string $register, string $schema): ?array
    {
        return $this->store[$schema][$id] ?? null;
    }//end find()

    /**
     * @param array<string, mixed> $config
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = (array) ($config['filters'] ?? []);
        $schema  = (string) ($config['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        if ($filters === []) {
            return $rows;
        }

        return array_values(
            array_filter(
                $rows,
                function (array $row) use ($filters): bool {
                    foreach ($filters as $key => $value) {
                        if (($row[$key] ?? null) !== $value) {
                            return false;
                        }
                    }

                    return true;
                }
            )
        );
    }//end findAll()

    /**
     * @param array<string, mixed> $object
     *
     * @return array<string, mixed>
     */
    public function saveObject(array $object, array $extend, string $register, string $schema, string $uuid): array
    {
        if ($this->throwOnSave === true) {
            throw new \RuntimeException('fake save error');
        }

        if ($uuid === '') {
            $this->seq++;
            $uuid = $schema.'-'.$this->seq;
        }

        $object['id'] = $uuid;
        $this->store[$schema][$uuid] = $object;
        return $object;
    }//end saveObject()

    public function deleteObject(string $id, string $register, string $schema): void
    {
        unset($this->store[$schema][$id]);
    }//end deleteObject()
}//end class

/**
 * In-memory fake of OR WebhookService capturing dispatched events.
 */
class TenderFakeWebhookService
{

    /**
     * @var array<int, array{eventName: string, payload: array<string, mixed>}>
     */
    public array $events = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchEvent(object $_event, string $eventName, array $payload): void
    {
        $this->events[] = ['eventName' => $eventName, 'payload' => $payload];
    }//end dispatchEvent()
}//end class

/**
 * Tests for PosTenderService.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/pos-split-tender/tasks.md#10.1
 */
class PosTenderServiceTest extends TestCase
{

    private PosTenderService $service;

    private TenderFakeObjectService $objects;

    private TenderFakeWebhookService $webhooks;

    /**
     * @var array<string, string>
     */
    private array $appConfigStore = [
        'register'              => 'reg',
        'posTender_schema'      => 'posTender',
        'posTenderType_schema'  => 'posTenderType',
        'posTransaction_schema' => 'posTransaction',
    ];

    protected function setUp(): void
    {
        $this->objects  = new TenderFakeObjectService();
        $this->webhooks = new TenderFakeWebhookService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') {
                return $this->appConfigStore[$key] ?? $default;
            }
        );

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\\OpenRegister\\Service\\ObjectService') {
                    return $this->objects;
                }

                if ($id === 'OCA\\OpenRegister\\Service\\WebhookService') {
                    return $this->webhooks;
                }

                throw new \RuntimeException('unknown service '.$id);
            }
        );

        $dispatcher = $this->createMock(IEventDispatcher::class);

        $this->service = new PosTenderService(
            container: $container,
            appConfig: $appConfig,
            eventDispatcher: $dispatcher,
            logger: $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * Seed a tender type into the store.
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed>
     */
    private function seedTenderType(array $overrides=[]): array
    {
        $id   = (string) ($overrides['id'] ?? 'type-'.bin2hex(random_bytes(4)));
        $type = array_merge(
            [
                'id'                => $id,
                'name'              => 'Contant',
                'code'              => 'CASH',
                'description'       => '',
                'glAccount'         => '1100',
                'requiresReference' => false,
                'requiresPin'       => false,
                'allowsChange'      => true,
                'isActive'          => true,
                'sortOrder'         => 1,
            ],
            $overrides,
        );

        $this->objects->store['posTenderType'][$id] = $type;
        return $type;
    }//end seedTenderType()

    /**
     * Seed a transaction.
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed>
     */
    private function seedTransaction(array $overrides=[]): array
    {
        $id          = (string) ($overrides['id'] ?? 'txn-'.bin2hex(random_bytes(4)));
        $transaction = array_merge(
            [
                'id'        => $id,
                'reference' => 'TXN-0001',
                'total'     => 27.59,
                'status'    => 'confirmed',
            ],
            $overrides,
        );

        $this->objects->store['posTransaction'][$id] = $transaction;
        return $transaction;
    }//end seedTransaction()

    /**
     * Seed a tender directly.
     *
     * @param array<string, mixed> $overrides Field overrides.
     *
     * @return array<string, mixed>
     */
    private function seedTender(array $overrides=[]): array
    {
        $id     = (string) ($overrides['id'] ?? 'tender-'.bin2hex(random_bytes(4)));
        $tender = array_merge(
            [
                'id'             => $id,
                'transaction'    => 'txn-1',
                'tenderType'     => 'type-1',
                'amount'         => 10.00,
                'glAccount'      => '1100',
                'change'         => 0.0,
                'sortOrder'      => 1,
                'glPosted'       => false,
                'glPostAttempts' => 0,
            ],
            $overrides,
        );

        $this->objects->store['posTender'][$id] = $tender;
        return $tender;
    }//end seedTender()

    // -----------------------------------------------------------------
    // calculateChange (REQ-PST-005)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testCalculateChangeOverpay(): void
    {
        $this->assertSame(22.80, $this->service->calculateChange(50.00, 27.20));
    }//end testCalculateChangeOverpay()

    /**
     * @return void
     */
    public function testCalculateChangeExact(): void
    {
        $this->assertSame(0.0, $this->service->calculateChange(27.20, 27.20));
    }//end testCalculateChangeExact()

    /**
     * @return void
     */
    public function testCalculateChangeUnderpay(): void
    {
        $this->assertSame(0.0, $this->service->calculateChange(10.00, 27.20));
    }//end testCalculateChangeUnderpay()

    // -----------------------------------------------------------------
    // listTenderTypes / getTenderTypeByCode (REQ-PST-001)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testListTenderTypesReturnsAllSortedBySortOrder(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'sortOrder' => 2]);
        $this->seedTenderType(['id' => 't2', 'code' => 'CARD', 'sortOrder' => 1]);
        $this->seedTenderType(['id' => 't3', 'code' => 'OFF', 'sortOrder' => 3, 'isActive' => false]);

        $types = $this->service->listTenderTypes(activeOnly: false);

        $this->assertCount(3, $types);
        $this->assertSame('CARD', $types[0]['code']);
        $this->assertSame('CASH', $types[1]['code']);
        $this->assertSame('OFF', $types[2]['code']);
    }//end testListTenderTypesReturnsAllSortedBySortOrder()

    /**
     * @return void
     */
    public function testListTenderTypesActiveOnlyFiltersDeactivated(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $this->seedTenderType(['id' => 't2', 'code' => 'OFF', 'isActive' => false]);

        $types = $this->service->listTenderTypes(activeOnly: true);

        $this->assertCount(1, $types);
        $this->assertSame('CASH', $types[0]['code']);
    }//end testListTenderTypesActiveOnlyFiltersDeactivated()

    /**
     * @return void
     */
    public function testGetTenderTypeByCodeThrowsOnMissing(): void
    {
        $this->expectException(TenderTypeNotFoundException::class);
        $this->service->getTenderTypeByCode(code: 'NOSUCH');
    }//end testGetTenderTypeByCodeThrowsOnMissing()

    /**
     * @return void
     */
    public function testGetTenderTypeByIdReturnsType(): void
    {
        $this->seedTenderType(['id' => 'cash-1', 'code' => 'CASH']);

        $type = $this->service->getTenderTypeById(id: 'cash-1');

        $this->assertSame('CASH', $type['code']);
    }//end testGetTenderTypeByIdReturnsType()

    // -----------------------------------------------------------------
    // createTenderType / updateTenderType / deleteTenderType (REQ-PST-001)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testCreateTenderTypeRequiresName(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->createTenderType(data: ['code' => 'CASH', 'glAccount' => '1100']);
    }//end testCreateTenderTypeRequiresName()

    /**
     * @return void
     */
    public function testCreateTenderTypeRequiresGlAccount(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->createTenderType(data: ['name' => 'Contant', 'code' => 'CASH']);
    }//end testCreateTenderTypeRequiresGlAccount()

    /**
     * @return void
     */
    public function testCreateTenderTypeRejectsDuplicateCode(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

        $this->expectException(OCSBadRequestException::class);
        $this->service->createTenderType(
                data: [
                    'name'      => 'Contant 2',
                    'code'      => 'CASH',
                    'glAccount' => '1100',
                ]
                );
    }//end testCreateTenderTypeRejectsDuplicateCode()

    /**
     * @return void
     */
    public function testCreateTenderTypePersists(): void
    {
        $saved = $this->service->createTenderType(
                data: [
                    'name'              => 'Cadeaubon',
                    'code'              => 'VOUCHER',
                    'glAccount'         => '2100',
                    'requiresReference' => true,
                ]
                );

        $this->assertSame('VOUCHER', $saved['code']);
        $this->assertSame('2100', $saved['glAccount']);
        $this->assertTrue($saved['requiresReference']);
        $this->assertTrue($saved['isActive']);
    }//end testCreateTenderTypePersists()

    /**
     * @return void
     */
    public function testUpdateTenderTypePreservesCode(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'name' => 'Contant']);

        $updated = $this->service->updateTenderType(
            id: 't1',
            data: ['name' => 'Kas', 'code' => 'CHANGED', 'glAccount' => '1100'],
        );

        $this->assertSame('CASH', $updated['code']);
        $this->assertSame('Kas', $updated['name']);
    }//end testUpdateTenderTypePreservesCode()

    /**
     * @return void
     */
    public function testDeleteTenderTypeWithActiveReferencesRejects(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $this->seedTender(['tenderType' => 't1']);

        $this->expectException(OCSBadRequestException::class);
        $this->service->deleteTenderType(id: 't1');
    }//end testDeleteTenderTypeWithActiveReferencesRejects()

    /**
     * @return void
     */
    public function testDeleteTenderTypeNoReferencesSucceeds(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

        $this->service->deleteTenderType(id: 't1');

        $this->assertArrayNotHasKey('t1', $this->objects->store['posTenderType']);
    }//end testDeleteTenderTypeNoReferencesSucceeds()

    /**
     * @return void
     */
    public function testDeleteTenderTypeMissingThrowsNotFound(): void
    {
        $this->expectException(TenderTypeNotFoundException::class);
        $this->service->deleteTenderType(id: 'nonexistent');
    }//end testDeleteTenderTypeMissingThrowsNotFound()

    // -----------------------------------------------------------------
    // addTender / removeTender (REQ-PST-002 / REQ-PST-003)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testAddTenderPersistsValidPayload(): void
    {
        $type = $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $txn  = $this->seedTransaction(['id' => 'tx1', 'total' => 27.59]);

        $saved = $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 27.59],
        );

        $this->assertSame('tx1', $saved['transaction']);
        $this->assertSame('t1', $saved['tenderType']);
        $this->assertSame(27.59, $saved['amount']);
        $this->assertSame('1100', $saved['glAccount']);
        $this->assertSame(0.0, $saved['change']);
    }//end testAddTenderPersistsValidPayload()

    /**
     * @return void
     */
    public function testAddTenderComputesChangeForCashOverpay(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH', 'allowsChange' => true]);
        $this->seedTransaction(['id' => 'tx1', 'total' => 27.20]);

        $saved = $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 50.00],
        );

        $this->assertSame(50.00, $saved['amount']);
        $this->assertSame(22.80, $saved['change']);
    }//end testAddTenderComputesChangeForCashOverpay()

    /**
     * @return void
     */
    public function testAddTenderRejectsOnSettledTransaction(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $this->seedTransaction(['id' => 'tx1', 'status' => 'settled']);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('Cannot add tenders to a settled transaction');

        $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 10.00],
        );
    }//end testAddTenderRejectsOnSettledTransaction()

    /**
     * @return void
     */
    public function testAddTenderRejectsAmountBelowMinimum(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $this->seedTransaction(['id' => 'tx1']);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('Tender amount must be greater than');

        $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 0.005],
        );
    }//end testAddTenderRejectsAmountBelowMinimum()

    /**
     * @return void
     */
    public function testAddTenderRejectsMissingReferenceWhenRequired(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CARD', 'requiresReference' => true]);
        $this->seedTransaction(['id' => 'tx1']);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('Reference is required');

        $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 10.00],
        );
    }//end testAddTenderRejectsMissingReferenceWhenRequired()

    /**
     * @return void
     */
    public function testAddTenderAcceptsReferenceWhenRequired(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CARD', 'requiresReference' => true]);
        $this->seedTransaction(['id' => 'tx1']);

        $saved = $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 10.00, 'reference' => 'AUTH-1'],
        );

        $this->assertSame('AUTH-1', $saved['reference']);
    }//end testAddTenderAcceptsReferenceWhenRequired()

    /**
     * @return void
     */
    public function testAddTenderRejectsInactiveType(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'OFF', 'isActive' => false]);
        $this->seedTransaction(['id' => 'tx1']);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('is not active');

        $this->service->addTender(
            transactionId: 'tx1',
            payload: ['tenderType' => 't1', 'amount' => 10.00],
        );
    }//end testAddTenderRejectsInactiveType()

    /**
     * @return void
     */
    public function testAddTenderRejectsMissingTenderType(): void
    {
        $this->seedTransaction(['id' => 'tx1']);

        $this->expectException(InvalidTenderException::class);
        $this->service->addTender(
            transactionId: 'tx1',
            payload: ['amount' => 10.00],
        );
    }//end testAddTenderRejectsMissingTenderType()

    /**
     * @return void
     */
    public function testAddTenderRejectsMissingTransaction(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);

        $this->expectException(OCSNotFoundException::class);
        $this->service->addTender(
            transactionId: 'missing-tx',
            payload: ['tenderType' => 't1', 'amount' => 10.00],
        );
    }//end testAddTenderRejectsMissingTransaction()

    /**
     * @return void
     */
    public function testRemoveTenderRejectsOnSettledTransaction(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1', 'status' => 'settled']);
        $this->seedTender(['id' => 'tnd1', 'transaction' => 'tx1', 'tenderType' => 't1']);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('Cannot remove tenders from a settled transaction');

        $this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');
    }//end testRemoveTenderRejectsOnSettledTransaction()

    /**
     * @return void
     */
    public function testRemoveTenderRejectsWhenTenderBelongsToDifferentTransaction(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1']);
        $this->seedTender(['id' => 'tnd1', 'transaction' => 'tx-other']);

        $this->expectException(OCSNotFoundException::class);
        $this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');
    }//end testRemoveTenderRejectsWhenTenderBelongsToDifferentTransaction()

    /**
     * @return void
     */
    public function testRemoveTenderDeletesValidTender(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1']);
        $this->seedTender(['id' => 'tnd1', 'transaction' => 'tx1', 'tenderType' => 't1']);

        $this->service->removeTender(transactionId: 'tx1', tenderId: 'tnd1');

        $this->assertArrayNotHasKey('tnd1', $this->objects->store['posTender']);
    }//end testRemoveTenderDeletesValidTender()

    // -----------------------------------------------------------------
    // getTendersForTransaction (REQ-PST-002)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testGetTendersForTransactionReturnsSortedByOrder(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1']);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'sortOrder' => 3]);
        $this->seedTender(['id' => 'b', 'transaction' => 'tx1', 'sortOrder' => 1]);
        $this->seedTender(['id' => 'c', 'transaction' => 'tx1', 'sortOrder' => 2]);
        $this->seedTender(['id' => 'd', 'transaction' => 'tx-other', 'sortOrder' => 0]);

        $tenders = $this->service->getTendersForTransaction(transactionId: 'tx1');

        $this->assertCount(3, $tenders);
        $this->assertSame('b', $tenders[0]['id']);
        $this->assertSame('c', $tenders[1]['id']);
        $this->assertSame('a', $tenders[2]['id']);
    }//end testGetTendersForTransactionReturnsSortedByOrder()

    /**
     * @return void
     */
    public function testGetTendersForEmptyIdReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->getTendersForTransaction(transactionId: ''));
    }//end testGetTendersForEmptyIdReturnsEmpty()

    // -----------------------------------------------------------------
    // validateTenderSum + assertBalancedForSettle (REQ-PST-004)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testValidateTenderSumReportsBalancedTotal(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1', 'total' => 100.00]);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 60.00]);
        $this->seedTender(['id' => 'b', 'transaction' => 'tx1', 'amount' => 40.00]);

        $result = $this->service->validateTenderSum(transactionId: 'tx1');

        $this->assertSame(100.00, $result['tenderSum']);
        $this->assertSame(100.00, $result['transactionTotal']);
        $this->assertSame(0.00, $result['variance']);
        $this->assertTrue($result['balanced']);
    }//end testValidateTenderSumReportsBalancedTotal()

    /**
     * @return void
     */
    public function testValidateTenderSumReportsUnderpayment(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1', 'total' => 100.00]);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 60.00]);

        $result = $this->service->validateTenderSum(transactionId: 'tx1');

        $this->assertSame(60.00, $result['tenderSum']);
        $this->assertSame(40.00, $result['variance']);
        $this->assertFalse($result['balanced']);
    }//end testValidateTenderSumReportsUnderpayment()

    /**
     * @return void
     */
    public function testAssertBalancedForSettleAcceptsExactMatch(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1', 'total' => 50.00]);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 50.00]);

        $this->service->assertBalancedForSettle(transactionId: 'tx1');
        $this->addToAssertionCount(1);
    }//end testAssertBalancedForSettleAcceptsExactMatch()

    /**
     * @return void
     */
    public function testAssertBalancedForSettleRejectsUnderpayment(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1', 'total' => 50.00]);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 30.00]);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('Underpayment');
        $this->service->assertBalancedForSettle(transactionId: 'tx1');
    }//end testAssertBalancedForSettleRejectsUnderpayment()

    /**
     * @return void
     */
    public function testAssertBalancedForSettleAcceptsOverpaymentWithChangeTender(): void
    {
        $this->seedTenderType(['id' => 'cash', 'code' => 'CASH', 'allowsChange' => true]);
        $this->seedTransaction(['id' => 'tx1', 'total' => 25.00]);
        // Cash tender of 30 with 5 of change recorded covers the overpayment.
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 30.00, 'change' => 5.00]);

        $this->service->assertBalancedForSettle(transactionId: 'tx1');
        $this->addToAssertionCount(1);
    }//end testAssertBalancedForSettleAcceptsOverpaymentWithChangeTender()

    /**
     * @return void
     */
    public function testAssertBalancedForSettleRejectsOverpaymentWithoutChangeTender(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $this->seedTransaction(['id' => 'tx1', 'total' => 25.00]);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'amount' => 30.00, 'change' => 0.0]);

        $this->expectException(InvalidTenderException::class);
        $this->expectExceptionMessage('Overpayment');
        $this->service->assertBalancedForSettle(transactionId: 'tx1');
    }//end testAssertBalancedForSettleRejectsOverpaymentWithoutChangeTender()

    // -----------------------------------------------------------------
    // emitSingleTenderPosted + markTenderGlPosted + listUnpostedTenders (REQ-PST-006)
    // -----------------------------------------------------------------

    /**
     * @return void
     */
    public function testEmitSingleTenderPostedPersistsEventIdAndIncrementsAttempts(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $tender = $this->seedTender(
                [
                    'id'             => 'tnd1',
                    'transaction'    => 'tx1',
                    'tenderType'     => 't1',
                    'amount'         => 25.00,
                    'glAccount'      => '1100',
                    'glPostAttempts' => 0,
                ]
                );

        $eventId = $this->service->emitSingleTenderPosted(
            transactionUuid: 'tx1',
            transactionReference: 'TXN-0001',
            tender: $tender,
        );

        $this->assertNotSame('', $eventId);
        $stored = $this->objects->store['posTender']['tnd1'];
        $this->assertSame($eventId, $stored['cloudEventId']);
        $this->assertSame(1, $stored['glPostAttempts']);
        $this->assertNotSame([], $this->webhooks->events);
        $this->assertSame(PosTenderService::EVENT_TENDER_POSTED, $this->webhooks->events[0]['eventName']);
    }//end testEmitSingleTenderPostedPersistsEventIdAndIncrementsAttempts()

    /**
     * @return void
     */
    public function testEmitSingleTenderPostedSoftFailsAtMaxAttempts(): void
    {
        $this->seedTenderType(['id' => 't1']);
        $tender = $this->seedTender(
                [
                    'id'             => 'tnd1',
                    'transaction'    => 'tx1',
                    'tenderType'     => 't1',
                    'glPostAttempts' => PosTenderService::MAX_GL_POST_ATTEMPTS,
                ]
                );

        $eventId = $this->service->emitSingleTenderPosted(
            transactionUuid: 'tx1',
            transactionReference: 'TXN-0001',
            tender: $tender,
        );

        $this->assertSame('', $eventId);
        $this->assertSame([], $this->webhooks->events);
    }//end testEmitSingleTenderPostedSoftFailsAtMaxAttempts()

    /**
     * @return void
     */
    public function testMarkTenderGlPostedFlipsTheFlag(): void
    {
        $this->seedTender(['id' => 'tnd1', 'glPosted' => false]);

        $this->service->markTenderGlPosted(tenderId: 'tnd1');

        $this->assertTrue($this->objects->store['posTender']['tnd1']['glPosted']);
    }//end testMarkTenderGlPostedFlipsTheFlag()

    /**
     * @return void
     */
    public function testListUnpostedTendersIncludesAttemptedNotConfirmed(): void
    {
        $this->seedTender(['id' => 'a', 'glPosted' => false, 'glPostAttempts' => 1]);
        $this->seedTender(['id' => 'b', 'glPosted' => true, 'glPostAttempts' => 2]);
        $this->seedTender(['id' => 'c', 'glPosted' => false, 'glPostAttempts' => 0]);
        $this->seedTender(['id' => 'd', 'glPosted' => false, 'glPostAttempts' => PosTenderService::MAX_GL_POST_ATTEMPTS]);

        $unposted = $this->service->listUnpostedTenders();
        $ids      = array_map(static fn (array $row): string => (string) $row['id'], $unposted);

        $this->assertSame(['a'], $ids);
    }//end testListUnpostedTendersIncludesAttemptedNotConfirmed()

    /**
     * @return void
     */
    public function testEmitTendersPostedFanOutsAcrossAllTenders(): void
    {
        $this->seedTenderType(['id' => 't1', 'code' => 'CASH']);
        $this->seedTransaction(['id' => 'tx1']);
        $this->seedTender(['id' => 'a', 'transaction' => 'tx1', 'tenderType' => 't1', 'amount' => 10.00]);
        $this->seedTender(['id' => 'b', 'transaction' => 'tx1', 'tenderType' => 't1', 'amount' => 15.00]);

        $emitted = $this->service->emitTendersPosted(transactionId: 'tx1');

        $this->assertCount(2, $emitted);
        $this->assertCount(2, $this->webhooks->events);
    }//end testEmitTendersPostedFanOutsAcrossAllTenders()
}//end class

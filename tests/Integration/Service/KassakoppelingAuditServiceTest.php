<?php

/**
 * Integration tests for KassakoppelingAuditService.
 *
 * Exercises createEntry / listEntries / getEntry / verifyEntry against a real
 * signature service and an in-memory fake ObjectService, so the
 * server-authoritative signing, the per-register hash chain (each entry's
 * previousHash equals the prior entry's currentHash) and the verified-flag
 * persistence are asserted end-to-end without a live OpenRegister.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Integration\Service
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

namespace OCA\Pipelinq\Tests\Integration\Service;

use OCA\Pipelinq\Service\BelastingdienstExportService;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * In-memory ObjectService keyed by schema + id, filtering on audit fields.
 */
class FakeAuditObjectService
{

    /**
     * @var array<string, array<string, array<string, mixed>>>
     */
    public array $store = [];

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
        $filters = $config['filters'] ?? [];
        $schema  = (string) ($filters['schema'] ?? '');
        $rows    = array_values($this->store[$schema] ?? []);

        return array_values(array_filter($rows, static function (array $row) use ($filters): bool {
            foreach (['registerNumber', 'operatorId', 'action'] as $key) {
                if (isset($filters[$key]) === true && ($row[$key] ?? null) !== $filters[$key]) {
                    return false;
                }
            }

            return true;
        }));
    }//end findAll()

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
    }//end saveObject()
}//end class

/**
 * Test suite for the append-only audit ledger service.
 */
class KassakoppelingAuditServiceTest extends TestCase
{
    /**
     * The schema config key value used by the fake store.
     *
     * @var string
     */
    private const SCHEMA = 'kassakoppelingAuditLog_schema';

    /**
     * The service under test.
     *
     * @var KassakoppelingAuditService
     */
    private KassakoppelingAuditService $service;

    /**
     * The in-memory object store.
     *
     * @var FakeAuditObjectService
     */
    private FakeAuditObjectService $objects;

    /**
     * The real signature service (so the chain is genuinely signed).
     *
     * @var KassakoppelingSignatureService
     */
    private KassakoppelingSignatureService $signatureService;

    /**
     * Wire the service with fakes.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->objects = new FakeAuditObjectService();

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default='') {
                if ($key === 'register') {
                    return 'reg';
                }

                if ($key === 'kassakoppeling_secret') {
                    return 'integration-secret';
                }

                return $key;
            }
        );

        $this->signatureService = new KassakoppelingSignatureService($appConfig);
        $exportService          = new BelastingdienstExportService($this->signatureService);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(function (string $id) {
            if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                return $this->objects;
            }

            throw new \RuntimeException('unknown service '.$id);
        });

        $this->service = new KassakoppelingAuditService(
            $container,
            $appConfig,
            $this->signatureService,
            $exportService,
            $this->createMock(LoggerInterface::class),
        );
    }//end setUp()

    /**
     * createEntry stores a signed, server-stamped entry with previousHash '0'.
     *
     * @return void
     */
    public function testCreateEntry(): void
    {
        $entry = $this->service->createEntry(
            data: [
                'registerNumber' => 'REG-001',
                'action'         => 'sale',
                'amount'         => 4950,
                'taxAmount'      => 870,
            ],
            operatorId: 'user_john'
        );

        $this->assertSame('user_john', $entry['operatorId']);
        $this->assertSame('0', $entry['previousHash']);
        $this->assertNotEmpty($entry['signature']);
        $this->assertSame(64, strlen($entry['currentHash']));
        $this->assertNotEmpty($entry['timestamp']);
        // The signature verifies against the stored fields.
        $this->assertTrue($this->signatureService->verifySignature($entry, $entry['signature']));
    }//end testCreateEntry()

    /**
     * The operator id is taken from the session arg, not the client body.
     *
     * @return void
     */
    public function testCreateEntryIgnoresClientOperatorId(): void
    {
        $entry = $this->service->createEntry(
            data: [
                'registerNumber' => 'REG-001',
                'action'         => 'sale',
                'amount'         => 100,
                'operatorId'     => 'attacker',
            ],
            operatorId: 'user_real'
        );

        $this->assertSame('user_real', $entry['operatorId']);
    }//end testCreateEntryIgnoresClientOperatorId()

    /**
     * Two entries on the same register chain: the second's previousHash equals
     * the first's currentHash, and the chain verifies.
     *
     * @return void
     */
    public function testCreateMultipleEntriesChain(): void
    {
        $first  = $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 4950],
            operatorId: 'user_john'
        );
        $second = $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'void', 'amount' => 4950],
            operatorId: 'user_john'
        );

        $this->assertSame($first['currentHash'], $second['previousHash']);
        $this->assertTrue($this->signatureService->verifyHashChain([$first, $second]));
    }//end testCreateMultipleEntriesChain()

    /**
     * Chains are scoped per register: a new register restarts at previousHash 0.
     *
     * @return void
     */
    public function testChainIsPerRegister(): void
    {
        $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 100],
            operatorId: 'user_john'
        );
        $other = $this->service->createEntry(
            data: ['registerNumber' => 'REG-002', 'action' => 'sale', 'amount' => 200],
            operatorId: 'user_john'
        );

        $this->assertSame('0', $other['previousHash']);
    }//end testChainIsPerRegister()

    /**
     * listEntries filters by operator and returns timestamp-ordered results.
     *
     * @return void
     */
    public function testListEntriesFilters(): void
    {
        $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 100],
            operatorId: 'john'
        );
        $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 200],
            operatorId: 'maria'
        );

        $john = $this->service->listEntries(filters: ['operatorId' => 'john']);
        $this->assertCount(1, $john);
        $this->assertSame('john', $john[0]['operatorId']);

        $all = $this->service->listEntries();
        $this->assertCount(2, $all);
    }//end testListEntriesFilters()

    /**
     * An invalid action is rejected with a 422 (OCSBadRequestException).
     *
     * @return void
     */
    public function testCreateEntryRejectsInvalidAction(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'hack', 'amount' => 100],
            operatorId: 'john'
        );
    }//end testCreateEntryRejectsInvalidAction()

    /**
     * A missing amount is rejected with a 422.
     *
     * @return void
     */
    public function testCreateEntryRequiresAmount(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale'],
            operatorId: 'john'
        );
    }//end testCreateEntryRequiresAmount()

    /**
     * getEntry on an unknown id is a 404 (no IDOR leak).
     *
     * @return void
     */
    public function testGetEntryNotFound(): void
    {
        $this->expectException(OCSNotFoundException::class);
        $this->service->getEntry(id: 'does-not-exist');
    }//end testGetEntryNotFound()

    /**
     * verifyEntry marks an untouched entry verified=true.
     *
     * @return void
     */
    public function testVerifyEntryValid(): void
    {
        $entry = $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 4950],
            operatorId: 'john'
        );

        $this->assertTrue($this->service->verifyEntry(id: $entry['id']));
        $stored = $this->service->getEntry(id: $entry['id']);
        $this->assertTrue($stored['verified']);
    }//end testVerifyEntryValid()

    /**
     * verifyEntry marks a tampered entry verified=false.
     *
     * @return void
     */
    public function testVerifyEntryDetectsTamper(): void
    {
        $entry = $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 4950],
            operatorId: 'john'
        );

        // Tamper with the persisted record directly (bypassing the service).
        $this->objects->store[self::SCHEMA][$entry['id']]['amount'] = 1;

        $this->assertFalse($this->service->verifyEntry(id: $entry['id']));
    }//end testVerifyEntryDetectsTamper()

    /**
     * exportForBelastingdienst renders a JSON document with the entries.
     *
     * @return void
     */
    public function testExportForBelastingdienst(): void
    {
        $this->service->createEntry(
            data: ['registerNumber' => 'REG-001', 'action' => 'sale', 'amount' => 4950],
            operatorId: 'john'
        );

        $json    = $this->service->exportForBelastingdienst(fromDate: '', toDate: '', format: 'json');
        $decoded = json_decode($json, true);

        $this->assertSame(1, $decoded['exportMetadata']['entryCount']);
        $this->assertSame('valid', $decoded['exportMetadata']['chainIntegrity']);
    }//end testExportForBelastingdienst()
}//end class

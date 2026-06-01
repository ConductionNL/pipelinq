<?php

/**
 * Unit tests for KassakoppelingAuditService.
 *
 * Covers entry creation (signature injection, hash-chain building),
 * listing, single fetch, and signature verification. The OpenRegister
 * ObjectService is mocked so these tests run without the full server.
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
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#8.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\BelastingdienestExportService;
use OCA\Pipelinq\Service\KassakoppelingAuditService;
use OCA\Pipelinq\Service\KassakoppelingSignatureService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for KassakoppelingAuditService.
 *
 * @spec openspec/changes/pos-kassakoppeling-audit/tasks.md#8.2
 */
class KassakoppelingAuditServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var KassakoppelingAuditService
     */
    private KassakoppelingAuditService $service;

    /**
     * Mock DI container.
     *
     * @var ContainerInterface&MockObject
     */
    private ContainerInterface $container;

    /**
     * Mock app config.
     *
     * @var IAppConfig&MockObject
     */
    private IAppConfig $appConfig;

    /**
     * Real signature service (uses mocked app config).
     *
     * @var KassakoppelingSignatureService
     */
    private KassakoppelingSignatureService $signatureService;

    /**
     * Mock export service.
     *
     * @var BelastingdienestExportService&MockObject
     */
    private BelastingdienestExportService $exportService;

    /**
     * Mock OpenRegister ObjectService.
     *
     * @var object&MockObject
     */
    private object $objectService;

    /**
     * Test signing key.
     *
     * @var string
     */
    private string $secretKey = 'unit-test-signing-key-kassakoppeling';

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->appConfig
            ->method('getAppValue')
            ->with('pipelinq', 'kassakoppeling_secret', '')
            ->willReturn($this->secretKey);

        $this->appConfig
            ->method('getValueString')
            ->willReturnMap([
                ['pipelinq', 'register', '', 'pipelinq-register-id'],
                ['pipelinq', 'kassakoppelingAuditLog_schema', '', 'audit-schema-id'],
            ]);

        $this->signatureService = new KassakoppelingSignatureService(
            appConfig: $this->appConfig,
        );

        $this->exportService = $this->createMock(BelastingdienestExportService::class);

        // Mock the OR ObjectService as a generic stdClass-based mock.
        $this->objectService = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['saveObject', 'find', 'findAll'])
            ->getMock();

        $this->container = $this->createMock(ContainerInterface::class);
        $this->container
            ->method('get')
            ->with('OCA\OpenRegister\Service\ObjectService')
            ->willReturn($this->objectService);

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new KassakoppelingAuditService(
            container: $this->container,
            appConfig: $this->appConfig,
            signatureService: $this->signatureService,
            exportService: $this->exportService,
            logger: $logger,
        );
    }//end setUp()

    /**
     * Test createEntry injects signature and hash into the saved object.
     *
     * @return void
     */
    public function testCreateEntryInjectsSignatureAndHash(): void
    {
        $data = [
            'operatorId'     => 'user_john',
            'registerNumber' => 'REG-001',
            'action'         => 'sale',
            'amount'         => 4950,
            'timestamp'      => '2026-05-20T08:15:30Z',
        ];

        // No previous entry.
        $this->objectService
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([]);

        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function (array $saved): bool {
                    return isset($saved['signature'])
                        && isset($saved['currentHash'])
                        && isset($saved['previousHash'])
                        && $saved['previousHash'] === '0'
                        && strlen($saved['signature']) === 64
                        && strlen($saved['currentHash']) === 64;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturnArgument(0);

        $entry = $this->service->createEntry($data);
        $this->assertArrayHasKey('signature', $entry);
        $this->assertArrayHasKey('currentHash', $entry);
        $this->assertArrayHasKey('previousHash', $entry);
        $this->assertSame('0', $entry['previousHash']);
    }//end testCreateEntryInjectsSignatureAndHash()

    /**
     * Test createEntry links hash chain to previous entry.
     *
     * @return void
     */
    public function testCreateEntryLinksHashChainToPreviousEntry(): void
    {
        $previousHash    = str_repeat('a', 64);
        $previousEntry   = [
            'id'          => 'prev-entry-uuid',
            'currentHash' => $previousHash,
            'registerNumber' => 'REG-001',
        ];

        $this->objectService
            ->expects($this->once())
            ->method('findAll')
            ->willReturn([$previousEntry]);

        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function (array $saved) use ($previousHash): bool {
                    return $saved['previousHash'] === $previousHash;
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturnArgument(0);

        $data = [
            'operatorId'     => 'user_john',
            'registerNumber' => 'REG-001',
            'action'         => 'void',
            'amount'         => 4950,
            'timestamp'      => '2026-05-20T08:20:00Z',
        ];

        $entry = $this->service->createEntry($data);
        $this->assertSame($previousHash, $entry['previousHash']);
    }//end testCreateEntryLinksHashChainToPreviousEntry()

    /**
     * Test createEntry throws OCSBadRequestException for missing required fields.
     *
     * @return void
     */
    public function testCreateEntryThrowsForMissingRequiredField(): void
    {
        $this->expectException(OCSBadRequestException::class);

        // Missing 'action'.
        $this->service->createEntry([
            'operatorId'     => 'user_john',
            'registerNumber' => 'REG-001',
            'amount'         => 1000,
            'timestamp'      => '2026-05-20T08:00:00Z',
        ]);
    }//end testCreateEntryThrowsForMissingRequiredField()

    /**
     * Test createEntry throws OCSBadRequestException for invalid action.
     *
     * @return void
     */
    public function testCreateEntryThrowsForInvalidAction(): void
    {
        $this->expectException(OCSBadRequestException::class);

        $this->service->createEntry([
            'operatorId'     => 'user_john',
            'registerNumber' => 'REG-001',
            'action'         => 'invalid-action',
            'amount'         => 1000,
            'timestamp'      => '2026-05-20T08:00:00Z',
        ]);
    }//end testCreateEntryThrowsForInvalidAction()

    /**
     * Test listEntries returns an array of entries.
     *
     * @return void
     */
    public function testListEntriesReturnsArray(): void
    {
        $rawEntries = [
            ['id' => 'entry-1', 'action' => 'sale', 'amount' => 1000, 'registerNumber' => 'REG-001'],
            ['id' => 'entry-2', 'action' => 'void', 'amount' => 1000, 'registerNumber' => 'REG-001'],
        ];

        $this->objectService
            ->method('findAll')
            ->willReturn($rawEntries);

        $entries = $this->service->listEntries();
        $this->assertCount(2, $entries);
        $this->assertSame('sale', $entries[0]['action']);
        $this->assertSame('void', $entries[1]['action']);
    }//end testListEntriesReturnsArray()

    /**
     * Test listEntries returns empty array on OR exception.
     *
     * @return void
     */
    public function testListEntriesReturnsEmptyArrayOnException(): void
    {
        $this->objectService
            ->method('findAll')
            ->willThrowException(new \RuntimeException('DB error'));

        $entries = $this->service->listEntries();
        $this->assertSame([], $entries);
    }//end testListEntriesReturnsEmptyArrayOnException()

    /**
     * Test getEntry throws OCSNotFoundException for missing entry.
     *
     * @return void
     */
    public function testGetEntryThrowsForMissingEntry(): void
    {
        $this->expectException(OCSNotFoundException::class);

        $this->objectService
            ->method('find')
            ->willReturn(null);

        $this->service->getEntry('non-existent-id');
    }//end testGetEntryThrowsForMissingEntry()

    /**
     * Test verifyEntry returns true for a valid entry.
     *
     * @return void
     */
    public function testVerifyEntryReturnsTrueForValidEntry(): void
    {
        $data = [
            'operatorId'     => 'user_john',
            'registerNumber' => 'REG-001',
            'action'         => 'sale',
            'amount'         => 4950,
            'taxAmount'      => 870,
            'timestamp'      => '2026-05-20T08:15:30Z',
            'previousHash'   => '0',
            'id'             => 'entry-uuid-001',
        ];

        $data['signature']   = $this->signatureService->generateSignature($data);
        $data['currentHash'] = $this->signatureService->generateHash($data, '0');

        $this->objectService
            ->method('find')
            ->willReturn($data);

        $this->objectService
            ->method('saveObject')
            ->willReturn(array_merge($data, ['verified' => true]));

        $verified = $this->service->verifyEntry('entry-uuid-001');
        $this->assertTrue($verified);
    }//end testVerifyEntryReturnsTrueForValidEntry()

    /**
     * Test verifyEntry updates the verified flag in the store.
     *
     * @return void
     */
    public function testVerifyEntryUpdatesVerifiedFlag(): void
    {
        $data = [
            'operatorId'     => 'user_john',
            'registerNumber' => 'REG-001',
            'action'         => 'sale',
            'amount'         => 4950,
            'taxAmount'      => 870,
            'timestamp'      => '2026-05-20T08:15:30Z',
            'previousHash'   => '0',
            'id'             => 'entry-uuid-002',
        ];

        $data['signature']   = $this->signatureService->generateSignature($data);
        $data['currentHash'] = $this->signatureService->generateHash($data, '0');

        $this->objectService
            ->method('find')
            ->willReturn($data);

        $savedArgs = null;
        $this->objectService
            ->expects($this->once())
            ->method('saveObject')
            ->with(
                $this->callback(function (array $saved) use (&$savedArgs): bool {
                    $savedArgs = $saved;
                    return array_key_exists('verified', $saved);
                }),
                $this->anything(),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn($data);

        $this->service->verifyEntry('entry-uuid-002');

        $this->assertNotNull($savedArgs);
        $this->assertArrayHasKey('verified', $savedArgs);
    }//end testVerifyEntryUpdatesVerifiedFlag()
}//end class

<?php

/**
 * Unit tests for ConsentService.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\ConsentService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the consent enforcement and audit service.
 */
class ConsentServiceTest extends TestCase
{
    /**
     * Mock container.
     *
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    /**
     * Mock object service.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The service under test.
     *
     * @var ConsentService
     */
    private ConsentService $service;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->objectService = $this->createMock(ObjectService::class);
        $this->container->method('get')->willReturn($this->objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'register', '', 'reg'],
                ['pipelinq', 'consentRecord_schema', '', 'consent'],
            ]
        );

        $this->service = new ConsentService(
            $this->container,
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * canSend returns false for a contact whose latest record is opted-out.
     *
     * @return void
     */
    public function testCanSendFalseWhenOptedOut(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                ['contactId' => 'c1', 'channel' => 'sms', 'state' => 'opted-in', 'recordedAt' => '2026-01-01T00:00:00Z'],
                ['contactId' => 'c1', 'channel' => 'sms', 'state' => 'opted-out', 'recordedAt' => '2026-02-01T00:00:00Z'],
            ]
        );

        $this->assertFalse($this->service->canSend('c1', 'sms'));
    }//end testCanSendFalseWhenOptedOut()

    /**
     * canSend returns true when the latest record re-opts-in (non-destructive history).
     *
     * @return void
     */
    public function testCanSendTrueAfterReOptIn(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                ['contactId' => 'c1', 'channel' => 'sms', 'state' => 'opted-out', 'recordedAt' => '2026-02-01T00:00:00Z'],
                ['contactId' => 'c1', 'channel' => 'sms', 'state' => 'opted-in', 'recordedAt' => '2026-03-01T00:00:00Z'],
            ]
        );

        $this->assertTrue($this->service->canSend('c1', 'sms'));
    }//end testCanSendTrueAfterReOptIn()

    /**
     * canSend returns true when no record exists (unknown state is sendable).
     *
     * @return void
     */
    public function testCanSendTrueWhenUnknown(): void
    {
        $this->objectService->method('findAll')->willReturn([]);

        $this->assertTrue($this->service->canSend('c1', 'whatsapp'));
    }//end testCanSendTrueWhenUnknown()

    /**
     * STOP keyword variants classify as opt-out (case-insensitive, trimmed).
     *
     * @return void
     */
    public function testStopKeywordsClassifyAsOptOut(): void
    {
        $this->assertSame('opt-out', $this->service->classifyKeyword('STOP'));
        $this->assertSame('opt-out', $this->service->classifyKeyword(' stop '));
        $this->assertSame('opt-out', $this->service->classifyKeyword('StopAll'));
        $this->assertSame('opt-out', $this->service->classifyKeyword('UITSCHRIJVEN'));
    }//end testStopKeywordsClassifyAsOptOut()

    /**
     * Opt-in keywords classify as opt-in; other text is none.
     *
     * @return void
     */
    public function testOptInAndNeutralKeywords(): void
    {
        $this->assertSame('opt-in', $this->service->classifyKeyword('JA'));
        $this->assertSame('opt-in', $this->service->classifyKeyword('start'));
        $this->assertSame('none', $this->service->classifyKeyword('Wat zijn de openingstijden?'));
    }//end testOptInAndNeutralKeywords()

    /**
     * recordOptOut persists an append-only consent record.
     *
     * @return void
     */
    public function testRecordOptOutSaves(): void
    {
        $this->objectService->expects($this->once())
            ->method('saveObject')
            ->willReturnCallback(
                static function (array $object) {
                    return $object;
                }
            );

        $record = $this->service->recordOptOut('c1', 'sms', 'keyword-stop', 'inbound STOP');

        $this->assertIsArray($record);
        $this->assertSame('opted-out', $record['state']);
        $this->assertSame('keyword-stop', $record['source']);
    }//end testRecordOptOutSaves()
}//end class

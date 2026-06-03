<?php

/**
 * Unit tests for DeliveryAuditLogger.
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
 * @link https://codeberg.org/Conduction/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\Service\DeliveryAuditLogger;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DeliveryAuditLogger append-only behaviour.
 */
class DeliveryAuditLoggerTest extends TestCase
{
    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The logger under test.
     *
     * @var DeliveryAuditLogger
     */
    private DeliveryAuditLogger $auditLogger;

    /**
     * Set up the audit logger.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->createMock(ObjectService::class);
        $container           = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($this->objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key, string $default=''): string {
                $map = ['register' => '1', 'deliveryAuditLog_schema' => '9'];
                return ($map[$key] ?? $default);
            }
        );

        $this->auditLogger = new DeliveryAuditLogger(
            $container,
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Logging an event inserts an immutable row with a SHA-256 payload hash.
     *
     * @return void
     */
    public function testLogInsertsImmutableRowWithHash(): void
    {
        $captured     = null;
        $capturedUuid = 'unset';
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = [], ?array $extend = [], $register = null, $schema = null, ?string $uuid = null) use (&$captured, &$capturedUuid): array {
                $captured     = (array) $object;
                $capturedUuid = $uuid;
                return (array) $object;
            }
        );
        // Append-only: it must never delete an existing entry.
        $this->objectService->expects($this->never())->method('deleteObject');

        $this->auditLogger->log(messageId: 'msg-1', event: 'sent', payloadBody: 'hello', actor: 'system', retentionYears: 20);

        $this->assertIsArray($captured);
        $this->assertSame('msg-1', $captured['messageId']);
        $this->assertSame('sent', $captured['event']);
        $this->assertSame(hash('sha256', 'hello'), $captured['payloadHash']);
        $this->assertSame('system', $captured['actor']);
        $this->assertNull($capturedUuid, 'Audit entries must always insert (uuid: null), never update.');
    }//end testLogInsertsImmutableRowWithHash()

    /**
     * The retention date is computed from the provided retention class.
     *
     * @return void
     */
    public function testRetentionUntilIsComputed(): void
    {
        $captured = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$captured): array {
                $captured = (array) $object;
                return (array) $object;
            }
        );

        $this->auditLogger->log(messageId: 'm', event: 'queued', payloadBody: 'x', retentionYears: 10);

        $year = (int) (new \DateTimeImmutable($captured['retentionUntil']))->format('Y');
        $this->assertSame((((int) date('Y')) + 10), $year);
    }//end testRetentionUntilIsComputed()
}//end class

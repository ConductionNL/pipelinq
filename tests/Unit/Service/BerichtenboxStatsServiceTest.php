<?php

/**
 * Unit tests for BerichtenboxStatsService.
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
use OCA\Pipelinq\Service\BerichtenboxStatsService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for BerichtenboxStatsService.
 */
class BerichtenboxStatsServiceTest extends TestCase
{
    /**
     * The object service mock.
     *
     * @var ObjectService
     */
    private ObjectService $objectService;

    /**
     * The service under test.
     *
     * @var BerichtenboxStatsService
     */
    private BerichtenboxStatsService $service;

    /**
     * Set up the service.
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
                $map = ['register' => '1', 'berichtenboxMessage_schema' => '2'];
                return ($map[$key] ?? $default);
            }
        );

        $this->service = new BerichtenboxStatsService(
            $container,
            $appConfig,
            $this->createMock(LoggerInterface::class)
        );
    }//end setUp()

    /**
     * Stats aggregate delivery status counts and the unread count.
     *
     * @return void
     */
    public function testStatsAggregates(): void
    {
        $this->objectService->method('findAll')->willReturn(
            [
                ['deliveryStatus' => 'sent', 'readAt' => ''],
                ['deliveryStatus' => 'sent', 'readAt' => '2026-06-01T00:00:00Z'],
                ['deliveryStatus' => 'failed'],
                ['deliveryStatus' => 'queued'],
            ]
        );

        $stats = $this->service->stats();

        $this->assertSame(2, $stats['sent']);
        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['queued']);
        $this->assertSame(1, $stats['unread']);
        $this->assertSame(4, $stats['total']);
    }//end testStatsAggregates()

    /**
     * Re-queue resets a failed message to queued.
     *
     * @return void
     */
    public function testRequeueResetsFailedMessage(): void
    {
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'm-1'], 'deliveryStatus' => 'failed', 'retryCount' => 5]);

        $saved = null;
        $this->objectService->method('saveObject')->willReturnCallback(
            function (array|object $object = []) use (&$saved): array {
                $saved = (array) $object;
                return $saved;
            }
        );

        $ok = $this->service->requeue('m-1');

        $this->assertTrue($ok);
        $this->assertSame('queued', $saved['deliveryStatus']);
        $this->assertSame(0, $saved['retryCount']);
    }//end testRequeueResetsFailedMessage()

    /**
     * A non-failed message is not re-queued.
     *
     * @return void
     */
    public function testRequeueRejectsNonFailedMessage(): void
    {
        $this->objectService->method('find')->willReturn(['@self' => ['id' => 'm-2'], 'deliveryStatus' => 'sent']);
        $this->objectService->expects($this->never())->method('saveObject');

        $this->assertFalse($this->service->requeue('m-2'));
    }//end testRequeueRejectsNonFailedMessage()

    /**
     * A missing message returns false.
     *
     * @return void
     */
    public function testRequeueMissingMessage(): void
    {
        $this->objectService->method('find')->willReturn(null);

        $this->assertFalse($this->service->requeue('nope'));
    }//end testRequeueMissingMessage()
}//end class

<?php

/**
 * Unit tests for MessageLogService session-window logic.
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
use OCA\Pipelinq\Service\MessageLogService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the message-log / session-window service.
 */
class MessageLogServiceTest extends TestCase
{
    /**
     * Build the service over a fixed message history.
     *
     * @param array<int, array<string, mixed>> $messages The messages findAll returns.
     *
     * @return MessageLogService The service under test.
     */
    private function build(array $messages): MessageLogService
    {
        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('findAll')->willReturn($messages);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'register', '', 'reg'],
                ['pipelinq', 'contactmoment_schema', '', 'cm'],
            ]
        );

        return new MessageLogService($container, $appConfig, $this->createMock(LoggerInterface::class));
    }//end build()

    /**
     * The window is open within 24h of the last inbound.
     *
     * @return void
     */
    public function testWindowOpenWithin24h(): void
    {
        $service = $this->build(
            [
                ['channel' => 'whatsapp', 'direction' => 'inbound', 'client' => 'c1', 'contactedAt' => '2026-06-01T10:00:00+00:00'],
            ]
        );

        $now = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $this->assertTrue($service->isWindowOpen('c1', 'whatsapp', $now));
    }//end testWindowOpenWithin24h()

    /**
     * The window is closed past 24h of the last inbound.
     *
     * @return void
     */
    public function testWindowClosedAfter24h(): void
    {
        $service = $this->build(
            [
                ['channel' => 'whatsapp', 'direction' => 'inbound', 'client' => 'c1', 'contactedAt' => '2026-06-01T10:00:00+00:00'],
            ]
        );

        $now = new \DateTimeImmutable('2026-06-02T10:01:00+00:00');
        $this->assertFalse($service->isWindowOpen('c1', 'whatsapp', $now));
    }//end testWindowClosedAfter24h()

    /**
     * With no inbound on record, the window is closed (template required).
     *
     * @return void
     */
    public function testWindowClosedWithNoInbound(): void
    {
        $service = $this->build(
            [
                ['channel' => 'whatsapp', 'direction' => 'outbound', 'client' => 'c1', 'contactedAt' => '2026-06-01T10:00:00+00:00'],
            ]
        );

        $now = new \DateTimeImmutable('2026-06-01T11:00:00+00:00');
        $this->assertFalse($service->isWindowOpen('c1', 'whatsapp', $now));
    }//end testWindowClosedWithNoInbound()

    /**
     * windowExpiresAt is the last inbound plus 24 hours.
     *
     * @return void
     */
    public function testWindowExpiresAt(): void
    {
        $service = $this->build(
            [
                ['channel' => 'whatsapp', 'direction' => 'inbound', 'client' => 'c1', 'contactedAt' => '2026-06-01T10:00:00+00:00'],
            ]
        );

        $expiry = $service->windowExpiresAt('c1', 'whatsapp');
        $this->assertNotNull($expiry);
        $this->assertStringStartsWith('2026-06-02T10:00:00', $expiry);
    }//end testWindowExpiresAt()
}//end class

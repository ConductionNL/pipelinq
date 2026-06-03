<?php

/**
 * Unit tests for TemplateApprovalSyncService reconciliation.
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
use OCA\Pipelinq\Service\Messaging\ProviderConfigService;
use OCA\Pipelinq\Service\NotificationService;
use OCA\Pipelinq\Service\TemplateApprovalSyncService;
use OCA\Pipelinq\Service\TemplateService;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for the template approval sync reconciliation logic.
 */
class TemplateApprovalSyncServiceTest extends TestCase
{
    /**
     * Build the service with a fixed local template set.
     *
     * @param array<int, array<string, mixed>> $templates The local templates.
     * @param NotificationService|null         $notifier  Optional notifier to assert against.
     *
     * @return TemplateApprovalSyncService The service under test.
     */
    private function build(array $templates, ?NotificationService $notifier = null): TemplateApprovalSyncService
    {
        $templateService = $this->createMock(TemplateService::class);
        $templateService->method('allTemplates')->willReturn($templates);

        $objectService = $this->createMock(ObjectService::class);
        $objectService->method('saveObject')->willReturn(['ok' => true]);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn($objectService);

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnMap(
            [
                ['pipelinq', 'register', '', 'reg'],
                ['pipelinq', 'messageTemplate_schema', '', 'tpl'],
            ]
        );

        $groupManager = $this->createMock(IGroupManager::class);
        $groupManager->method('get')->willReturn(null);

        return new TemplateApprovalSyncService(
            $templateService,
            $this->createMock(ProviderConfigService::class),
            $container,
            $appConfig,
            ($notifier ?? $this->createMock(NotificationService::class)),
            $groupManager,
            $this->createMock(LoggerInterface::class)
        );
    }//end build()

    /**
     * A pending template approved upstream is updated.
     *
     * @return void
     */
    public function testApprovalUpdatesStatus(): void
    {
        $service = $this->build(
            [
                ['@self' => ['id' => 't1'], 'externalId' => 'afspraak_nl', 'status' => 'pending'],
            ]
        );

        $summary = $service->reconcile(['afspraak_nl' => 'APPROVED']);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['alerted']);
    }//end testApprovalUpdatesStatus()

    /**
     * A disabled template both updates and alerts.
     *
     * @return void
     */
    public function testDisabledAlerts(): void
    {
        $service = $this->build(
            [
                ['@self' => ['id' => 't1'], 'externalId' => 'afspraak_nl', 'status' => 'approved'],
            ]
        );

        $summary = $service->reconcile(['afspraak_nl' => 'DISABLED']);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, $summary['alerted']);
    }//end testDisabledAlerts()

    /**
     * An unchanged status is a no-op.
     *
     * @return void
     */
    public function testUnchangedStatusNoOp(): void
    {
        $service = $this->build(
            [
                ['@self' => ['id' => 't1'], 'externalId' => 'afspraak_nl', 'status' => 'approved'],
            ]
        );

        $summary = $service->reconcile(['afspraak_nl' => 'APPROVED']);

        $this->assertSame(0, $summary['updated']);
    }//end testUnchangedStatusNoOp()
}//end class

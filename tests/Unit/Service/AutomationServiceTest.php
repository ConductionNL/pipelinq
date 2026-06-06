<?php

/**
 * Unit tests for AutomationService.
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
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-9.1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AutomationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationService.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-9.1
 */
class AutomationServiceTest extends TestCase
{
    private AutomationService $service;
    private ContainerInterface $container;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    private object $objectService;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container     = $this->createMock(ContainerInterface::class);
        $this->appConfig     = $this->createMock(IAppConfig::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->objectService = new class {
            public array $saved = [];
            public array $findAllResult = [];
            public function findAll(array $config = []) { return $this->findAllResult; }
            public function count(array $config = []): int { return count($this->findAllResult); }
            public function saveObject(array $data, array $extend, $register, $schema, $uuid) {
                $this->saved[] = $data;
                return $data;
            }
            public function find(string $id, $register, $schema) { return null; }
        };

        $this->container->method('get')->willReturn($this->objectService);
        $this->appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default) {
                return match ($key) {
                    'register'              => 'pipelinq',
                    'automation_schema'     => 'automation',
                    'automationLog_schema'  => 'automationLog',
                    default                 => $default,
                };
            }
        );

        $this->service = new AutomationService(
            $this->container,
            $this->appConfig,
            $this->logger,
        );
    }//end setUp()

    /**
     * Condition match — strings compare case-insensitively, gte numeric.
     *
     * @return void
     */
    public function testEvaluateTriggerConditionsMatchesCaseInsensitive(): void
    {
        $ok = $this->service->evaluateTriggerConditions(
            ['industry' => 'Gemeente', 'value' => ['gte' => 5000]],
            ['industry' => 'gemeente', 'value' => 6000]
        );
        $this->assertTrue($ok);
    }//end testEvaluateTriggerConditionsMatchesCaseInsensitive()

    /**
     * Condition miss when a field does not satisfy gte.
     *
     * @return void
     */
    public function testEvaluateTriggerConditionsMissesOnGteBelow(): void
    {
        $ok = $this->service->evaluateTriggerConditions(
            ['value' => ['gte' => 10000]],
            ['value' => 500]
        );
        $this->assertFalse($ok);
    }//end testEvaluateTriggerConditionsMissesOnGteBelow()

    /**
     * Dispatch actions records each action with a result and persists a log.
     *
     * @return void
     */
    public function testExecuteAndLogExecutionWritesAutomationLog(): void
    {
        $automation = [
            'name'    => 'X',
            'actions' => [
                ['type' => 'send_notification', 'message' => 'hi'],
                ['type' => 'add_note', 'text' => 'note'],
            ],
        ];
        $results = $this->service->executeAutomation($automation, ['id' => 'lead-1']);
        $this->assertCount(2, $results);
        $this->assertSame('success', $results[0]['result']);

        $this->service->logExecution('auto-1', 'lead-1', [
            'actionsExecuted' => $results,
            'status'          => 'success',
        ]);
        $this->assertNotEmpty($this->objectService->saved);
        $log = $this->objectService->saved[0];
        $this->assertSame('auto-1', $log['automation']);
        $this->assertSame('lead-1', $log['triggerEntity']);
        $this->assertSame('success', $log['status']);
        $this->assertCount(2, $log['actionsExecuted']);
    }//end testExecuteAndLogExecutionWritesAutomationLog()
}//end class

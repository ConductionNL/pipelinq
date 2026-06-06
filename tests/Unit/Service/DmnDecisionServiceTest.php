<?php

/**
 * Unit tests for DmnDecisionService.
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
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-9.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\DmnDecisionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DmnDecisionService.
 *
 * @spec openspec/changes/crm-workflow-automation/tasks.md#task-9.2
 */
class DmnDecisionServiceTest extends TestCase
{
    private DmnDecisionService $service;
    private ContainerInterface $container;
    private IAppConfig $appConfig;
    private LoggerInterface $logger;
    private object $objectService;
    private object $registry;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->container = $this->createMock(ContainerInterface::class);
        $this->appConfig = $this->createMock(IAppConfig::class);
        $this->logger    = $this->createMock(LoggerInterface::class);

        $adapter = new class {
            public function evaluate(array $input): array {
                return ['sla_tier' => 'high', 'assignee_pool' => 'legal'];
            }
        };
        $this->registry = new class($adapter) {
            public function __construct(public object $adapter) {}
            public function resolveAdapterById(int $id): object {
                if ($id === 0) {
                    throw new \RuntimeException('not found');
                }
                return $this->adapter;
            }
            public function getEnginesByType(string $type): array { return []; }
        };

        $this->objectService = new class {
            public array $saved = [];
            public ?array $existing = ['id' => 'lead-1', 'name' => 'Old'];
            public function find(string $id, $register, $schema) { return $this->existing; }
            public function saveObject(array $data, array $extend, $register, $schema, ?string $uuid) {
                $this->saved[] = ['uuid' => $uuid, 'data' => $data];
                return $data;
            }
        };

        $this->container->method('get')->willReturnCallback(function ($name) {
            if (strpos($name, 'WorkflowEngineRegistry') !== false) {
                return $this->registry;
            }
            return $this->objectService;
        });
        $this->appConfig->method('getValueString')->willReturn('pipelinq');

        $this->service = new DmnDecisionService(
            $this->container,
            $this->appConfig,
            $this->logger,
        );
    }//end setUp()

    /**
     * Successful evaluation returns mapped output.
     *
     * @return void
     */
    public function testEvaluateDecisionReturnsAdapterOutput(): void
    {
        $result = $this->service->evaluateDecision('42', ['priority' => 'high']);
        $this->assertSame('42', $result['decisionTableId']);
        $this->assertSame('high', $result['output']['sla_tier']);
        $this->assertArrayHasKey('evaluatedAt', $result);
    }//end testEvaluateDecisionReturnsAdapterOutput()

    /**
     * Empty / non-numeric decisionTableId throws InvalidArgumentException.
     *
     * @return void
     */
    public function testEvaluateDecisionInvalidIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->evaluateDecision('', ['x' => 1]);
    }//end testEvaluateDecisionInvalidIdThrows()

    /**
     * Apply output merges decision values back onto the entity.
     *
     * @return void
     */
    public function testApplyDecisionToEntityMerges(): void
    {
        $this->service->applyDecisionToEntity('lead-1', 'lead', ['sla_tier' => 'high']);
        $this->assertNotEmpty($this->objectService->saved);
        $saved = $this->objectService->saved[0];
        $this->assertSame('lead-1', $saved['uuid']);
        $this->assertSame('high', $saved['data']['sla_tier']);
        $this->assertSame('Old', $saved['data']['name']);
    }//end testApplyDecisionToEntityMerges()
}//end class

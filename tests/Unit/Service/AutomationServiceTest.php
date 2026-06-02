<?php

/**
 * Unit tests for AutomationService.
 *
 * Covers the automation engine core: trigger-condition evaluation (AND logic,
 * case-insensitivity, operator maps), per-action dispatch (including the
 * isolation of a failing action), the overall execution summary, automation
 * matching against a fired trigger and the append-only log write. The
 * OpenRegister ObjectService is supplied as an in-memory test double resolved
 * through the container, so the persistence path is exercised without a real
 * database.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\AutomationService;
use OCA\Pipelinq\Service\DmnDecisionService;
use OCA\Pipelinq\Service\NotificationService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for AutomationService.
 */
class AutomationServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var AutomationService
     */
    private AutomationService $service;

    /**
     * The in-memory object service double.
     *
     * @var object
     */
    private object $objectService;

    /**
     * Set up the test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objectService = $this->makeObjectServiceDouble();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            fn (string $id): object => $this->objectService
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            static function (string $app, string $key): string {
                return match ($key) {
                    'register'             => 'reg1',
                    'automation_schema'    => 'autoSchema',
                    'automationLog_schema' => 'logSchema',
                    default                => '',
                };
            }
        );

        $dmn          = $this->createMock(DmnDecisionService::class);
        $notification = $this->createMock(NotificationService::class);
        $logger       = $this->createMock(LoggerInterface::class);

        $this->service = new AutomationService(
            $container,
            $appConfig,
            $dmn,
            $notification,
            $logger,
        );
    }//end setUp()

    /**
     * Scalar conditions match case-insensitively under AND logic.
     *
     * @return void
     */
    public function testEvaluateTriggerConditionsHit(): void
    {
        $conditions = ['industry' => 'Gemeente', 'source' => 'website'];
        $entity     = ['industry' => 'gemeente', 'source' => 'WEBSITE', 'extra' => 'x'];

        $this->assertTrue($this->service->evaluateTriggerConditions($conditions, $entity));
    }//end testEvaluateTriggerConditionsHit()

    /**
     * A single unmet condition fails the whole match (AND logic).
     *
     * @return void
     */
    public function testEvaluateTriggerConditionsMiss(): void
    {
        $conditions = ['industry' => 'Gemeente', 'source' => 'website'];
        $entity     = ['industry' => 'Gemeente', 'source' => 'referral'];

        $this->assertFalse($this->service->evaluateTriggerConditions($conditions, $entity));
    }//end testEvaluateTriggerConditionsMiss()

    /**
     * Operator-map conditions evaluate numeric comparisons.
     *
     * @return void
     */
    public function testEvaluateTriggerConditionsOperatorMap(): void
    {
        $conditions = ['value' => ['gte' => 1000]];

        $this->assertTrue($this->service->evaluateTriggerConditions($conditions, ['value' => 1500]));
        $this->assertFalse($this->service->evaluateTriggerConditions($conditions, ['value' => 500]));
    }//end testEvaluateTriggerConditionsOperatorMap()

    /**
     * An empty condition set always matches.
     *
     * @return void
     */
    public function testEvaluateTriggerConditionsEmptyMatchesAll(): void
    {
        $this->assertTrue($this->service->evaluateTriggerConditions([], ['anything' => 1]));
    }//end testEvaluateTriggerConditionsEmptyMatchesAll()

    /**
     * executeAutomation runs every action and reports success.
     *
     * @return void
     */
    public function testExecuteAutomationSuccess(): void
    {
        $automation = [
            'actions' => [
                ['type' => 'add_note', 'note' => 'hi'],
                ['type' => 'update_tag', 'tag' => 'vip'],
            ],
        ];

        $result = $this->service->executeAutomation($automation, ['id' => 'e1']);

        $this->assertSame('success', $result['status']);
        $this->assertCount(2, $result['actionsExecuted']);
    }//end testExecuteAutomationSuccess()

    /**
     * An unsupported action marks the run as a failure but still records steps.
     *
     * @return void
     */
    public function testExecuteAutomationFailureIsolated(): void
    {
        $automation = [
            'actions' => [
                ['type' => 'add_note'],
                ['type' => 'does_not_exist'],
            ],
        ];

        $result = $this->service->executeAutomation($automation, ['id' => 'e1']);

        $this->assertSame('failure', $result['status']);
        $this->assertCount(2, $result['actionsExecuted']);
        $this->assertSame('success', $result['actionsExecuted'][0]['result']);
        $this->assertSame('failure', $result['actionsExecuted'][1]['result']);
    }//end testExecuteAutomationFailureIsolated()

    /**
     * A fire_webhook action with no url is a recorded failure (no throw).
     *
     * @return void
     */
    public function testDispatchActionWebhookMissingUrlFails(): void
    {
        $result = $this->service->dispatchAction('fire_webhook', [], ['id' => 'e1']);

        $this->assertSame('failure', $result['result']);
        $this->assertArrayHasKey('error', $result);
    }//end testDispatchActionWebhookMissingUrlFails()

    /**
     * getMatchingAutomations returns only active automations whose conditions hold.
     *
     * @return void
     */
    public function testGetMatchingAutomationsFiltersActiveAndConditions(): void
    {
        $this->objectService->seed = [
            ['id' => 'a1', 'isActive' => true, 'trigger' => 'lead_created', 'triggerConditions' => ['industry' => 'Gemeente']],
            ['id' => 'a2', 'isActive' => false, 'trigger' => 'lead_created', 'triggerConditions' => []],
            ['id' => 'a3', 'isActive' => true, 'trigger' => 'lead_created', 'triggerConditions' => ['industry' => 'Bedrijf']],
        ];

        $matching = $this->service->getMatchingAutomations('lead_created', ['industry' => 'gemeente']);

        $this->assertCount(1, $matching);
        $this->assertSame('a1', $matching[0]['id']);
    }//end testGetMatchingAutomationsFiltersActiveAndConditions()

    /**
     * logExecution writes an automationLog record via the object service.
     *
     * @return void
     */
    public function testLogExecutionWritesLog(): void
    {
        $this->service->logExecution('a1', 'e1', ['status' => 'success', 'actionsExecuted' => []], ['id' => 'e1']);

        $logs = array_values(array_filter(
            $this->objectService->saved,
            static fn (array $o): bool => isset($o['triggeredAt'])
        ));

        $this->assertNotEmpty($logs);
        $this->assertSame('a1', $logs[0]['automation']);
        $this->assertSame('success', $logs[0]['status']);
    }//end testLogExecutionWritesLog()

    /**
     * Build an in-memory ObjectService test double.
     *
     * Implements only the methods AutomationService calls: find, findAll and
     * saveObject. `seed` feeds findAll/find; `saved` captures every write.
     *
     * @return object The double.
     */
    private function makeObjectServiceDouble(): object
    {
        return new class {
            /**
             * Seed objects returned by find / findAll.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $seed = [];

            /**
             * Every object passed to saveObject.
             *
             * @var array<int, array<string, mixed>>
             */
            public array $saved = [];

            /**
             * Return all seeded objects (filters ignored for the double).
             *
             * @param array $config The query config.
             *
             * @return array The seeded objects.
             */
            public function findAll(array $config=[]): array
            {
                return $this->seed;
            }

            /**
             * Find a seeded object by id.
             *
             * @param string $id       The object id.
             * @param mixed  $register The register (ignored).
             * @param mixed  $schema   The schema (ignored).
             *
             * @return array|null The matching object or null.
             */
            public function find(string $id, mixed $register=null, mixed $schema=null): ?array
            {
                foreach ($this->seed as $object) {
                    if (($object['id'] ?? null) === $id) {
                        return $object;
                    }
                }

                return null;
            }

            /**
             * Capture a saved object.
             *
             * @param array $object   The object to save.
             * @param array $extend   Extend config (ignored).
             * @param mixed $register The register (ignored).
             * @param mixed $schema   The schema (ignored).
             * @param mixed $uuid     The uuid (ignored).
             *
             * @return array The saved object.
             */
            public function saveObject(array $object, array $extend=[], mixed $register=null, mixed $schema=null, mixed $uuid=null): array
            {
                $this->saved[] = $object;

                return $object;
            }
        };
    }//end makeObjectServiceDouble()
}//end class

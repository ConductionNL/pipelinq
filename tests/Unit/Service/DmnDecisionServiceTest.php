<?php

/**
 * Unit tests for DmnDecisionService.
 *
 * Covers decision-table evaluation (FIRST and COLLECT hit policies, operator
 * and equality conditions), the error contract (empty/unknown table id throws
 * rather than returning a misleading empty array) and writing decision output
 * back onto an entity. The OpenRegister ObjectService is supplied as an
 * in-memory double resolved through the container.
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

use InvalidArgumentException;
use OCA\Pipelinq\Service\DmnDecisionService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Tests for DmnDecisionService.
 */
class DmnDecisionServiceTest extends TestCase
{
    /**
     * The service under test.
     *
     * @var DmnDecisionService
     */
    private DmnDecisionService $service;

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
                    'decisionTable_schema' => 'dmnSchema',
                    default                => '',
                };
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new DmnDecisionService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * A FIRST-policy table returns the first matching rule's output.
     *
     * @return void
     */
    public function testEvaluateDecisionFirstPolicy(): void
    {
        $this->objectService->seed = [
            [
                'id'        => 'dmn1',
                'hitPolicy' => 'FIRST',
                'rules'     => [
                    ['when' => ['score' => ['gte' => 80]], 'then' => ['sla_tier' => 'high']],
                    ['when' => [], 'then' => ['sla_tier' => 'low']],
                ],
            ],
        ];

        $output = $this->service->evaluateDecision('dmn1', ['score' => 90]);

        $this->assertSame(['sla_tier' => 'high'], $output);
    }//end testEvaluateDecisionFirstPolicy()

    /**
     * The catch-all default rule applies when no specific rule matches.
     *
     * @return void
     */
    public function testEvaluateDecisionFallsThroughToDefaultRule(): void
    {
        $this->objectService->seed = [
            [
                'id'        => 'dmn1',
                'hitPolicy' => 'FIRST',
                'rules'     => [
                    ['when' => ['score' => ['gte' => 80]], 'then' => ['sla_tier' => 'high']],
                    ['when' => [], 'then' => ['sla_tier' => 'low']],
                ],
            ],
        ];

        $output = $this->service->evaluateDecision('dmn1', ['score' => 10]);

        $this->assertSame(['sla_tier' => 'low'], $output);
    }//end testEvaluateDecisionFallsThroughToDefaultRule()

    /**
     * A COLLECT-policy table returns all matching outputs.
     *
     * @return void
     */
    public function testEvaluateDecisionCollectPolicy(): void
    {
        $this->objectService->seed = [
            [
                'id'        => 'dmn1',
                'hitPolicy' => 'COLLECT',
                'rules'     => [
                    ['when' => ['region' => 'noord'], 'then' => ['pool' => 'A']],
                    ['when' => ['region' => 'NOORD'], 'then' => ['pool' => 'B']],
                ],
            ],
        ];

        $output = $this->service->evaluateDecision('dmn1', ['region' => 'Noord']);

        $this->assertSame(['matches' => [['pool' => 'A'], ['pool' => 'B']]], $output);
    }//end testEvaluateDecisionCollectPolicy()

    /**
     * An empty table id throws (never returns an empty array).
     *
     * @return void
     */
    public function testEvaluateDecisionEmptyIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->evaluateDecision('', ['score' => 1]);
    }//end testEvaluateDecisionEmptyIdThrows()

    /**
     * An unknown table id throws (the table is not in this app's scope).
     *
     * @return void
     */
    public function testEvaluateDecisionUnknownIdThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->evaluateDecision('missing', ['score' => 1]);
    }//end testEvaluateDecisionUnknownIdThrows()

    /**
     * applyDecisionToEntity merges output onto the entity and saves it.
     *
     * @return void
     */
    public function testApplyDecisionToEntityWritesBack(): void
    {
        $this->objectService->seed = [
            ['id' => 'lead1', 'name' => 'Acme', 'sla_tier' => 'low'],
        ];

        $this->service->applyDecisionToEntity('lead1', 'lead', ['sla_tier' => 'high', 'assignee_pool' => 'legal']);

        $this->assertNotEmpty($this->objectService->saved);
        $saved = $this->objectService->saved[0];
        $this->assertSame('high', $saved['sla_tier']);
        $this->assertSame('legal', $saved['assignee_pool']);
        $this->assertSame('Acme', $saved['name']);
    }//end testApplyDecisionToEntityWritesBack()

    /**
     * applyDecisionToEntity rejects an empty entity id.
     *
     * @return void
     */
    public function testApplyDecisionToEntityRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->applyDecisionToEntity('', 'lead', ['x' => 1]);
    }//end testApplyDecisionToEntityRejectsEmptyId()

    /**
     * Build an in-memory ObjectService test double.
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
             * Return all seeded objects.
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

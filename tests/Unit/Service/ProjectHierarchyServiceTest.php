<?php

/**
 * Unit tests for ProjectHierarchyService.
 *
 * Exercises the pure WBS logic (billable inheritance, cycle detection) and the
 * server-side roll-up (logged / billable / non-billable hours, budget status)
 * against an in-memory fake OpenRegister ObjectService — no Nextcloud server.
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
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\Pipelinq\Service\ProjectHierarchyService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * An in-memory fake of the OpenRegister ObjectService keyed by schema slug.
 *
 * Each row's identity is its `@self.slug`. findAll() answers from the store by
 * the schema filter, mirroring the real service's config-array contract.
 */
class FakeHierarchyObjectService
{
    /** @var array<string, array<int, array<string, mixed>>> */
    public array $store = [];

    /**
     * @param array<string, mixed> $config The find config (filters.register/schema).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(array $config): array
    {
        $filters = ($config['filters'] ?? []);
        $schema  = (string) ($filters['schema'] ?? '');

        return array_values($this->store[$schema] ?? []);
    }
}

/**
 * Tests for ProjectHierarchyService.
 */
class ProjectHierarchyServiceTest extends TestCase
{
    /**
     * The fake object store.
     *
     * @var FakeHierarchyObjectService
     */
    private FakeHierarchyObjectService $objects;

    /**
     * The service under test.
     *
     * @var ProjectHierarchyService
     */
    private ProjectHierarchyService $service;

    /**
     * Set up the service with a fake OR ObjectService and config.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->objects = new FakeHierarchyObjectService();

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            function (string $id) {
                if ($id === 'OCA\OpenRegister\Service\ObjectService') {
                    return $this->objects;
                }

                throw new \RuntimeException('unexpected container id: '.$id);
            }
        );

        $appConfig = $this->createMock(IAppConfig::class);
        $appConfig->method('getValueString')->willReturnCallback(
            function (string $app, string $key, string $default='') {
                if ($key === 'register') {
                    return 'pipelinq';
                }

                // Every <slug>_schema maps onto its own slug for the fake store.
                if (str_ends_with($key, '_schema') === true) {
                    return substr($key, 0, -7);
                }

                return $default;
            }
        );

        $logger = $this->createMock(LoggerInterface::class);

        $this->service = new ProjectHierarchyService($container, $appConfig, $logger);
    }//end setUp()

    /**
     * Build an object with the given @self.slug and fields.
     *
     * @param string               $schema The schema slug.
     * @param string               $slug   The object slug.
     * @param array<string, mixed> $fields The object fields.
     *
     * @return array<string, mixed>
     */
    private function obj(string $schema, string $slug, array $fields): array
    {
        return array_merge(['@self' => ['register' => 'pipelinq', 'schema' => $schema, 'slug' => $slug]], $fields);
    }//end obj()

    /**
     * resolveBillable returns the first explicit boolean, specific-first.
     *
     * @return void
     */
    public function testResolveBillablePrefersMostSpecificExplicitValue(): void
    {
        $this->assertTrue($this->service->resolveBillable([null, null, true]));
        $this->assertFalse($this->service->resolveBillable([false, null, true]));
        $this->assertTrue($this->service->resolveBillable([true, false, false]));
    }//end testResolveBillablePrefersMostSpecificExplicitValue()

    /**
     * resolveBillable defaults to true when the whole chain is null.
     *
     * @return void
     */
    public function testResolveBillableDefaultsTrueWhenAllNull(): void
    {
        $this->assertTrue($this->service->resolveBillable([null, null, null]));
        $this->assertTrue($this->service->resolveBillable([]));
    }//end testResolveBillableDefaultsTrueWhenAllNull()

    /**
     * wouldCreateCycle rejects a self-parent.
     *
     * @return void
     */
    public function testWouldCreateCycleRejectsSelfParent(): void
    {
        $this->assertTrue($this->service->wouldCreateCycle('a', 'a', []));
    }//end testWouldCreateCycleRejectsSelfParent()

    /**
     * wouldCreateCycle rejects a parent that is a descendant of the child.
     *
     * @return void
     */
    public function testWouldCreateCycleRejectsDescendantParent(): void
    {
        // Graph: a -> b -> c (c's parent is b, b's parent is a).
        $edges = ['c' => 'b', 'b' => 'a', 'a' => null];

        // Re-parenting a under c would close the loop a->...->c->a.
        $this->assertTrue($this->service->wouldCreateCycle('a', 'c', $edges));
    }//end testWouldCreateCycleRejectsDescendantParent()

    /**
     * wouldCreateCycle accepts a valid non-looping link.
     *
     * @return void
     */
    public function testWouldCreateCycleAcceptsValidLink(): void
    {
        $edges = ['b' => 'a', 'a' => null, 'x' => null];

        // Re-parenting b under x does not loop.
        $this->assertFalse($this->service->wouldCreateCycle('b', 'x', $edges));
    }//end testWouldCreateCycleAcceptsValidLink()

    /**
     * wouldCreateCycle treats an already-corrupt (looping) graph as "would cycle".
     *
     * @return void
     */
    public function testWouldCreateCycleHandlesPreexistingLoop(): void
    {
        // Corrupt graph: p <-> q already loop.
        $edges = ['p' => 'q', 'q' => 'p'];

        $this->assertTrue($this->service->wouldCreateCycle('new', 'p', $edges));
    }//end testWouldCreateCycleHandlesPreexistingLoop()

    /**
     * getProjectSummary throws when the project does not exist.
     *
     * @return void
     */
    public function testGetProjectSummaryThrowsWhenProjectMissing(): void
    {
        $this->expectException(OCSNotFoundException::class);
        $this->service->getProjectSummary('does-not-exist');
    }//end testGetProjectSummaryThrowsWhenProjectMissing()

    /**
     * getProjectSummary rolls up logged hours and computes budget status.
     *
     * @return void
     */
    public function testGetProjectSummaryRollsUpHoursAndBudget(): void
    {
        $this->objects->store['project'] = [
            $this->obj('project', 'p1', ['name' => 'Project 1', 'billable' => true, 'budgetHours' => 4, 'hourlyRate' => 100]),
        ];
        $this->objects->store['projectPhase'] = [
            $this->obj('projectPhase', 'ph1', ['name' => 'Phase 1', 'project' => 'p1', 'order' => 1, 'billable' => true]),
        ];
        $this->objects->store['projectTask'] = [
            $this->obj('projectTask', 't1', ['name' => 'Task 1', 'phase' => 'ph1', 'project' => 'p1', 'order' => 1, 'status' => 'completed']),
        ];
        // 180 + 120 = 300 minutes = 5 hours; budget is 4 -> over budget.
        $this->objects->store['projectActivity'] = [
            $this->obj('projectActivity', 'a1', ['task' => 't1', 'project' => 'p1', 'durationMinutes' => 180, 'user' => 'jan']),
            $this->obj('projectActivity', 'a2', ['task' => 't1', 'project' => 'p1', 'durationMinutes' => 120, 'user' => 'jan']),
        ];

        $summary = $this->service->getProjectSummary('p1');

        $this->assertSame(5.0, $summary['loggedHours']);
        $this->assertSame(4.0, $summary['budgetHours']);
        $this->assertSame(-1.0, $summary['remainingHours']);
        $this->assertTrue($summary['overBudget']);
        // All activities inherit billable=true -> 5 billable hours * 100 = 500.
        $this->assertSame(5.0, $summary['billableHours']);
        $this->assertSame(0.0, $summary['nonBillableHours']);
        $this->assertSame(500.0, $summary['billableAmount']);
        $this->assertCount(1, $summary['phases']);
        $this->assertSame(1, $summary['phases'][0]['tasksTotal']);
        $this->assertSame(1, $summary['phases'][0]['tasksCompleted']);
    }//end testGetProjectSummaryRollsUpHoursAndBudget()

    /**
     * A phase billable:false override cascades to its activities (non-billable),
     * while an explicit activity override wins over the phase.
     *
     * @return void
     */
    public function testBillableInheritanceAndActivityOverride(): void
    {
        $this->objects->store['project'] = [
            $this->obj('project', 'p1', ['name' => 'P', 'billable' => true, 'hourlyRate' => 50]),
        ];
        $this->objects->store['projectPhase'] = [
            // Phase overrides to non-billable; tasks/activities inherit it.
            $this->obj('projectPhase', 'ph1', ['name' => 'Ph', 'project' => 'p1', 'billable' => false]),
        ];
        $this->objects->store['projectTask'] = [
            $this->obj('projectTask', 't1', ['name' => 'T', 'phase' => 'ph1', 'project' => 'p1']),
        ];
        $this->objects->store['projectActivity'] = [
            // 60 min inherits non-billable from phase.
            $this->obj('projectActivity', 'a1', ['task' => 't1', 'project' => 'p1', 'durationMinutes' => 60, 'user' => 'x']),
            // 30 min explicitly overridden to billable -> wins over phase.
            $this->obj('projectActivity', 'a2', ['task' => 't1', 'project' => 'p1', 'durationMinutes' => 30, 'user' => 'x', 'billable' => true]),
        ];

        $summary = $this->service->getProjectSummary('p1');

        $this->assertSame(1.5, $summary['loggedHours']);
        $this->assertSame(0.5, $summary['billableHours']);
        $this->assertSame(1.0, $summary['nonBillableHours']);
        // Phase resolves to non-billable, sourced from the phase override.
        $this->assertFalse($summary['phases'][0]['billable']);
        $this->assertSame('phase', $summary['phases'][0]['billableSource']);
        // Task resolves to non-billable, inherited from the phase.
        $this->assertFalse($summary['phases'][0]['tasks'][0]['billable']);
        $this->assertSame('phase', $summary['phases'][0]['tasks'][0]['billableSource']);
    }//end testBillableInheritanceAndActivityOverride()

    /**
     * assertValidParent rejects a missing parent.
     *
     * @return void
     */
    public function testAssertValidParentRejectsMissingParent(): void
    {
        $this->objects->store['project'] = [
            $this->obj('project', 'p1', ['name' => 'P']),
        ];

        $this->expectException(OCSBadRequestException::class);
        $this->service->assertValidParent('projectPhase', 'ph1', 'no-such-project');
    }//end testAssertValidParentRejectsMissingParent()

    /**
     * assertValidParent accepts an existing parent for a new child.
     *
     * @return void
     */
    public function testAssertValidParentAcceptsExistingParent(): void
    {
        $this->objects->store['project'] = [
            $this->obj('project', 'p1', ['name' => 'P']),
        ];

        $this->service->assertValidParent('projectPhase', '', 'p1');
        $this->addToAssertionCount(1);
    }//end testAssertValidParentAcceptsExistingParent()

    /**
     * assertValidParent rejects an empty parent key.
     *
     * @return void
     */
    public function testAssertValidParentRejectsEmptyParent(): void
    {
        $this->expectException(OCSBadRequestException::class);
        $this->service->assertValidParent('projectTask', 't1', '');
    }//end testAssertValidParentRejectsEmptyParent()
}//end class

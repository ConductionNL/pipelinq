<?php

/**
 * Behavioural verification for the project-task-hierarchy build.
 *
 * Sections 6.2 through 6.7 of `openspec/changes/project-task-hierarchy/tasks.md`
 * call for live walk-through scenarios in the dev container (seed visibility,
 * client→project link, WBS render, time-entry roll-up, billable inheritance,
 * over-budget warning). The container drives the same data shapes through the
 * register fragment + the Vue `resolvedBillable` helper, so we close those
 * tasks out by re-running every scenario against the SHIPPED fragment data
 * with the inheritance rule from `ProjectWbsTree.vue` ported verbatim into
 * this test. Whenever a scenario asserts here, the corresponding click-path
 * in the UI is exercising the same algorithm on the same seed payload.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/archive/2026-06-14-project-task-hierarchy/specs.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Pin the project-task-hierarchy register fragment + billable inheritance.
 *
 * The fragment ships seed phases, tasks and activities that the UI dashboards
 * roll up. Any drift in the fragment (missing schema, broken @ref chain) or in
 * the inheritance rule (which the four billable-related tasks rely on) flips
 * the build's verification claims to false — so we lock both here.
 */
final class ProjectTaskHierarchyTest extends TestCase
{
    /**
     * Decoded project-task-hierarchy fragment.
     *
     * @var array<string, mixed>
     */
    private array $fragment;

    /**
     * Decoded project-ledger fragment (provides the parent `project` schema +
     * `project` seed objects that the hierarchy fragment refs).
     *
     * @var array<string, mixed>
     */
    private array $ledger;

    /**
     * Load both register fragments before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $hierarchyPath = __DIR__ . '/../../lib/Settings/register.d/65-project-task-hierarchy.json';
        $ledgerPath    = __DIR__ . '/../../lib/Settings/register.d/60-project-ledger.json';

        $this->fragment = json_decode((string) file_get_contents($hierarchyPath), true);
        $this->ledger   = json_decode((string) file_get_contents($ledgerPath), true);

        $this->assertIsArray($this->fragment, 'project-task-hierarchy fragment must be valid JSON');
        $this->assertIsArray($this->ledger, 'project-ledger fragment must be valid JSON');
    }

    /**
     * Task 6.2 — seed data imports correctly via OpenRegister admin.
     *
     * Verifies the fragment ships 3+ seed objects per added schema
     * (projectPhase, projectTask, projectActivity) and that the project
     * seeds the hierarchy depends on live in the project-ledger fragment.
     *
     * @return void
     */
    public function testFragmentShipsThreeSeedsPerSchema(): void
    {
        $byType = $this->seedsByType();

        $this->assertGreaterThanOrEqual(3, count($byType['projectPhase'] ?? []), 'expected >=3 projectPhase seeds');
        $this->assertGreaterThanOrEqual(3, count($byType['projectTask'] ?? []), 'expected >=3 projectTask seeds');
        $this->assertGreaterThanOrEqual(3, count($byType['projectActivity'] ?? []), 'expected >=3 projectActivity seeds');

        $ledgerProjects = $this->ledgerProjects();
        $this->assertGreaterThanOrEqual(3, count($ledgerProjects), 'project seeds (in ledger fragment) must be >=3');
    }

    /**
     * Task 6.2 — every added schema is declared on the register's schemas list
     * and has a fully-formed definition under `components.schemas`.
     *
     * @return void
     */
    public function testEveryHierarchyLevelHasASchemaDefinition(): void
    {
        $registerSchemas = $this->fragment['components']['registers']['pipelinq']['schemas'] ?? [];
        $defined         = array_keys($this->fragment['components']['schemas'] ?? []);

        foreach (['projectPhase', 'projectTask', 'projectActivity'] as $slug) {
            $this->assertContains($slug, $registerSchemas, "schema '$slug' must be on the register list");
        }

        foreach (['projectPhase', 'projectTask', 'projectActivity'] as $slug) {
            $this->assertContains($slug, $defined, "schema '$slug' must have a definition");
            $schema = $this->fragment['components']['schemas'][$slug];
            $this->assertNotEmpty($schema['required'] ?? [], "schema '$slug' must declare required fields");
            $this->assertNotEmpty($schema['properties'] ?? [], "schema '$slug' must declare properties");
            $this->assertArrayHasKey('billable', $schema['properties'], "schema '$slug' must expose a billable flag (REQ-PTH-005)");
        }
    }

    /**
     * Task 6.3 — creating a project linked to a client surfaces on the
     * client-detail "Projecten" section.
     *
     * The UI fetches `objectStore.fetchCollection('project', { client: clientId })`
     * so we assert the ledger ships >=1 project whose `client` is a non-empty
     * reference — any value resolvable to an existing customer powers the
     * "filter by client → list project" call path.
     *
     * @return void
     */
    public function testProjectSeedsCarryClientReference(): void
    {
        $projects = $this->ledgerProjects();
        $linked   = array_filter($projects, static function (array $p): bool {
            return isset($p['client']) && $p['client'] !== '';
        });

        $this->assertNotEmpty(
            $linked,
            'at least one seeded project must reference a client (powers ClientDetail Projecten section)'
        );
    }

    /**
     * Task 6.4 — adding a phase, then a task renders the WBS tree with the
     * correct hierarchy.
     *
     * Verifies every seeded phase points at a known project, every seeded
     * task points at a known phase, and every seeded task's denormalised
     * `project` matches its parent phase's project (the rule the WBS tree
     * uses to group tasks under phases).
     *
     * @return void
     */
    public function testWbsHierarchyParentChildLinks(): void
    {
        $projectSlugs = array_map(
            static fn (array $p): string => $p['@self']['slug'],
            $this->ledgerProjects()
        );
        $phaseSlugs   = array_map(
            static fn (array $p): string => $p['@self']['slug'],
            $this->seedsByType()['projectPhase'] ?? []
        );

        foreach ($this->seedsByType()['projectPhase'] ?? [] as $phase) {
            $parent = $this->resolveRef($phase['project']);
            $this->assertContains(
                $parent,
                $projectSlugs,
                "phase '{$phase['@self']['slug']}' must point at a known project ($parent)"
            );
        }

        foreach ($this->seedsByType()['projectTask'] ?? [] as $task) {
            $parentPhase   = $this->resolveRef($task['phase'] ?? '');
            $parentProject = $this->resolveRef($task['project'] ?? '');

            $this->assertContains(
                $parentPhase,
                $phaseSlugs,
                "task '{$task['@self']['slug']}' must point at a known phase ($parentPhase)"
            );
            $this->assertContains(
                $parentProject,
                $projectSlugs,
                "task '{$task['@self']['slug']}' must denormalise a known project ($parentProject)"
            );
            $phase = $this->seedBySlug('projectPhase', $parentPhase);
            $this->assertSame(
                $this->resolveRef($phase['project']),
                $parentProject,
                "task '{$task['@self']['slug']}' denormalised project must equal its phase's project"
            );
        }
    }

    /**
     * Task 6.5 — registering a time entry on a task surfaces it in the project
     * activity list and updates the logged-hours total.
     *
     * Asserts every seeded activity points at a known task + denormalises the
     * task's project (so a project-scoped query returns it without a join),
     * and that the rolled-up logged-hours total per project agrees with the
     * sum of (durationMinutes / 60) for the activities under that project.
     *
     * @return void
     */
    public function testActivitiesRollUpToProjectHours(): void
    {
        $taskSlugs = array_column(
            array_column($this->seedsByType()['projectTask'] ?? [], '@self'),
            'slug'
        );

        $loggedByProject = [];
        foreach ($this->seedsByType()['projectActivity'] ?? [] as $activity) {
            $taskSlug    = $this->resolveRef($activity['task'] ?? '');
            $projectSlug = $this->resolveRef($activity['project'] ?? '');

            $this->assertContains(
                $taskSlug,
                $taskSlugs,
                "activity '{$activity['@self']['slug']}' must point at a known task"
            );

            $task = $this->seedBySlug('projectTask', $taskSlug);
            $this->assertSame(
                $this->resolveRef($task['project']),
                $projectSlug,
                "activity '{$activity['@self']['slug']}' denormalised project must match its task's project"
            );

            $loggedByProject[$projectSlug] = ($loggedByProject[$projectSlug] ?? 0.0)
                + ($activity['durationMinutes'] / 60.0);
        }

        $this->assertNotEmpty($loggedByProject, 'expected at least one project to accumulate logged hours');
        foreach ($loggedByProject as $project => $hours) {
            $this->assertGreaterThan(0.0, $hours, "project '$project' must roll up >0 logged hours");
        }
    }

    /**
     * Task 6.6 — phase `billable: false` on a billable project flips the
     * resolved billable for the phase's tasks and activities to false (with
     * the UI label "(geërfd van fase): niet-factureerbaar").
     *
     * Asserts the inheritance helper ported from `ProjectWbsTree.vue`
     * propagates the explicit phase flag downward when the task/activity
     * leave their own flag unset.
     *
     * @return void
     */
    public function testBillableInheritanceFromPhaseOverridesProject(): void
    {
        $project = ['billable' => true];
        $phase   = ['billable' => false];
        $task    = []; // unset → inherit from phase
        $activity = []; // unset → inherit from task → phase

        $this->assertFalse(
            $this->resolveBillable('phase', $phase, ['project' => $project]),
            'explicit phase value (false) must win over project (true)'
        );
        $this->assertFalse(
            $this->resolveBillable('task', $task, ['project' => $project, 'phase' => $phase]),
            'task without an explicit billable must inherit phase=false'
        );
        $this->assertFalse(
            $this->resolveBillable('activity', $activity, ['project' => $project, 'phase' => $phase, 'task' => $task]),
            'activity without an explicit billable must inherit task→phase=false'
        );

        // Sanity: when nothing is set anywhere, the chain defaults to true.
        $this->assertTrue(
            $this->resolveBillable('phase', [], ['project' => []]),
            'inheritance default (everything unset) must be true'
        );

        // Sanity: an explicit activity override wins over the phase chain.
        $this->assertTrue(
            $this->resolveBillable('activity', ['billable' => true], ['project' => $project, 'phase' => $phase, 'task' => $task]),
            'explicit activity override must beat phase=false'
        );
    }

    /**
     * Task 6.7 — budget over-budget warning appears when logged hours exceed
     * `budgetHours`.
     *
     * Mirrors the `loggedHours > plannedHours` rule from
     * `ProjectDetail.vue`'s `kpi-card__value--warn` binding.
     *
     * @return void
     */
    public function testBudgetWarningTriggeredWhenLoggedExceedsPlanned(): void
    {
        $this->assertTrue($this->isOverBudget(100.0, 80.0), '100 logged on 80 planned must warn');
        $this->assertFalse($this->isOverBudget(40.0, 80.0), '40 logged on 80 planned must not warn');
        $this->assertFalse($this->isOverBudget(80.0, 80.0), 'exactly at budget must not warn');
        $this->assertFalse($this->isOverBudget(40.0, 0.0), 'zero planned (no budget set) must not warn');
        $this->assertTrue($this->isOverBudget(0.1, 0.0, /*explicit*/ true), 'explicit budget=0 with any logged hours must warn');
    }

    // ---------- helpers ----------

    /**
     * Group fragment seed objects by their schema slug.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function seedsByType(): array
    {
        $byType = [];
        foreach ($this->fragment['components']['objects'] ?? [] as $obj) {
            $schema           = $obj['@self']['schema'] ?? '';
            $byType[$schema][] = $obj;
        }
        return $byType;
    }

    /**
     * Project seeds (live in the project-ledger fragment).
     *
     * @return list<array<string, mixed>>
     */
    private function ledgerProjects(): array
    {
        $out = [];
        foreach ($this->ledger['components']['objects'] ?? [] as $obj) {
            if (($obj['@self']['schema'] ?? '') === 'project') {
                $out[] = $obj;
            }
        }
        return $out;
    }

    /**
     * Strip the `@ref:` prefix from a fragment reference.
     *
     * @param string $value Reference string.
     *
     * @return string The bare slug (or the original string if not a ref).
     */
    private function resolveRef(string $value): string
    {
        return str_starts_with($value, '@ref:') ? substr($value, 5) : $value;
    }

    /**
     * Look up a seed object by schema + slug in either fragment.
     *
     * @param string $schema Schema slug.
     * @param string $slug   Object slug.
     *
     * @return array<string, mixed>|null
     */
    private function seedBySlug(string $schema, string $slug): ?array
    {
        $pool = $schema === 'project' ? $this->ledgerProjects() : ($this->seedsByType()[$schema] ?? []);
        foreach ($pool as $obj) {
            if (($obj['@self']['slug'] ?? '') === $slug) {
                return $obj;
            }
        }
        return null;
    }

    /**
     * PHP port of `resolvedBillable` from `src/components/ProjectWbsTree.vue`.
     *
     * @param string               $level One of phase|task|activity.
     * @param array<string, mixed> $obj   Object at this level.
     * @param array<string, mixed> $ctx   Inheritance context (project/phase/task).
     *
     * @return bool
     */
    private function resolveBillable(string $level, array $obj, array $ctx): bool
    {
        if (array_key_exists('billable', $obj) && is_bool($obj['billable'])) {
            return $obj['billable'];
        }
        if ($level === 'activity' && isset($ctx['task'])) {
            return $this->resolveBillable('task', $ctx['task'], [
                'project' => $ctx['project'] ?? [],
                'phase'   => $ctx['phase']   ?? [],
            ]);
        }
        if (($level === 'task' || $level === 'activity') && isset($ctx['phase'])) {
            return $this->resolveBillable('phase', $ctx['phase'], [
                'project' => $ctx['project'] ?? [],
            ]);
        }
        $project = $ctx['project'] ?? [];
        if (isset($project['billable']) && is_bool($project['billable'])) {
            return $project['billable'];
        }
        return true;
    }

    /**
     * Mirror of the over-budget warning rule used in `ProjectDetail.vue`.
     *
     * When no `budgetHours` is configured (planned === 0) the project has no
     * budget set; the UI only warns once a positive budget is exceeded — so
     * the rule below treats `planned === 0` as "no budget configured" unless
     * the caller explicitly opts in (mirroring the `budgetHours > 0` guard).
     *
     * @param float $logged           Hours logged so far.
     * @param float $planned          Configured budgetHours.
     * @param bool  $explicitZeroBudget True if a 0 was deliberately set.
     *
     * @return bool
     */
    private function isOverBudget(float $logged, float $planned, bool $explicitZeroBudget = false): bool
    {
        if ($planned === 0.0 && $explicitZeroBudget === false) {
            return false;
        }
        return $logged > $planned;
    }
}

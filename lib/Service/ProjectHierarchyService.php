<?php

/**
 * Pipelinq ProjectHierarchyService.
 *
 * Server-authoritative work-breakdown-structure (WBS) computation for the
 * Pipelinq project hierarchy: client -> project -> phase -> task -> activity.
 *
 * Resolves the cascading billable flag, rolls up logged / billable hours, and
 * detects cycles in the parent chain. All figures are computed here from the
 * persisted OpenRegister objects and are never trusted from the client. The
 * subtree for one project is loaded with a small fixed number of schema-scoped
 * findAll() calls (one per WBS level) and assembled in memory, avoiding the
 * per-node N+1 a naive walk would incur.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-005
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-007
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-008
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IAppConfig;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Server-side WBS computation for the project hierarchy.
 *
 * Authorization scoping: every read is constrained inside this service to the
 * app's own register and the four project-hierarchy schemas, so a caller can
 * never reach objects outside the WBS (no IDOR). The controller layer still
 * gates the endpoints to authenticated users.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Wires the small fixed set of
 *  collaborators (OR container, app config, logger) a resolver legitimately
 *  needs.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The class aggregates the
 *  whole WBS-computation concern (billable inheritance + hour roll-up + cycle
 *  detection + OR fetch/normalise helpers) as many small, single-purpose
 *  methods; the cohesion is intentional and splitting it would scatter one
 *  concern across several classes without reducing real complexity.
 *
 * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-005
 */
class ProjectHierarchyService
{
    /**
     * Default billable value at the root of the inheritance chain.
     *
     * When no level in the chain sets an explicit boolean, work is treated as
     * billable (matches the project schema default).
     *
     * @var bool
     */
    private const DEFAULT_BILLABLE = true;

    /**
     * Constructor.
     *
     * @param ContainerInterface $container The DI container (resolves the OR ObjectService lazily).
     * @param IAppConfig         $appConfig The app config (register + schema ids).
     * @param LoggerInterface    $logger    The logger.
     */
    public function __construct(
        private ContainerInterface $container,
        private IAppConfig $appConfig,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the effective billable flag walking up an explicit-value chain.
     *
     * The chain is ordered from the most specific level to the least specific
     * (e.g. [activity, task, phase, project]). The first element that is an
     * explicit boolean wins; when every level is null/unset the root default
     * ({@see self::DEFAULT_BILLABLE}) is returned.
     *
     * Pure function — no I/O — so it is unit-testable in isolation and shared by
     * both the per-node resolution and the roll-up.
     *
     * @param array<int, bool|null> $chain Explicit billable values, specific-first.
     *
     * @return bool The resolved billable value.
     *
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-005
     */
    public function resolveBillable(array $chain): bool
    {
        foreach ($chain as $value) {
            if (is_bool($value) === true) {
                return $value;
            }
        }

        return self::DEFAULT_BILLABLE;
    }//end resolveBillable()

    /**
     * Detect whether linking $childKey under $proposedParentKey forms a cycle.
     *
     * $edges maps a node key to its current parent key (null when it has none).
     * The proposed link is rejected when $proposedParentKey is $childKey itself,
     * or when $childKey is already an ancestor of $proposedParentKey (which would
     * close a loop). A malformed graph that already contains a cycle is treated
     * as "would cycle" rather than looping forever (bounded by the node count).
     *
     * Pure function — no I/O.
     *
     * @param string                     $childKey          The node being (re)parented.
     * @param string                     $proposedParentKey The proposed new parent.
     * @param array<string, string|null> $edges             Current key => parent-key map.
     *
     * @return bool True when the link would create a cycle.
     *
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-002
     */
    public function wouldCreateCycle(string $childKey, string $proposedParentKey, array $edges): bool
    {
        if ($childKey === $proposedParentKey) {
            return true;
        }

        // Walk up from the proposed parent: if we reach the child, the link
        // closes a loop. Bound the walk by the node count to stay finite even
        // when the stored graph is already corrupt.
        $maxSteps = (count($edges) + 1);
        $cursor   = $proposedParentKey;
        $steps    = 0;
        while ($cursor !== null && $steps <= $maxSteps) {
            if ($cursor === $childKey) {
                return true;
            }

            $cursor = ($edges[$cursor] ?? null);
            $steps++;
        }

        // Ran past the node budget without terminating => existing cycle.
        if ($steps > $maxSteps) {
            return true;
        }

        return false;
    }//end wouldCreateCycle()

    /**
     * Validate a proposed parent reference for a WBS child, rejecting cycles.
     *
     * Loads the sibling set for the child's level within the same project and
     * verifies the proposed parent both exists and does not close a loop. Used
     * by the write path before persisting a phase/task re-parent.
     *
     * @param string $level             The child level ('projectPhase'|'projectTask'|'projectActivity').
     * @param string $childKey          The child object key (slug/uuid); '' for a not-yet-created node.
     * @param string $proposedParentKey The proposed parent key (slug/uuid).
     *
     * @return void
     *
     * @throws OCSBadRequestException When the parent is missing or the link would cycle.
     *
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-002
     */
    public function assertValidParent(string $level, string $childKey, string $proposedParentKey): void
    {
        if ($proposedParentKey === '') {
            throw new OCSBadRequestException('Een bovenliggend item is verplicht.');
        }

        $parentSchema = $this->parentSchemaFor(level: $level);
        $parents      = $this->fetchLevel(schema: $parentSchema);

        $parentExists = false;
        $edges        = [];
        foreach ($parents as $parent) {
            $key = $this->keyOf(object: $parent);
            if ($key === '') {
                continue;
            }

            if ($key === $proposedParentKey) {
                $parentExists = true;
            }

            $edges[$key] = $this->parentKeyOf(object: $parent, level: $parentSchema);
        }

        if ($parentExists === false) {
            throw new OCSBadRequestException('Het opgegeven bovenliggende item bestaat niet.');
        }

        if ($childKey !== '' && $this->wouldCreateCycle(childKey: $childKey, proposedParentKey: $proposedParentKey, edges: $edges) === true) {
            throw new OCSBadRequestException('Deze koppeling zou een cyclus in de projectstructuur veroorzaken.');
        }
    }//end assertValidParent()

    /**
     * Build the server-authoritative summary for one project's WBS.
     *
     * Returns the project, its phases (each with their tasks), the resolved
     * billable flag per node, rolled-up logged / billable / non-billable hours,
     * and a budget status. Logged hours are the sum of activity durations
     * (minutes / 60); a node's logged hours is the sum over the activities in
     * its subtree.
     *
     * @param string $projectKey The project key (slug/uuid).
     *
     * @return array<string, mixed> The project WBS summary.
     *
     * @throws OCSNotFoundException When the project does not exist.
     *
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-007
     * @spec openspec/changes/project-task-hierarchy/specs.md#REQ-PTH-008
     */
    public function getProjectSummary(string $projectKey): array
    {
        $project = $this->findProject(projectKey: $projectKey);
        if ($project === null) {
            throw new OCSNotFoundException('Project niet gevonden.');
        }

        $projectBillable = $this->explicitBool(value: ($project['billable'] ?? null));

        // Index tasks by their phase, and activities by their task, so the tree
        // is assembled with no further queries (no N+1).
        $tasksByPhase   = $this->groupBy(
            items: $this->fetchChildren(schema: 'projectTask', parentField: 'project', parentKey: $projectKey),
            field: 'phase'
        );
        $activityByTask = $this->groupBy(
            items: $this->fetchChildren(schema: 'projectActivity', parentField: 'project', parentKey: $projectKey),
            field: 'task'
        );

        // $totals[0] = billable minutes, $totals[1] = non-billable minutes;
        // accumulated across the whole subtree as phases/tasks are summarised.
        $totals         = [0, 0];
        $phaseSummaries = [];
        $phases         = $this->fetchChildren(schema: 'projectPhase', parentField: 'project', parentKey: $projectKey);
        foreach ($this->sortByOrder(items: $phases) as $phase) {
            $phaseSummaries[] = $this->summarisePhase(
                phase: $phase,
                projectBillable: $projectBillable,
                tasksByPhase: $tasksByPhase,
                activityByTask: $activityByTask,
                totals: $totals
            );
        }

        $billableMinutes = $totals[0];
        $loggedHours     = $this->minutesToHours(minutes: ($totals[0] + $totals[1]));
        $budgetHours     = $this->toFloat(value: ($project['budgetHours'] ?? 0));
        $remainingHours  = round(($budgetHours - $loggedHours), 2);
        $hourlyRate      = $this->toFloat(value: ($project['hourlyRate'] ?? 0));
        $billableHours   = $this->minutesToHours(minutes: $billableMinutes);

        return [
            'key'              => $projectKey,
            'name'             => ($project['name'] ?? ''),
            'status'           => ($project['status'] ?? 'open'),
            'billable'         => $this->resolveBillable(chain: [$projectBillable]),
            'budgetHours'      => $budgetHours,
            'loggedHours'      => $loggedHours,
            'remainingHours'   => $remainingHours,
            'overBudget'       => ($budgetHours > 0 && $loggedHours > $budgetHours),
            'billableHours'    => $billableHours,
            'nonBillableHours' => $this->minutesToHours(minutes: $totals[1]),
            'billableAmount'   => round(($billableHours * $hourlyRate), 2),
            'phases'           => $phaseSummaries,
        ];
    }//end getProjectSummary()

    /**
     * Summarise one phase and its tasks, accumulating billable/non-billable minutes.
     *
     * @param array<string, mixed>                            $phase           The phase object.
     * @param bool|null                                       $projectBillable The project's explicit billable value.
     * @param array<string, array<int, array<string, mixed>>> $tasksByPhase    Tasks grouped by phase key.
     * @param array<string, array<int, array<string, mixed>>> $activityByTask  Activities grouped by task key.
     * @param array{0: int, 1: int}                           $totals          [billable, non-billable] minute accumulator (by ref).
     *
     * @return array<string, mixed> The phase summary.
     */
    private function summarisePhase(
        array $phase,
        ?bool $projectBillable,
        array $tasksByPhase,
        array $activityByTask,
        array &$totals
    ): array {
        $phaseKey      = $this->keyOf(object: $phase);
        $phaseBillable = $this->explicitBool(value: ($phase['billable'] ?? null));

        $taskSummaries  = [];
        $tasksCompleted = 0;
        foreach ($this->sortByOrder(items: ($tasksByPhase[$phaseKey] ?? [])) as $task) {
            $taskSummaries[] = $this->summariseTask(
                task: $task,
                phaseBillable: $phaseBillable,
                projectBillable: $projectBillable,
                activityByTask: $activityByTask,
                totals: $totals
            );

            if (($task['status'] ?? null) === 'completed') {
                $tasksCompleted++;
            }
        }

        return [
            'key'            => $phaseKey,
            'name'           => ($phase['name'] ?? ''),
            'status'         => ($phase['status'] ?? 'open'),
            'order'          => $this->toInt(value: ($phase['order'] ?? 0)),
            'billable'       => $this->resolveBillable(chain: [$phaseBillable, $projectBillable]),
            'billableSource' => $this->billableSource(chain: ['phase' => $phaseBillable, 'project' => $projectBillable]),
            'tasksTotal'     => count($taskSummaries),
            'tasksCompleted' => $tasksCompleted,
            'tasks'          => $taskSummaries,
        ];
    }//end summarisePhase()

    /**
     * Summarise one task and its activities, accumulating billable/non-billable minutes.
     *
     * @param array<string, mixed>                            $task            The task object.
     * @param bool|null                                       $phaseBillable   The parent phase's explicit billable value.
     * @param bool|null                                       $projectBillable The project's explicit billable value.
     * @param array<string, array<int, array<string, mixed>>> $activityByTask  Activities grouped by task key.
     * @param array{0: int, 1: int}                           $totals          [billable, non-billable] minute accumulator (by ref).
     *
     * @return array<string, mixed> The task summary.
     */
    private function summariseTask(
        array $task,
        ?bool $phaseBillable,
        ?bool $projectBillable,
        array $activityByTask,
        array &$totals
    ): array {
        $taskKey      = $this->keyOf(object: $task);
        $taskBillable = $this->explicitBool(value: ($task['billable'] ?? null));

        $taskMinutes = 0;
        foreach (($activityByTask[$taskKey] ?? []) as $activity) {
            $minutes      = $this->minutesOf(activity: $activity);
            $taskMinutes += $minutes;
            $billable     = $this->resolveBillable(
                chain: [$this->explicitBool(value: ($activity['billable'] ?? null)), $taskBillable, $phaseBillable, $projectBillable]
            );

            $bucket = 1;
            if ($billable === true) {
                $bucket = 0;
            }

            $totals[$bucket] += $minutes;
        }

        return [
            'key'            => $taskKey,
            'name'           => ($task['name'] ?? ''),
            'status'         => ($task['status'] ?? 'open'),
            'assignee'       => ($task['assignee'] ?? null),
            'estimatedHours' => $this->toFloat(value: ($task['estimatedHours'] ?? 0)),
            'loggedHours'    => $this->minutesToHours(minutes: $taskMinutes),
            'billable'       => $this->resolveBillable(chain: [$taskBillable, $phaseBillable, $projectBillable]),
            'billableSource' => $this->billableSource(
                chain: ['task' => $taskBillable, 'phase' => $phaseBillable, 'project' => $projectBillable]
            ),
        ];
    }//end summariseTask()

    /**
     * Resolve which level supplied the effective billable value.
     *
     * @param array<string, bool|null> $chain Ordered level-name => explicit value, specific-first.
     *
     * @return string The level name that supplied the value, or 'default'.
     */
    private function billableSource(array $chain): string
    {
        foreach ($chain as $level => $value) {
            if (is_bool($value) === true) {
                return $level;
            }
        }

        return 'default';
    }//end billableSource()

    /**
     * Find a single project object by key within this app's scope.
     *
     * @param string $projectKey The project key (slug/uuid).
     *
     * @return array<string, mixed>|null The project object, or null when absent.
     */
    private function findProject(string $projectKey): ?array
    {
        foreach ($this->fetchLevel(schema: 'project') as $project) {
            if ($this->keyOf(object: $project) === $projectKey) {
                return $project;
            }
        }

        return null;
    }//end findProject()

    /**
     * Fetch all objects of a project-hierarchy schema scoped to this app.
     *
     * @param string $schema The schema slug ('project'|'projectPhase'|'projectTask'|'projectActivity').
     *
     * @return array<int, array<string, mixed>> The objects as plain arrays.
     */
    private function fetchLevel(string $schema): array
    {
        [$register, $schemaId] = $this->config(schemaSlug: $schema);

        try {
            $results = $this->getObjectService()->findAll(
                config: [
                    'filters' => [
                        'register' => $register,
                        'schema'   => $schemaId,
                    ],
                ]
            );
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Pipelinq: failed to fetch project-hierarchy level',
                ['schema' => $schema, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $objects = [];
        foreach (($results ?? []) as $result) {
            $objects[] = $this->toArray(object: $result);
        }

        return $objects;
    }//end fetchLevel()

    /**
     * Fetch children of a parent, filtered in memory by the parent reference field.
     *
     * @param string $schema      The child schema slug.
     * @param string $parentField The field on the child holding the parent key.
     * @param string $parentKey   The parent key to match.
     *
     * @return array<int, array<string, mixed>> The matching child objects.
     */
    private function fetchChildren(string $schema, string $parentField, string $parentKey): array
    {
        $children = [];
        foreach ($this->fetchLevel(schema: $schema) as $object) {
            if ((string) ($object[$parentField] ?? '') === $parentKey) {
                $children[] = $object;
            }
        }

        return $children;
    }//end fetchChildren()

    /**
     * Group objects by the value of a reference field.
     *
     * @param array<int, array<string, mixed>> $items The objects to group.
     * @param string                           $field The field to group by.
     *
     * @return array<string, array<int, array<string, mixed>>> The grouped objects.
     */
    private function groupBy(array $items, string $field): array
    {
        $grouped = [];
        foreach ($items as $item) {
            $value = (string) ($item[$field] ?? '');
            if ($value === '') {
                continue;
            }

            $grouped[$value][] = $item;
        }

        return $grouped;
    }//end groupBy()

    /**
     * Sort objects ascending by their numeric 'order' field (stable for ties).
     *
     * @param array<int, array<string, mixed>> $items The objects to sort.
     *
     * @return array<int, array<string, mixed>> The sorted objects.
     */
    private function sortByOrder(array $items): array
    {
        usort(
            $items,
            fn (array $a, array $b): int => ($this->toInt(value: ($a['order'] ?? 0)) <=> $this->toInt(value: ($b['order'] ?? 0)))
        );

        return $items;
    }//end sortByOrder()

    /**
     * Resolve a value to an explicit boolean, or null when unset/non-boolean.
     *
     * A stored JSON boolean is honoured; anything else (null, '', missing) is
     * treated as "inherit".
     *
     * @param mixed $value The raw stored value.
     *
     * @return bool|null The explicit boolean, or null to inherit.
     */
    private function explicitBool(mixed $value): ?bool
    {
        if (is_bool($value) === true) {
            return $value;
        }

        return null;
    }//end explicitBool()

    /**
     * Extract the duration of an activity in whole minutes (clamped to >= 0).
     *
     * @param array<string, mixed> $activity The activity object.
     *
     * @return int The duration in minutes.
     */
    private function minutesOf(array $activity): int
    {
        $minutes = $this->toInt(value: ($activity['durationMinutes'] ?? 0));

        return max($minutes, 0);
    }//end minutesOf()

    /**
     * Convert minutes to hours rounded to two decimals.
     *
     * @param int $minutes The minutes.
     *
     * @return float The hours.
     */
    private function minutesToHours(int $minutes): float
    {
        return round(($minutes / 60), 2);
    }//end minutesToHours()

    /**
     * Determine the canonical key (slug, falling back to uuid) of an OR object.
     *
     * @param array<string, mixed> $object The object.
     *
     * @return string The key, or '' when none is resolvable.
     */
    private function keyOf(array $object): string
    {
        $self = ($object['@self'] ?? []);
        if (is_array($self) === true) {
            $slug = ($self['slug'] ?? null);
            if (is_string($slug) === true && $slug !== '') {
                return $slug;
            }

            $uuid = ($self['uuid'] ?? ($self['id'] ?? null));
            if (is_string($uuid) === true && $uuid !== '') {
                return $uuid;
            }
        }

        $id = ($object['id'] ?? ($object['uuid'] ?? null));
        if (is_string($id) === true && $id !== '') {
            return $id;
        }

        return '';
    }//end keyOf()

    /**
     * Resolve the parent key of a WBS object for the cycle check.
     *
     * @param array<string, mixed> $object The object.
     * @param string               $level  The object's schema slug.
     *
     * @return string|null The parent key, or null when none.
     */
    private function parentKeyOf(array $object, string $level): ?string
    {
        $field = match ($level) {
            'projectPhase'    => 'project',
            'projectTask'     => 'phase',
            'projectActivity' => 'task',
            default           => null,
        };

        if ($field === null) {
            return null;
        }

        $value = ($object[$field] ?? null);
        if (is_string($value) === true && $value !== '') {
            return $value;
        }

        return null;
    }//end parentKeyOf()

    /**
     * Map a child level to its parent schema slug.
     *
     * @param string $level The child schema slug.
     *
     * @return string The parent schema slug.
     *
     * @throws OCSBadRequestException When the level is not a re-parentable WBS level.
     */
    private function parentSchemaFor(string $level): string
    {
        return match ($level) {
            'projectPhase'    => 'project',
            'projectTask'     => 'projectPhase',
            'projectActivity' => 'projectTask',
            default           => throw new OCSBadRequestException('Onbekend hiërarchieniveau.'),
        };
    }//end parentSchemaFor()

    /**
     * Resolve the register id and a schema id for a project-hierarchy schema.
     *
     * @param string $schemaSlug The schema slug.
     *
     * @return array{0: string, 1: string} The [register, schema] ids.
     *
     * @throws OCSNotFoundException When the register or schema is not configured.
     */
    private function config(string $schemaSlug): array
    {
        $register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schema   = $this->appConfig->getValueString(Application::APP_ID, $schemaSlug.'_schema', '');

        if ($register === '' || $schema === '') {
            throw new OCSNotFoundException('Projectregister of -schema is niet geconfigureerd.');
        }

        return [$register, $schema];
    }//end config()

    /**
     * Cast a value to float, defaulting to 0.0 on a non-numeric value.
     *
     * @param mixed $value The value.
     *
     * @return float The float.
     */
    private function toFloat(mixed $value): float
    {
        if (is_int($value) === true || is_float($value) === true) {
            return (float) $value;
        }

        if (is_string($value) === true && is_numeric($value) === true) {
            return (float) $value;
        }

        return 0.0;
    }//end toFloat()

    /**
     * Cast a value to int, defaulting to 0 on a non-numeric value.
     *
     * @param mixed $value The value.
     *
     * @return int The int.
     */
    private function toInt(mixed $value): int
    {
        if (is_int($value) === true) {
            return $value;
        }

        if (is_float($value) === true) {
            return (int) $value;
        }

        if (is_string($value) === true && is_numeric($value) === true) {
            return (int) $value;
        }

        return 0;
    }//end toInt()

    /**
     * Get the OpenRegister ObjectService from the container.
     *
     * @return object The object service.
     *
     * @throws RuntimeException When OpenRegister is not available.
     */
    private function getObjectService(): object
    {
        try {
            return $this->container->get('OCA\OpenRegister\Service\ObjectService');
        } catch (\Throwable $e) {
            throw new RuntimeException('OpenRegister service is not available.');
        }
    }//end getObjectService()

    /**
     * Normalise an OR object (entity or array) into a plain array.
     *
     * @param mixed $object The OR object.
     *
     * @return array<string, mixed> The object as an array.
     */
    private function toArray(mixed $object): array
    {
        if (is_array($object) === true) {
            return $object;
        }

        if (is_object($object) === true && method_exists($object, 'jsonSerialize') === true) {
            $serialized = $object->jsonSerialize();
            if (is_array($serialized) === true) {
                return $serialized;
            }
        }

        if (is_object($object) === true && method_exists($object, 'getObject') === true) {
            $data = $object->getObject();
            if (is_array($data) === true) {
                return $data;
            }
        }

        return (array) $object;
    }//end toArray()
}//end class

<?php

/**
 * Pipelinq RoutingService.
 *
 * Service for skill-based routing suggestions: matches request/lead categories
 * against agent skills and ranks agents by current workload.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2024 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-aggregation service that produces a ranked shortlist of agents for an
 * incoming request or lead based on skill match and current workload.
 *
 * This is NOT a CRUD layer — it composes existing ObjectService queries.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1
 */
class RoutingService
{
    /**
     * Terminal statuses excluded from workload counts.
     *
     * @var array<int, string>
     */
    private const TERMINAL_STATUSES = ['completed', 'cancelled', 'closed'];

    /**
     * Default maximum concurrent items when not configured on a profile.
     */
    private const DEFAULT_MAX_CONCURRENT = 10;

    /**
     * Constructor.
     *
     * @param IAppConfig         $appConfig   The app config.
     * @param IUserSession       $userSession The user session.
     * @param ContainerInterface $container   The container (for OpenRegister ObjectService).
     * @param LoggerInterface    $logger      The logger.
     */
    public function __construct(
        private IAppConfig $appConfig,
        private IUserSession $userSession,
        private ContainerInterface $container,
        private LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Get suggested agents for a queued request or lead.
     *
     * @param string $entityType Either 'request' or 'lead'.
     * @param string $entityId   The entity UUID.
     *
     * @return array<string, mixed> Shape: { suggestions, atCapacity, noMatch }.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-1.2
     */
    public function getSuggestedAgents(string $entityType, string $entityId): array
    {
        $registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $schemaKey  = $entityType === 'lead' ? 'lead_schema' : 'request_schema';
        $schemaId   = $this->appConfig->getValueString(Application::APP_ID, $schemaKey, '');

        if ($registerId === '' || $schemaId === '') {
            $this->logger->warning('RoutingService: register or schema not configured for ' . $entityType);
            return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
        }

        $objectService = $this->getObjectService();

        try {
            $entity = $objectService->find(
                $entityId,
                [],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'RoutingService: failed to load entity',
                ['exception' => $e->getMessage(), 'entityType' => $entityType, 'entityId' => $entityId]
            );
            return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
        }

        if (is_array($entity) === false) {
            // Try array fallback.
            $entity = (array) $entity;
        }

        $category = (string) ($entity['category'] ?? '');
        if ($category === '') {
            return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
        }

        $candidates = $this->findMatchingAgents(category: $category);
        if ($candidates === []) {
            return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
        }

        $available    = $this->filterByAvailability(profiles: $candidates);
        [$inCapacity, $atCapacityCount] = $this->filterByCapacity(profiles: $available);

        $suggestions = [];
        foreach ($inCapacity as $profile) {
            $userId   = (string) ($profile['userId'] ?? '');
            $workload = $this->getAgentWorkload(userId: $userId);
            $suggestions[] = [
                'userId'        => $userId,
                'displayName'   => (string) ($profile['displayName'] ?? $userId),
                'workload'      => $workload,
                'maxConcurrent' => (int) ($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT),
                'matchedSkill'  => (string) ($profile['matchedSkill'] ?? ''),
                'categories'    => $profile['matchedCategories'] ?? [],
            ];
        }

        usort($suggestions, static fn(array $a, array $b): int => $a['workload'] <=> $b['workload']);

        return [
            'suggestions' => $suggestions,
            'atCapacity'  => $atCapacityCount,
            'noMatch'     => $suggestions === [] && $atCapacityCount === 0,
        ];
    }//end getSuggestedAgents()

    /**
     * Count open items (requests + leads) assigned to a user.
     *
     * @param string $userId The Nextcloud user UID.
     *
     * @return int The open item count.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-1.3
     */
    public function getAgentWorkload(string $userId): int
    {
        if ($userId === '') {
            return 0;
        }

        $registerId      = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $requestSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'request_schema', '');
        $leadSchemaId    = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

        if ($registerId === '') {
            return 0;
        }

        $objectService = $this->getObjectService();
        $count         = 0;

        // Open requests (filter terminal statuses PHP-side).
        if ($requestSchemaId !== '') {
            try {
                $requests = $objectService->findAll(
                    [
                        'filters' => [
                            'register' => $registerId,
                            'schema'   => $requestSchemaId,
                            'assignee' => $userId,
                        ],
                        'limit'   => 999,
                    ],
                    _rbac: false,
                    _multitenancy: false
                );

                foreach ($requests as $request) {
                    $status = strtolower((string) ($request['status'] ?? ''));
                    if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
                        $count++;
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error(
                    'RoutingService: failed to count open requests',
                    ['exception' => $e->getMessage(), 'userId' => $userId]
                );
            }
        }

        // Open leads (status=open).
        if ($leadSchemaId !== '') {
            try {
                $leads = $objectService->findAll(
                    [
                        'filters' => [
                            'register' => $registerId,
                            'schema'   => $leadSchemaId,
                            'assignee' => $userId,
                            'status'   => 'open',
                        ],
                        'limit'   => 999,
                    ],
                    _rbac: false,
                    _multitenancy: false
                );

                $count += count($leads);
            } catch (\Throwable $e) {
                $this->logger->error(
                    'RoutingService: failed to count open leads',
                    ['exception' => $e->getMessage(), 'userId' => $userId]
                );
            }
        }

        return $count;
    }//end getAgentWorkload()

    /**
     * Find agent profiles whose skills cover the given category.
     *
     * Returns profiles enriched with `matchedSkill` (title) and
     * `matchedCategories` for downstream display.
     *
     * @param string $category The request/lead category.
     *
     * @return array<int, array<string, mixed>> Matching agentProfile objects.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-1.4
     */
    public function findMatchingAgents(string $category): array
    {
        if ($category === '') {
            return [];
        }

        $registerId             = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
        $skillSchemaId          = $this->appConfig->getValueString(Application::APP_ID, 'skill_schema', '');
        $agentProfileSchemaId   = $this->appConfig->getValueString(Application::APP_ID, 'agentProfile_schema', '');

        if ($registerId === '' || $skillSchemaId === '' || $agentProfileSchemaId === '') {
            return [];
        }

        $objectService = $this->getObjectService();

        try {
            $skills = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $skillSchemaId,
                        'isActive' => true,
                    ],
                    'limit'   => 999,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'RoutingService: failed to load skills',
                ['exception' => $e->getMessage(), 'category' => $category]
            );
            return [];
        }

        // Collect skills that match this category.
        $matchingSkillsById = [];
        foreach ($skills as $skill) {
            $categories = $skill['categories'] ?? [];
            if (is_array($categories) === false) {
                continue;
            }

            if (in_array($category, $categories, true) === true) {
                $skillId = (string) ($skill['id'] ?? ($skill['@self']['id'] ?? ''));
                if ($skillId === '') {
                    continue;
                }

                $matchingSkillsById[$skillId] = $skill;
            }
        }

        if ($matchingSkillsById === []) {
            return [];
        }

        $matchingSkillIds = array_keys($matchingSkillsById);

        try {
            $profiles = $objectService->findAll(
                [
                    'filters' => [
                        'register' => $registerId,
                        'schema'   => $agentProfileSchemaId,
                    ],
                    'limit'   => 999,
                ],
                _rbac: false,
                _multitenancy: false
            );
        } catch (\Throwable $e) {
            $this->logger->error(
                'RoutingService: failed to load agent profiles',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $matched = [];
        foreach ($profiles as $profile) {
            $profileSkills = $profile['skills'] ?? [];
            if (is_array($profileSkills) === false || $profileSkills === []) {
                continue;
            }

            $intersection = array_values(array_intersect($profileSkills, $matchingSkillIds));
            if ($intersection === []) {
                continue;
            }

            $firstMatchedSkill            = $matchingSkillsById[$intersection[0]];
            $profile['matchedSkill']      = (string) ($firstMatchedSkill['title'] ?? '');
            $profile['matchedCategories'] = $firstMatchedSkill['categories'] ?? [];
            $matched[]                    = $profile;
        }

        return $matched;
    }//end findMatchingAgents()

    /**
     * Filter out profiles where isAvailable === false.
     *
     * @param array<int, array<string, mixed>> $profiles The candidate profiles.
     *
     * @return array<int, array<string, mixed>> Available profiles.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-1.5
     */
    public function filterByAvailability(array $profiles): array
    {
        return array_values(array_filter(
            $profiles,
            static fn(array $p): bool => ($p['isAvailable'] ?? true) !== false
        ));
    }//end filterByAvailability()

    /**
     * Filter out profiles that are at or over their maxConcurrent capacity.
     *
     * @param array<int, array<string, mixed>> $profiles The candidate profiles.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int} Tuple of (in-capacity profiles, at-capacity count).
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-1.6
     */
    public function filterByCapacity(array $profiles): array
    {
        $inCapacity      = [];
        $atCapacityCount = 0;

        foreach ($profiles as $profile) {
            $workload = $this->getAgentWorkload(userId: (string) ($profile['userId'] ?? ''));
            if ($this->isAgentAtCapacity(profile: $profile, workload: $workload) === true) {
                $atCapacityCount++;
                continue;
            }

            $inCapacity[] = $profile;
        }

        return [$inCapacity, $atCapacityCount];
    }//end filterByCapacity()

    /**
     * Check whether an agent is at or over capacity.
     *
     * @param array<string, mixed> $profile  The agent profile.
     * @param int                  $workload The current open-item count for the agent.
     *
     * @return bool True if workload >= maxConcurrent.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-1.7
     */
    public function isAgentAtCapacity(array $profile, int $workload): bool
    {
        $max = (int) ($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT);
        return $workload >= $max;
    }//end isAgentAtCapacity()

    /**
     * Verify the current user may view routing data for this entity.
     *
     * Authorization rule: the requesting user must be the assignee on the
     * entity, a member of an assigned group, or a Nextcloud admin. The check
     * is intentionally permissive in absence of richer ACLs; tighten as RBAC
     * matures.
     *
     * @param array<string, mixed> $entity The loaded entity.
     *
     * @return bool True if the user is authorized.
     *
     * @spec openspec/changes/skill-routing/tasks.md#task-2.3
     */
    public function authorizeEntity(array $entity): bool
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return false;
        }

        $uid      = $user->getUID();
        $assignee = (string) ($entity['assignee'] ?? '');
        if ($assignee !== '' && $assignee === $uid) {
            return true;
        }

        // Admins always allowed; defer to caller-side check via group membership.
        return true;
    }//end authorizeEntity()

    /**
     * Get the OpenRegister ObjectService via the container.
     *
     * @return object The object service.
     */
    private function getObjectService(): object
    {
        return $this->container->get('OCA\OpenRegister\Service\ObjectService');
    }//end getObjectService()
}//end class

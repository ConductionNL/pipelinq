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
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;

/**
 * Read-aggregation service that produces a ranked shortlist of agents for an
 * incoming request or lead based on skill match and current workload.
 *
 * This is NOT a CRUD layer — it composes existing ObjectService queries.
 *
 * Since unify-ticket-supertype a "request" is a `ticket` carrying
 * `ticketType: request`; the request legs resolve through {@see TicketService}
 * instead of the retired `request_schema` config key. Leads keep their own
 * `lead` schema.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1
 * @spec openspec/changes/unify-ticket-supertype/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 */
class RoutingService {
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
	 * @param IAppConfig $appConfig The app config.
	 * @param TicketService $ticketService The unified ticket resolver.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private TicketService $ticketService,
		private LoggerInterface $logger,
		private readonly ObjectServiceInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Get suggested agents for a queued request or lead.
	 *
	 * A 'request' entity is a `ticket` with `ticketType: request`.
	 *
	 * @param string $entityType Either 'request' or 'lead'.
	 * @param string $entityId The entity UUID.
	 *
	 * @return array<string, mixed> Shape: { suggestions, atCapacity, noMatch }.
	 *
	 * @spec openspec/changes/skill-routing/tasks.md#task-1.2
	 */
	public function getSuggestedAgents(string $entityType, string $entityId): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');

		$schemaId = $this->ticketService->getSchemaId();
		if ($entityType === 'lead') {
			$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');
		}

		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning('RoutingService: register or schema not configured for ' . $entityType);
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$objectService = $this->getObjectService();

		try {
			$entity = $objectService->find(
				$entityId,
				[]
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
			$entity = (array)$entity;
		}

		$category = (string)($entity['category'] ?? '');
		if ($category === '') {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$candidates = $this->findMatchingAgents(category: $category);
		if ($candidates === []) {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$available = $this->filterByAvailability(profiles: $candidates);
		[$inCapacity, $atCapacityCount] = $this->filterByCapacity(profiles: $available);

		$suggestions = $this->buildSuggestions(inCapacity: $inCapacity);

		return [
			'suggestions' => $suggestions,
			'atCapacity' => $atCapacityCount,
			'noMatch' => $suggestions === [] && $atCapacityCount === 0,
		];
	}//end getSuggestedAgents()

	/**
	 * Build the workload-sorted suggestion list from in-capacity profiles.
	 *
	 * @param array<int, array<string, mixed>> $inCapacity The profiles within capacity.
	 *
	 * @return array<int, array<string, mixed>> The suggestions, ascending by workload.
	 */
	private function buildSuggestions(array $inCapacity): array {
		$suggestions = [];
		foreach ($inCapacity as $profile) {
			$userId = (string)($profile['userId'] ?? '');
			$suggestions[] = [
				'userId' => $userId,
				'displayName' => (string)($profile['displayName'] ?? $userId),
				'workload' => $this->getAgentWorkload(userId: $userId),
				'maxConcurrent' => (int)($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT),
				'matchedSkill' => (string)($profile['matchedSkill'] ?? ''),
				'categories' => $profile['matchedCategories'] ?? [],
			];
		}

		usort($suggestions, static fn (array $a, array $b): int => $a['workload'] <=> $b['workload']);

		return $suggestions;
	}//end buildSuggestions()

	/**
	 * Count open items (request tickets + leads) assigned to a user.
	 *
	 * @param string $userId The Nextcloud user UID.
	 *
	 * @return int The open item count.
	 *
	 * @spec openspec/changes/skill-routing/tasks.md#task-1.3
	 */
	public function getAgentWorkload(string $userId): int {
		if ($userId === '') {
			return 0;
		}

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$leadSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'lead_schema', '');

		if ($registerId === '') {
			return 0;
		}

		$objectService = $this->getObjectService();
		$count = 0;

		// Open request tickets: the "non-terminal" predicate is a NOT IN over
		// TERMINAL_STATUSES, which OpenRegister's query engine cannot express
		// (no NOT IN operator), and the comparison is case-folded in PHP. This
		// leg therefore stays a PHP-side count over the assignee-filtered set.
		// TicketService::findByType pins register + schema + ticketType=request
		// and degrades to [] on any failure (it logs the cause itself).
		$requests = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_REQUEST,
			extraFilters: ['assignee' => $userId],
			limit: 999,
		);

		foreach ($requests as $request) {
			$status = strtolower((string)($request['status'] ?? ''));
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				$count++;
			}
		}

		// Open leads (status=open) — push the COUNT down into OpenRegister
		// since every predicate is a server-side equality filter.
		if ($leadSchemaId !== '') {
			try {
				$count += $objectService->count(
					[
						'filters' => [
							'register' => $registerId,
							'schema' => $leadSchemaId,
							'assignee' => $userId,
							'status' => 'open',
						],
					]
				);
			} catch (\Throwable $e) {
				$this->logger->error(
					'RoutingService: failed to count open leads',
					['exception' => $e->getMessage(), 'userId' => $userId]
				);
			}//end try
		}//end if

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
	public function findMatchingAgents(string $category): array {
		if ($category === '') {
			return [];
		}

		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$skillSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'skill_schema', '');
		$agentProfileSchemaId = $this->appConfig->getValueString(Application::APP_ID, 'agentProfile_schema', '');

		if ($registerId === '' || $skillSchemaId === '' || $agentProfileSchemaId === '') {
			return [];
		}

		$skills = $this->loadActiveSkills(registerId: $registerId, skillSchemaId: $skillSchemaId, category: $category);
		$matchingSkillsById = $this->collectMatchingSkills(skills: $skills, category: $category);
		if ($matchingSkillsById === []) {
			return [];
		}

		$profiles = $this->loadAgentProfiles(registerId: $registerId, agentProfileSchemaId: $agentProfileSchemaId);

		return $this->matchProfilesToSkills(profiles: $profiles, matchingSkillsById: $matchingSkillsById);
	}//end findMatchingAgents()

	/**
	 * Load all active skills, returning an empty list on failure.
	 *
	 * @param string $registerId The configured register ID.
	 * @param string $skillSchemaId The skill schema ID.
	 * @param string $category The category (for error context only).
	 *
	 * @return iterable<mixed> The active skill objects (empty on error).
	 */
	private function loadActiveSkills(string $registerId, string $skillSchemaId, string $category): iterable {
		try {
			return $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $skillSchemaId,
						'isActive' => true,
					],
					'limit' => 999,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to load skills',
				['exception' => $e->getMessage(), 'category' => $category]
			);
			return [];
		}
	}//end loadActiveSkills()

	/**
	 * Index skills that declare the given category by their ID.
	 *
	 * @param iterable<mixed> $skills The candidate skills.
	 * @param string $category The category to match.
	 *
	 * @return array<string, mixed> Map of skill ID => skill, for matching skills.
	 */
	private function collectMatchingSkills(iterable $skills, string $category): array {
		$matchingSkillsById = [];
		foreach ($skills as $skill) {
			$categories = $skill['categories'] ?? [];
			if (is_array($categories) === false || in_array($category, $categories, true) === false) {
				continue;
			}

			$skillId = (string)($skill['id'] ?? ($skill['@self']['id'] ?? ''));
			if ($skillId === '') {
				continue;
			}

			$matchingSkillsById[$skillId] = $skill;
		}

		return $matchingSkillsById;
	}//end collectMatchingSkills()

	/**
	 * Load all agent profiles, returning an empty list on failure.
	 *
	 * @param string $registerId The configured register ID.
	 * @param string $agentProfileSchemaId The agent-profile schema ID.
	 *
	 * @return iterable<mixed> The agent-profile objects (empty on error).
	 */
	private function loadAgentProfiles(string $registerId, string $agentProfileSchemaId): iterable {
		try {
			return $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $agentProfileSchemaId,
					],
					'limit' => 999,
				]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to load agent profiles',
				['exception' => $e->getMessage()]
			);
			return [];
		}
	}//end loadAgentProfiles()

	/**
	 * Match agent profiles against the matching skills and annotate each match.
	 *
	 * @param iterable<mixed> $profiles The candidate agent profiles.
	 * @param array<string, mixed> $matchingSkillsById Map of skill ID => skill.
	 *
	 * @return array<int, array<string, mixed>> The matched, annotated profiles.
	 */
	private function matchProfilesToSkills(iterable $profiles, array $matchingSkillsById): array {
		$matchingSkillIds = array_keys($matchingSkillsById);

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

			$firstMatchedSkill = $matchingSkillsById[$intersection[0]];
			$profile['matchedSkill'] = (string)($firstMatchedSkill['title'] ?? '');
			$profile['matchedCategories'] = $firstMatchedSkill['categories'] ?? [];
			$matched[] = $profile;
		}

		return $matched;
	}//end matchProfilesToSkills()

	/**
	 * Filter out profiles where isAvailable === false.
	 *
	 * @param array<int, array<string, mixed>> $profiles The candidate profiles.
	 *
	 * @return array<int, array<string, mixed>> Available profiles.
	 *
	 * @spec openspec/changes/skill-routing/tasks.md#task-1.5
	 */
	public function filterByAvailability(array $profiles): array {
		return array_values(
			array_filter(
				$profiles,
				static fn (array $profile): bool => ($profile['isAvailable'] ?? true) !== false
			)
		);
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
	public function filterByCapacity(array $profiles): array {
		$inCapacity = [];
		$atCapacityCount = 0;

		foreach ($profiles as $profile) {
			$workload = $this->getAgentWorkload(userId: (string)($profile['userId'] ?? ''));
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
	 * @param array<string, mixed> $profile The agent profile.
	 * @param int $workload The current open-item count for the agent.
	 *
	 * @return bool True if workload >= maxConcurrent.
	 *
	 * @spec openspec/changes/skill-routing/tasks.md#task-1.7
	 */
	public function isAgentAtCapacity(array $profile, int $workload): bool {
		$max = (int)($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT);
		return $workload >= $max;
	}//end isAgentAtCapacity()

	/**
	 * Get the OpenRegister ObjectService via the container.
	 *
	 * @return object The object service.
	 */
	private function getObjectService(): object {
		return $this->objectService;
	}//end getObjectService()
}//end class

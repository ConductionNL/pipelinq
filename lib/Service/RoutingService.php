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
 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Kernel\AppIdentity;
use OCA\Pipelinq\Kernel\Contracts\RoutingServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

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
 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1
 * @spec openspec/specs/unify-ticket-supertype/spec.md#requirement-create-surfaces-write-tickets
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The total is the sum of small private steps; each method stays under the method thresholds.
 */
class RoutingService implements RoutingServiceInterface {
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
	 * @param ObjectServiceInterface $objectService OpenRegister's published object service.
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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.2
	 */
	public function getSuggestedAgents(string $entityType, string $entityId): array {
		if ($this->isRoutingConfigured(entityType: $entityType) === false) {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$data = $this->loadEntityData(entityType: $entityType, entityId: $entityId);
		if ($data === null) {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		$candidates = $this->candidatesFor(data: $data);
		if ($candidates === []) {
			return ['suggestions' => [], 'atCapacity' => 0, 'noMatch' => true];
		}

		return $this->rankCandidates(candidates: $candidates);
	}//end getSuggestedAgents()

	/**
	 * Whether the register and the entity type's schema are configured (logs when not).
	 *
	 * @param string $entityType The entity type (`lead` or a ticket).
	 *
	 * @return bool True when routing can look up the entity.
	 */
	private function isRoutingConfigured(string $entityType): bool {
		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');

		$schemaId = $this->ticketService->getSchemaId();
		if ($entityType === 'lead') {
			$schemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'lead_schema', '');
		}

		if ($registerId === '' || $schemaId === '') {
			$this->logger->warning('RoutingService: register or schema not configured for ' . $entityType);
			return false;
		}

		return true;
	}//end isRoutingConfigured()

	/**
	 * Load the entity's object data, or null (logged on failure) when it cannot be read.
	 *
	 * @param string $entityType The entity type.
	 * @param string $entityId The entity id.
	 *
	 * @return array<string, mixed>|null The entity data.
	 */
	private function loadEntityData(string $entityType, string $entityId): array|null {
		$objectService = $this->getObjectService();

		try {
			$entity = $objectService->find($entityId, []);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to load entity',
				['exception' => $e->getMessage(), 'entityType' => $entityType, 'entityId' => $entityId]
			);
			return null;
		}

		// ObjectServiceInterface::find() returns an ObjectEntityInterface, so a
		// plain `(array)$entity` cast produced the MANGLED private-property keys
		// of the entity object and `category` was therefore always '' — every
		// suggestion request short-circuited to noMatch. Read the payload
		// through the entity contract instead.
		if ($entity === null) {
			return null;
		}

		return $entity->getObject();
	}//end loadEntityData()

	/**
	 * The agents matching the entity's category; none when it has no category.
	 *
	 * @param array<string, mixed> $data The entity data.
	 *
	 * @return array<int, mixed> The candidate agent profiles.
	 */
	private function candidatesFor(array $data): array {
		$category = (string)($data['category'] ?? '');
		if ($category === '') {
			return [];
		}

		return $this->findMatchingAgents(category: $category);
	}//end candidatesFor()

	/**
	 * Filter candidates by availability and capacity and build the suggestions.
	 *
	 * @param array<int, mixed> $candidates The candidate agent profiles.
	 *
	 * @return array{suggestions: array, atCapacity: int, noMatch: bool} The routing suggestions.
	 */
	private function rankCandidates(array $candidates): array {
		$available = $this->filterByAvailability(profiles: $candidates);
		[$inCapacity, $atCapacityCount] = $this->filterByCapacity(profiles: $available);

		$suggestions = $this->buildSuggestions(inCapacity: $inCapacity);

		return [
			'suggestions' => $suggestions,
			'atCapacity' => $atCapacityCount,
			'noMatch' => $suggestions === [] && $atCapacityCount === 0,
		];
	}//end rankCandidates()

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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.3
	 */
	public function getAgentWorkload(string $userId): int {
		if ($userId === '') {
			return 0;
		}

		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$leadSchemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'lead_schema', '');

		if ($registerId === '') {
			return 0;
		}

		$objectService = $this->getObjectService();

		return $this->openRequestCount(userId: $userId)
			+ $this->openLeadCount(objectService: $objectService, registerId: $registerId, leadSchemaId: $leadSchemaId, userId: $userId);
	}//end getAgentWorkload()

	/**
	 * Count the agent's open (non-terminal) request tickets.
	 *
	 * The "non-terminal" predicate is a NOT IN over TERMINAL_STATUSES, which
	 * OpenRegister's query engine cannot express (no NOT IN operator), and the
	 * comparison is case-folded in PHP. This leg therefore stays a PHP-side
	 * count over the assignee-filtered set. TicketService::findByType pins
	 * register + schema + ticketType=request and degrades to [] on any failure
	 * (it logs the cause itself).
	 *
	 * @param string $userId The agent's user id.
	 *
	 * @return int The number of open requests.
	 */
	private function openRequestCount(string $userId): int {
		$count = 0;
		$requests = $this->ticketService->findByType(
			ticketType: TicketService::TYPE_REQUEST,
			extraFilters: ['assignee' => $userId],
			limit: 999,
		);

		foreach ($requests as $request) {
			$status = strtolower((string)($this->toArray(value: $request)['status'] ?? ''));
			if (in_array($status, self::TERMINAL_STATUSES, true) === false) {
				$count++;
			}
		}

		return $count;
	}//end openRequestCount()

	/**
	 * Count the agent's open leads server-side; 0 when no lead schema is set or the count fails (logged).
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $registerId The register id.
	 * @param string $leadSchemaId The lead schema id.
	 * @param string $userId The agent's user id.
	 *
	 * @return int The number of open leads.
	 */
	private function openLeadCount(object $objectService, string $registerId, string $leadSchemaId, string $userId): int {
		if ($leadSchemaId === '') {
			return 0;
		}

		try {
			return $objectService->count(
				['filters' => ['register' => $registerId, 'schema' => $leadSchemaId, 'assignee' => $userId, 'status' => 'open']]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'RoutingService: failed to count open leads',
				['exception' => $e->getMessage(), 'userId' => $userId]
			);
			return 0;
		}
	}//end openLeadCount()

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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.4
	 */
	public function findMatchingAgents(string $category): array {
		if ($category === '') {
			return [];
		}

		$registerId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'register', '');
		$skillSchemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'skill_schema', '');
		$agentProfileSchemaId = $this->appConfig->getValueString(AppIdentity::APP_ID, 'agentProfile_schema', '');

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
	 * @return array<int, array<string, mixed>> The active skills as plain arrays (empty on error).
	 */
	private function loadActiveSkills(string $registerId, string $skillSchemaId, string $category): array {
		try {
			$skills = $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $skillSchemaId,
						'isActive' => true,
					],
					'limit' => 999,
				]
			);
			return array_map(fn ($skill): array => $this->toArray(value: $skill), $skills);
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
	 * @param array<int, array<string, mixed>> $skills The candidate skills.
	 * @param string $category The category to match.
	 *
	 * @return array<string, mixed> Map of skill ID => skill, for matching skills.
	 */
	private function collectMatchingSkills(array $skills, string $category): array {
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
	 * @return array<int, array<string, mixed>> The agent profiles as plain arrays (empty on error).
	 */
	private function loadAgentProfiles(string $registerId, string $agentProfileSchemaId): array {
		try {
			$profiles = $this->getObjectService()->findAll(
				[
					'filters' => [
						'register' => $registerId,
						'schema' => $agentProfileSchemaId,
					],
					'limit' => 999,
				]
			);
			return array_map(fn ($profile): array => $this->toArray(value: $profile), $profiles);
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
	 * @param array<int, array<string, mixed>> $profiles The candidate agent profiles.
	 * @param array<string, mixed> $matchingSkillsById Map of skill ID => skill.
	 *
	 * @return array<int, array<string, mixed>> The matched, annotated profiles.
	 */
	private function matchProfilesToSkills(array $profiles, array $matchingSkillsById): array {
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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.5
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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.6
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
	 * @spec openspec/changes/archive/2026-05-11-skill-routing/tasks.md#task-1.7
	 */
	public function isAgentAtCapacity(array $profile, int $workload): bool {
		$max = (int)($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT);
		return $workload >= $max;
	}//end isAgentAtCapacity()

	/**
	 * Normalise an OpenRegister entity to a plain array.
	 *
	 * OpenRegister's findAll() returns ObjectEntity instances, which cannot be read with
	 * array access.
	 *
	 * @param mixed $value Entity or array.
	 *
	 * @return array<string, mixed> Plain payload.
	 */
	private function toArray(mixed $value): array {
		if (is_object($value) === true && method_exists($value, 'jsonSerialize') === true) {
			$value = $value->jsonSerialize();
		}

		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end toArray()

	/**
	 * Get the injected OpenRegister ObjectService.
	 *
	 * Typed against the CONTRACT rather than the bare `object` it used to
	 * return, so static analysis can see that `find()` yields a nullable
	 * ObjectEntityInterface — which is how the `(array)$entity` bug above went
	 * unnoticed for as long as it did.
	 *
	 * @return ObjectServiceInterface The object service.
	 */
	private function getObjectService(): ObjectServiceInterface {
		return $this->objectService;
	}//end getObjectService()
}//end class

<?php

/**
 * Pipelinq TeamLoadService.
 *
 * The team's load for the queue's Team today card: every agent profile with
 * the open items assigned to them against their capacity.
 *
 * @category Service
 * @package  OCA\Pipelinq\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the agent profiles and counts each agent's open items through
 * {@see RoutingService::getAgentWorkload()}, the same count routing ranks by.
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1.3
 */
class TeamLoadService {

	/**
	 * Capacity of a profile that sets no maxConcurrent, as routing reads it.
	 *
	 * @var integer
	 */
	private const DEFAULT_MAX_CONCURRENT = 10;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig             $appConfig     The app config.
	 * @param RoutingService         $routing       The workload count and the capacity rule.
	 * @param ObjectServiceInterface $objectService OpenRegister's object service.
	 * @param IUserManager           $userManager   Names a colleague whose profile has no display name.
	 * @param LoggerInterface        $logger        The logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly RoutingService $routing,
		private readonly ObjectServiceInterface $objectService,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The team's load: one row per colleague (the first agent profile of a
	 * user id), sorted by name.
	 *
	 * Each row holds the user id and name, whether the agent is available,
	 * their open request tickets and leads, their maxConcurrent and whether
	 * the open count has reached it. Empty when the register or the agent
	 * profile schema is not configured, or the profiles cannot be read.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/skill-routing/tasks.md#task-1.3
	 * @spec openspec/changes/team-today-names-colleagues/specs/skill-routing/spec.md#requirement-team-today-shows-each-colleague-once-by-name
	 */
	public function getTeamLoad(): array {
		$rows = [];
		foreach ($this->loadProfiles() as $profile) {
			$userId = (string) ($profile['userId'] ?? '');
			if ($userId === '' || isset($rows[$userId]) === true) {
				continue;
			}

			$open = $this->routing->getAgentWorkload(userId: $userId);
			$rows[$userId] = [
				'userId' => $userId,
				'displayName' => $this->displayName(profile: $profile, userId: $userId),
				'isAvailable' => ($profile['isAvailable'] ?? true) !== false,
				'open' => $open,
				'maxConcurrent' => (int) ($profile['maxConcurrent'] ?? self::DEFAULT_MAX_CONCURRENT),
				'atCapacity' => $this->routing->isAgentAtCapacity(profile: $profile, workload: $open),
			];
		}

		$rows = array_values($rows);
		usort($rows, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

		return $rows;
	}//end getTeamLoad()

	/**
	 * The name a colleague shows under: the profile's display name, else the
	 * Nextcloud user's, else the user id.
	 *
	 * @param array<string, mixed> $profile The agent profile.
	 * @param string               $userId  The profile's user id.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/team-today-names-colleagues/specs/skill-routing/spec.md#requirement-team-today-shows-each-colleague-once-by-name
	 */
	private function displayName(array $profile, string $userId): string {
		$name = (string) ($profile['displayName'] ?? '');
		if ($name !== '') {
			return $name;
		}

		return $this->userManager->getDisplayName($userId) ?? $userId;
	}//end displayName()

	/**
	 * Every agent profile as a plain array.
	 *
	 * @return array<int, array<string, mixed>> The profiles, empty when unconfigured or unreadable.
	 */
	private function loadProfiles(): array {
		$registerId = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schemaId = $this->appConfig->getValueString(Application::APP_ID, 'agentProfile_schema', '');
		if ($registerId === '' || $schemaId === '') {
			return [];
		}

		try {
			$profiles = $this->objectService->findAll(
				config: ['filters' => ['register' => $registerId, 'schema' => $schemaId], 'limit' => 999]
			);
		} catch (Throwable $e) {
			$this->logger->error('TeamLoadService: failed to load agent profiles', ['exception' => $e->getMessage()]);
			return [];
		}

		$rows = [];
		foreach ($profiles as $profile) {
			if (is_object($profile) === true && method_exists($profile, 'jsonSerialize') === true) {
				$profile = $profile->jsonSerialize();
			}

			if (is_array($profile) === true) {
				$rows[] = $profile;
			}
		}

		return $rows;
	}//end loadProfiles()
}//end class

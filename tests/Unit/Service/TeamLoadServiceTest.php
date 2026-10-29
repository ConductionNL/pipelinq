<?php

/**
 * Unit tests for TeamLoadService (the queue's Team today card).
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/skill-routing/tasks.md#task-1.3
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\Pipelinq\Service\RoutingService;
use OCA\Pipelinq\Service\TeamLoadService;
use OCP\IAppConfig;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Every agent profile becomes a row with its open items against its capacity.
 */
class TeamLoadServiceTest extends TestCase {

	/**
	 * Build the service.
	 *
	 * @param array<string, string>                 $config    App config values by key.
	 * @param array<int, array<string, mixed>>|null $profiles  What findAll returns, or null to make it throw.
	 * @param array<string, int>                    $workloads Open items by user id.
	 * @param array<string, string>                 $names     Nextcloud display names by user id.
	 *
	 * @return TeamLoadService The service.
	 */
	private function service(array $config, ?array $profiles, array $workloads, array $names = []): TeamLoadService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $config[$key] ?? $default
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		if ($profiles === null) {
			$objects->method('findAll')->willThrowException(new RuntimeException('down'));
		} else {
			$objects->method('findAll')->willReturn($profiles);
		}

		$routing = $this->createMock(RoutingService::class);
		$routing->method('getAgentWorkload')->willReturnCallback(
			static fn (string $userId): int => $workloads[$userId] ?? 0
		);
		$routing->method('isAgentAtCapacity')->willReturnCallback(
			static fn (array $profile, int $workload): bool => $workload >= (int) ($profile['maxConcurrent'] ?? 10)
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(
			static fn (string $uid): ?string => $names[$uid] ?? null
		);

		return new TeamLoadService(
			appConfig: $appConfig,
			routing: $routing,
			objectService: $objects,
			userManager: $users,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end service()

	/**
	 * Rows are sorted by name and carry availability, open count, capacity and whether it is reached.
	 *
	 * @return void
	 */
	public function testRowsPerProfile(): void {
		$service = $this->service(
			config: ['register' => 'pipelinq', 'agentProfile_schema' => 'agentProfile'],
			profiles: [
				['userId' => 'lotte', 'displayName' => 'Lotte Mulder', 'maxConcurrent' => 8],
				['userId' => 'fatma', 'displayName' => 'Fatma Yildiz', 'isAvailable' => false],
				['displayName' => 'No user'],
			],
			workloads: ['lotte' => 9, 'fatma' => 4]
		);

		$this->assertSame(
			expected: [
				[
					'userId' => 'fatma',
					'displayName' => 'Fatma Yildiz',
					'isAvailable' => false,
					'open' => 4,
					'maxConcurrent' => 10,
					'atCapacity' => false,
				],
				[
					'userId' => 'lotte',
					'displayName' => 'Lotte Mulder',
					'isAvailable' => true,
					'open' => 9,
					'maxConcurrent' => 8,
					'atCapacity' => true,
				],
			],
			actual: $service->getTeamLoad()
		);
	}//end testRowsPerProfile()

	/**
	 * Without the agent profile schema, or when the profiles cannot be read, there is no team to show.
	 *
	 * @return void
	 */
	public function testUnconfiguredOrUnreadableIsEmpty(): void {
		$this->assertSame(
			expected: [],
			actual: $this->service(config: ['register' => 'pipelinq'], profiles: [['userId' => 'x']], workloads: [])->getTeamLoad()
		);
		$this->assertSame(
			expected: [],
			actual: $this->service(config: ['register' => 'pipelinq', 'agentProfile_schema' => 'agentProfile'], profiles: null, workloads: [])->getTeamLoad()
		);
	}//end testUnconfiguredOrUnreadableIsEmpty()

	/**
	 * A profile without a display name shows the colleague's Nextcloud name, not the user id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-today-names-colleagues/specs/skill-routing/spec.md#requirement-team-today-shows-each-colleague-once-by-name
	 */
	public function testNameFromTheNextcloudUser(): void {
		$service = $this->service(
			config: ['register' => 'pipelinq', 'agentProfile_schema' => 'agentProfile'],
			profiles: [
				['userId' => 'fatma.yildiz'],
				['userId' => 'gone'],
			],
			workloads: [],
			names: ['fatma.yildiz' => 'Fatma Yildiz']
		);

		$this->assertSame(
			expected: ['Fatma Yildiz', 'gone'],
			actual: array_column($service->getTeamLoad(), 'displayName')
		);
	}//end testNameFromTheNextcloudUser()

	/**
	 * A colleague with two agent profiles is one row: the first profile read.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/team-today-names-colleagues/specs/skill-routing/spec.md#requirement-team-today-shows-each-colleague-once-by-name
	 */
	public function testOneRowPerColleague(): void {
		$service = $this->service(
			config: ['register' => 'pipelinq', 'agentProfile_schema' => 'agentProfile'],
			profiles: [
				['userId' => 'fatma', 'displayName' => 'Fatma Yildiz', 'maxConcurrent' => 6],
				['userId' => 'fatma', 'displayName' => 'Fatma Yildiz', 'maxConcurrent' => 12],
			],
			workloads: ['fatma' => 8]
		);

		$rows = $service->getTeamLoad();
		$this->assertCount(expectedCount: 1, haystack: $rows);
		$this->assertSame(expected: 6, actual: $rows[0]['maxConcurrent']);
	}//end testOneRowPerColleague()
}//end class

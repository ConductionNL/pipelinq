<?php

/**
 * Unit tests for RoutingService::getTeamLoad() (the queue's team card).
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
use OCA\Pipelinq\Service\TicketService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every agent profile becomes a row with its open items against its capacity.
 */
class RoutingServiceTeamLoadTest extends TestCase {

	/**
	 * Build the service with the given config values, profiles and workloads.
	 *
	 * @param array<string, string>            $config    App config values by key.
	 * @param array<int, array<string, mixed>> $profiles  The agent profiles findAll returns.
	 * @param array<string, int>               $workloads Open items by user id.
	 *
	 * @return RoutingService The service, with getAgentWorkload answered from $workloads.
	 */
	private function service(array $config, array $profiles, array $workloads): RoutingService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default): string => $config[$key] ?? $default
		);
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturn($profiles);

		$service = $this->getMockBuilder(RoutingService::class)
			->setConstructorArgs(
				[
					$appConfig,
					$this->createMock(TicketService::class),
					$this->createMock(LoggerInterface::class),
					$objects,
				]
			)
			->onlyMethods(['getAgentWorkload'])
			->getMock();
		$service->method('getAgentWorkload')->willReturnCallback(
			static fn (string $userId): int => $workloads[$userId] ?? 0
		);

		return $service;
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
	 * Without the agent profile schema there is no team to show.
	 *
	 * @return void
	 */
	public function testUnconfiguredIsEmpty(): void {
		$service = $this->service(config: ['register' => 'pipelinq'], profiles: [['userId' => 'x']], workloads: []);

		$this->assertSame(expected: [], actual: $service->getTeamLoad());
	}//end testUnconfiguredIsEmpty()
}//end class

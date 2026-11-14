<?php

/**
 * Unit tests for the walk-in queue number: a new ticket gets the next number
 * of the day, and the numbers start again at 1 each day.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/specs/appointment-booking/spec.md#requirement-a-walk-in-visitor-gets-a-queue-number
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\AvailabilityService;
use OCA\Pipelinq\Service\WalkInQueueService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Pipelinq\Service\WalkInQueueService
 */
class WalkInQueueNumberTest extends TestCase {

	/**
	 * What saveObject() was given, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	/**
	 * The config findAll() was given, per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $queried = [];

	/**
	 * A service over a store holding these tickets; a Throwable makes the read fail.
	 *
	 * @param array<int, array<string, mixed>>|\Throwable $tickets The stored tickets.
	 *
	 * @return WalkInQueueService
	 */
	private function service(array|\Throwable $tickets): WalkInQueueService {
		$objects = $this->createMock(ObjectServiceInterface::class);
		$objects->method('findAll')->willReturnCallback(
			function (array $config) use ($tickets): array {
				$this->queried[] = $config;
				if ($tickets instanceof \Throwable) {
					throw $tickets;
				}

				return array_map(static function (array $row): ObjectEntity {
					$entity = new ObjectEntity();
					$entity->setUuid((string)($row['id'] ?? ''));
					$entity->setObject($row);
					return $entity;
				}, $tickets);
			}
		);
		$objects->method('saveObject')->willReturnCallback(
			function (array|object $payload): ObjectEntityInterface {
				$this->saved[] = (array)$payload;
				$entity = new ObjectEntity();
				$entity->setUuid('t-' . count($this->saved));
				$entity->setObject((array)$payload);
				return $entity;
			}
		);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ['register' => 'pipelinq', 'walkInTicket_schema' => 'walkInTicket'][$key] ?? $default
		);

		return new WalkInQueueService(
			appConfig: $appConfig,
			availabilityService: $this->createMock(AvailabilityService::class),
			logger: $this->createMock(LoggerInterface::class),
			objectService: $objects,
		);
	}//end service()

	public function testTheFirstVisitorOfTheDayGetsNumberOne(): void {
		$this->service([])->createTicket(['displayName' => 'Anna']);

		$this->assertSame(1, $this->saved[0]['queueNumber']);
		$this->assertSame(['arrivedAt' => ['gte' => gmdate('Y-m-d') . 'T00:00:00+00:00']], $this->queried[0]['filters']);
		$this->assertSame(['arrivedAt' => 'DESC'], $this->queried[0]['order']);
	}

	public function testTheNextVisitorGetsOneMoreThanTheHighestNumberToday(): void {
		$today = gmdate('Y-m-d');
		$this->service([
			['id' => 'a', 'arrivedAt' => $today . 'T09:00:00+00:00', 'queueNumber' => 1],
			['id' => 'b', 'arrivedAt' => $today . 'T09:05:00+00:00', 'queueNumber' => 2, 'status' => 'called'],
			['id' => 'c', 'arrivedAt' => $today . 'T09:06:00+00:00'],
		])->createTicket(['displayName' => 'Bram']);

		$this->assertSame(3, $this->saved[0]['queueNumber']);
	}

	public function testYesterdaysNumbersDoNotCarryOverEvenWhenTheFilterIsIgnored(): void {
		$yesterday = gmdate('Y-m-d', time() - 86400);
		$this->service([
			['id' => 'old', 'arrivedAt' => $yesterday . 'T16:00:00+00:00', 'queueNumber' => 41],
		])->createTicket(['displayName' => 'Anna']);

		$this->assertSame(1, $this->saved[0]['queueNumber']);
	}

	public function testAFailedLookupStillSeatsTheVisitorAsNumberOne(): void {
		$uuid = $this->service(new RuntimeException('index offline'))->createTicket(['displayName' => 'Anna']);

		$this->assertSame('t-1', $uuid);
		$this->assertSame(1, $this->saved[0]['queueNumber']);
		$this->assertSame('waiting', $this->saved[0]['status']);
	}
}

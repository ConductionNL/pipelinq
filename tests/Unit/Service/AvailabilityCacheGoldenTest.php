<?php

/**
 * Golden test for the cache-miss path of AvailabilityService::getOrComputeCache().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git_id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Service;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\ICacheFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * On a cache miss: the answer, the cache row written (its timestamps as
 * whether they lie a day apart and match the answer) and the warning on a
 * failed write, with and without a configured cache schema, compared with
 * AvailabilityCacheGolden.json. Set AVAILABILITY_CACHE_GOLDEN_RECORD=1 to
 * rewrite the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Service\AvailabilityService
 * @uses   \OCA\Pipelinq\Util\SerialisedPayload
 * @uses \OCA\Pipelinq\Util\RowIdOf
 * @uses \OCA\Pipelinq\Util\RowIdentity
 * @uses \OCA\Pipelinq\Util\EntityIdOf
 * @uses \OCA\Pipelinq\Util\NullableRowIdOf
 * @uses \OCA\Pipelinq\Util\PayloadToArray
 * @uses \OCA\Pipelinq\Util\CastingPayloadToArray
 * @uses \OCA\Pipelinq\Util\OptionalPayloadToArray
 * @uses \OCA\Pipelinq\Util\AnyPayloadToArray
 * @uses \OCA\Pipelinq\Util\EntityPayload
 * @uses \OCA\Pipelinq\Util\NullablePayloadToArray
 * @uses \OCA\Pipelinq\Util\SerialisedToArray
 *
 * @spec openspec/specs/appointment-booking/spec.md
 */
class AvailabilityCacheGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/AvailabilityCacheGolden.json';

	/**
	 * Saves and warnings in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $events = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$cases = [
			'monday, 30 minutes'   => ['res-sarah', '2026-06-01', 30, []],
			'tuesday, default'     => ['res-sarah', '2026-06-02', null, []],
			'sunday'               => ['res-sarah', '2026-06-07', 60, []],
			'no cache schema'      => ['res-sarah', '2026-06-01', 30, ['availability_cache_schema' => '']],
			'no register'          => ['res-sarah', '2026-06-01', 30, ['register' => '']],
			'write fails'          => ['res-sarah', '2026-06-01', 45, [], true],
		];

		$actual = [];
		foreach ($cases as $name => $case) {
			$this->events = [];
			$service = $this->service(config: $case[3], failSave: ($case[4] ?? false));
			$result = $service->getOrComputeCache(resourceId: $case[0], date: $case[1], serviceDurationMinutes: $case[2]);
			$actual[$name] = ['result' => $this->masked(row: $result, answer: $result), 'events' => $this->maskedEvents(answer: $result)];
		}

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('AVAILABILITY_CACHE_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testGolden()

	/**
	 * The recorded events with their timestamps masked.
	 *
	 * @param array<string, mixed> $answer The answer of the call.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function maskedEvents(array $answer): array {
		$out = [];
		foreach ($this->events as $event) {
			if (isset($event['save']) === true) {
				$event['save'] = $this->masked(row: $event['save'], answer: $answer);
			}

			$out[] = $event;
		}

		return $out;
	}//end maskedEvents()

	/**
	 * A row with generatedAt/expiresAt replaced by their TTL and whether they match the answer.
	 *
	 * @param array<string, mixed> $row    The row.
	 * @param array<string, mixed> $answer The answer of the call.
	 *
	 * @return array<string, mixed>
	 */
	private function masked(array $row, array $answer): array {
		$ttl = (strtotime((string)$row['expiresAt']) - strtotime((string)$row['generatedAt']));
		$row['ttlIsADay'] = ($ttl === 86400 || $ttl === 86401);
		$row['sameAsAnswer'] = ($row['generatedAt'] === $answer['generatedAt'] && $row['expiresAt'] === $answer['expiresAt']);
		unset($row['generatedAt'], $row['expiresAt']);
		return $row;
	}//end masked()

	/**
	 * A service with one resource, no bookings, no cached row and the given config.
	 *
	 * @param array<string, string> $config   Config overrides.
	 * @param bool                  $failSave Whether saveObject throws.
	 *
	 * @return AvailabilityService
	 */
	private function service(array $config, bool $failSave): AvailabilityService {
		$values = array_merge(
			[
				'register' => 'pipelinq',
				'resource_schema' => 'appointmentResource',
				'booking_schema' => 'appointmentBooking',
				'service_schema' => 'appointmentService',
				'availability_cache_schema' => 'availability-cache',
			],
			$config
		);
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => ($values[$key] ?? $default)
		);

		$resource = new ObjectEntity();
		$resource->setUuid('res-sarah');
		$resource->setObject(
			[
				'@self' => ['id' => 'res-sarah'],
				'name' => 'Sarah',
				'workingHours' => [
					['day' => 'monday', 'openTime' => '09:00', 'closeTime' => '11:00'],
					['day' => 'tuesday', 'openTime' => '13:00', 'closeTime' => '14:00'],
				],
			]
		);

		$objects = $this->createMock(originalClassName: ObjectServiceInterface::class);
		$objects->method('find')->willReturn($resource);
		$objects->method('findAll')->willReturn([]);
		$objects->method('saveObject')->willReturnCallback(
			function (array $object) use ($failSave): ObjectEntity {
				$this->events[] = ['save' => $object];
				if ($failSave === true) {
					throw new RuntimeException('down');
				}

				return new ObjectEntity();
			}
		);

		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message, array $context = []): void {
				$this->events[] = ['warning' => (string)$message, 'context' => $context];
			}
		);

		return new AvailabilityService(
			appConfig: $appConfig,
			cacheFactory: $this->createMock(originalClassName: ICacheFactory::class),
			logger: $logger,
			objectService: $objects,
		);
	}//end service()
}//end class

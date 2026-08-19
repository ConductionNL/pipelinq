<?php

/**
 * Unit tests for AvailabilityCacheRefreshJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://pipelinq.nl
 *
 * @spec openspec/changes/appointment-booking-10-calendar-sync/specs/appointment-booking/spec.md#req-apt-018
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCA\Pipelinq\BackgroundJob\AvailabilityCacheRefreshJob;
use OCA\Pipelinq\Service\AvailabilityService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for AvailabilityCacheRefreshJob.
 *
 * Mocks ObjectService + AvailabilityService and uses reflection to invoke the
 * protected `run` method, mirroring the pattern used by every other job test
 * in the suite (CallbackOverdueJobTest, etc.).
 */
class AvailabilityCacheRefreshJobTest extends TestCase {

	/**
	 * Build a job under test with overridable mocks.
	 *
	 * @param ObjectServiceInterface|null $objectService Optional ObjectService mock.
	 * @param AvailabilityService|null $availability Optional AvailabilityService mock.
	 *
	 * @return array{0: AvailabilityCacheRefreshJob, 1: ObjectService, 2: AvailabilityService, 3: LoggerInterface}
	 */
	private function buildJob(
		?ObjectServiceInterface $objectService = null,
		?AvailabilityService $availability = null,
	): array {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(time());
		$objectService = ($objectService ?? $this->createMock(ObjectServiceInterface::class));
		$availability = ($availability ?? $this->createMock(AvailabilityService::class));

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static function (string $app, string $key, string $default = ''): string {
				$values = [
					'register' => 'pipelinq',
					'resource_schema' => 'resource',
				];
				return ($values[$key] ?? $default);
			}
		);

		$logger = $this->createMock(LoggerInterface::class);

		$job = new AvailabilityCacheRefreshJob(
			time: $time,
			objectService: $objectService,
			appConfig: $appConfig,
			availabilityService: $availability,
			logger: $logger,
		);

		return [$job, $objectService, $availability, $logger];
	}//end buildJob()

	/**
	 * Invoke the job's protected `run` method.
	 *
	 * @param AvailabilityCacheRefreshJob $job The job under test.
	 *
	 * @return void
	 */
	private function runJob(AvailabilityCacheRefreshJob $job): void {
		$ref = new \ReflectionMethod($job, 'run');
		$ref->setAccessible(true);
		$ref->invoke($job, null);
	}//end runJob()

	/**
	 * The job calls AvailabilityService::invalidateCache for every (resource, date)
	 * pair across the 31-day horizon (today inclusive + 30 forward days).
	 *
	 * @return void
	 */
	public function testJobInvalidatesEveryResourceAcrossHorizon(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('findAll')->willReturn([
			['@self' => ['id' => 'res-sarah'], 'status' => 'active'],
			['@self' => ['id' => 'res-bob'], 'status' => 'active'],
		]);

		$availability = $this->createMock(AvailabilityService::class);
		$availability->expects($this->exactly(2 * (AvailabilityCacheRefreshJob::HORIZON_DAYS + 1)))
			->method('invalidateCache');

		[$job] = $this->buildJob(objectService: $object, availability: $availability);
		$this->runJob(job: $job);
	}//end testJobInvalidatesEveryResourceAcrossHorizon()

	/**
	 * Per-resource errors are caught + logged so the next resource still runs.
	 *
	 * @return void
	 */
	public function testJobContinuesPastPerResourceErrors(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('findAll')->willReturn([
			['@self' => ['id' => 'res-sarah']],
			['@self' => ['id' => 'res-bob']],
		]);

		$availability = $this->createMock(AvailabilityService::class);
		$availability->method('invalidateCache')->willReturnCallback(
			static function (string $resourceId, string $date): void {
				if ($resourceId === 'res-sarah') {
					throw new \RuntimeException('cache wipe failed');
				}
			}
		);

		[$job, , , $logger] = $this->buildJob(objectService: $object, availability: $availability);
		$logger->expects($this->atLeastOnce())->method('warning');

		$this->runJob(job: $job);
	}//end testJobContinuesPastPerResourceErrors()

	/**
	 * When the resource list is empty (or fetch fails) the job is a no-op and
	 * never touches the availability service.
	 *
	 * @return void
	 */
	public function testJobIsNoOpWhenNoResources(): void {
		$object = $this->createMock(ObjectServiceInterface::class);
		$object->method('findAll')->willReturn([]);

		$availability = $this->createMock(AvailabilityService::class);
		$availability->expects($this->never())->method('invalidateCache');

		[$job] = $this->buildJob(objectService: $object, availability: $availability);
		$this->runJob(job: $job);
	}//end testJobIsNoOpWhenNoResources()
}//end class

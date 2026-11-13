<?php

/**
 * Unit tests for ForecastSnapshotJob: configuration guard, weekly run window, timezone and failure handling.
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
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Pipelinq\AppInfo\Application;
use OCA\Pipelinq\BackgroundJob\ForecastSnapshotJob;
use OCA\Pipelinq\Service\SnapshotGenerationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * When the weekly forecast snapshot runs, and what it records.
 */
#[CoversClass(ForecastSnapshotJob::class)]
class ForecastSnapshotJobTest extends TestCase {

	/**
	 * @var SnapshotGenerationService&MockObject
	 */
	private SnapshotGenerationService&MockObject $generation;

	/**
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig&MockObject $appConfig;

	/**
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * String config values by key.
	 *
	 * @var array<string, string>
	 */
	private array $strings = [];

	/**
	 * Int config values by key.
	 *
	 * @var array<string, int>
	 */
	private array $ints = [];

	/**
	 * Set up mocks; the app config answers from the arrays above, else the default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->generation = $this->createMock(SnapshotGenerationService::class);
		$this->appConfig  = $this->createMock(IAppConfig::class);
		$this->logger     = $this->createMock(LoggerInterface::class);
		$this->strings    = ['register' => 'crm', 'forecastSnapshot_schema' => 'forecast'];
		$this->ints       = [];

		$this->appConfig->method('getValueString')->willReturnCallback(
			function (string $app, string $key, string $default = ''): string {
				$this->assertSame(Application::APP_ID, $app);
				return $this->strings[$key] ?? $default;
			}
		);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			function (string $app, string $key, int $default = 0): int {
				$this->assertSame(Application::APP_ID, $app);
				return $this->ints[$key] ?? $default;
			}
		);
	}//end setUp()

	/**
	 * Run the job at the given UTC moment.
	 *
	 * @param string $utc The moment, e.g. "2026-11-16 06:30:00".
	 *
	 * @return void
	 */
	private function runAt(string $utc): void {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn((new DateTimeImmutable($utc . ' UTC'))->getTimestamp());

		$job = new ForecastSnapshotJob($time, $this->generation, $this->appConfig, $this->logger);
		$run = new \ReflectionMethod($job, 'run');
		$run->setAccessible(true);
		$run->invoke($job, null);
	}//end runAt()

	/**
	 * Without a register or schema the job skips before reading the clock.
	 *
	 * @return void
	 */
	public function testSkipsWhenSchemaIsNotConfigured(): void {
		$this->strings['forecastSnapshot_schema'] = '';
		$this->generation->expects($this->never())->method('generate');
		$this->logger->expects($this->once())->method('debug')
			->with('ForecastSnapshotJob: skipping — register or forecastSnapshot_schema not configured');

		$this->runAt('2026-11-16 06:30:00');
	}//end testSkipsWhenSchemaIsNotConfigured()

	/**
	 * On Monday from 06:00 (the defaults) it generates once and records today's date.
	 *
	 * @return void
	 */
	public function testGeneratesOnMondayFromSixAndRecordsTheDate(): void {
		$this->generation->expects($this->once())->method('generate')
			->with($this->callback(static fn (DateTimeImmutable $asOf): bool => $asOf->format('Y-m-d H:i') === '2026-11-16 06:00'))
			->willReturn(['created' => 3]);
		$this->appConfig->expects($this->once())->method('setValueString')
			->with(Application::APP_ID, ForecastSnapshotJob::LAST_RUN_KEY, '2026-11-16');

		$this->runAt('2026-11-16 06:00:00');
	}//end testGeneratesOnMondayFromSixAndRecordsTheDate()

	/**
	 * Before the configured hour, or on another day, nothing is generated.
	 *
	 * @return void
	 */
	public function testDoesNotRunBeforeTheHourOrOnAnotherDay(): void {
		$this->generation->expects($this->never())->method('generate');
		$this->appConfig->expects($this->never())->method('setValueString');

		$this->runAt('2026-11-16 05:59:59');
		$this->runAt('2026-11-17 09:00:00');
	}//end testDoesNotRunBeforeTheHourOrOnAnotherDay()

	/**
	 * A run already recorded for today is not repeated.
	 *
	 * @return void
	 */
	public function testDoesNotRunTwiceOnTheSameDay(): void {
		$this->strings[ForecastSnapshotJob::LAST_RUN_KEY] = '2026-11-16';
		$this->generation->expects($this->never())->method('generate');

		$this->runAt('2026-11-16 10:00:00');
	}//end testDoesNotRunTwiceOnTheSameDay()

	/**
	 * The configured day, hour and timezone are applied: Friday 08:00 in
	 * Amsterdam is 07:00 UTC in November.
	 *
	 * @return void
	 */
	public function testAppliesConfiguredDayHourAndTimezone(): void {
		$this->ints[ForecastSnapshotJob::DAY_KEY]          = 5;
		$this->ints[ForecastSnapshotJob::HOUR_KEY]         = 8;
		$this->strings[ForecastSnapshotJob::TIMEZONE_KEY]  = 'Europe/Amsterdam';
		$this->generation->expects($this->once())->method('generate')
			->with($this->callback(static fn (DateTimeImmutable $asOf): bool => $asOf->format('Y-m-d H:i e') === '2026-11-20 08:00 Europe/Amsterdam'))
			->willReturn([]);

		$this->runAt('2026-11-20 06:59:00');
		$this->runAt('2026-11-20 07:00:00');
	}//end testAppliesConfiguredDayHourAndTimezone()

	/**
	 * An unknown or empty timezone falls back to UTC.
	 *
	 * @return void
	 */
	public function testAnInvalidTimezoneFallsBackToUtc(): void {
		$this->strings[ForecastSnapshotJob::TIMEZONE_KEY] = 'Mars/Olympus';
		$this->generation->expects($this->once())->method('generate')
			->with($this->callback(static fn (DateTimeImmutable $asOf): bool => $asOf->getTimezone()->getName() === 'UTC'))
			->willReturn([]);

		$this->runAt('2026-11-16 06:00:00');
	}//end testAnInvalidTimezoneFallsBackToUtc()

	/**
	 * An empty timezone setting also means UTC.
	 *
	 * @return void
	 */
	public function testAnEmptyTimezoneMeansUtc(): void {
		$this->strings[ForecastSnapshotJob::TIMEZONE_KEY] = '';
		$this->generation->expects($this->once())->method('generate')
			->with($this->callback(static fn (DateTimeImmutable $asOf): bool => $asOf->getTimezone()->getName() === 'UTC'))
			->willReturn([]);

		$this->runAt('2026-11-16 23:00:00');
	}//end testAnEmptyTimezoneMeansUtc()

	/**
	 * A failed generation is logged and the date is not recorded, so the next hour retries.
	 *
	 * @return void
	 */
	public function testAFailedGenerationIsLoggedAndNotRecorded(): void {
		$this->generation->method('generate')->willThrowException(new \RuntimeException('register offline'));
		$this->appConfig->expects($this->never())->method('setValueString');
		$this->logger->expects($this->once())->method('error')
			->with('ForecastSnapshotJob: generation failed', ['exception' => 'register offline']);

		$this->runAt('2026-11-16 07:00:00');
	}//end testAFailedGenerationIsLoggedAndNotRecorded()
}//end class

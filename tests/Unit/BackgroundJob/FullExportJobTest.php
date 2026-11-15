<?php

/**
 * Unit tests for FullExportJob.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/data-portability-full-export/specs/data-portability/spec.md#REQ-DPE-002
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Pipelinq\BackgroundJob\FullExportJob;
use OCA\Pipelinq\Service\Export\FullExportState;
use OCA\Pipelinq\Service\Export\FullExportService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * The export runs for the administrator who asked and tells them how it went.
 *
 * @covers \OCA\Pipelinq\BackgroundJob\FullExportJob
 */
class FullExportJobTest extends TestCase {

	/**
	 * The state record the job writes to, when a test watches it.
	 *
	 * @var FullExportState|null
	 */
	private ?FullExportState $state = null;


	/**
	 * What the notification was given.
	 *
	 * @var array<string, mixed>
	 */
	private array $sent = [];

	/**
	 * Run the job.
	 *
	 * @param FullExportService $export   The export service.
	 * @param mixed             $argument The job argument.
	 * @param bool              $notify   Whether a notification is expected.
	 *
	 * @return void
	 */
	private function runJob(FullExportService $export, mixed $argument, bool $notify = true): void {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed $value) use ($setter, $notification): INotification {
					$this->sent[$setter] = $value;
					return $notification;
				}
			);
		}

		$notification->method('setObject')->willReturnCallback(
			function (string $type, string $id) use ($notification): INotification {
				$this->sent['object'] = [$type, $id];
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject, array $parameters) use ($notification): INotification {
				$this->sent['subject'] = [$subject, $parameters];
				return $notification;
			}
		);
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($notify ? $this->once() : $this->never())->method('notify')->with($notification);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-13T11:00:00+00:00'));

		$job = new FullExportJob(time: $time, export: $export, notifications: $manager, logger: $this->createMock(LoggerInterface::class), state: $this->state);
		(new ReflectionMethod($job, 'run'))->invoke($job, $argument);
	}//end run()

	/**
	 * A built archive is announced to its administrator with its name and file id.
	 *
	 * @return void
	 */
	public function testAReadyArchiveIsAnnounced(): void {
		$this->state = $this->createMock(FullExportState::class);
		$this->state->expects($this->once())->method('ready')->with('admin', 'pipelinq-export.zip', '42');
		$this->state->expects($this->never())->method('failed');
		$export = $this->createMock(FullExportService::class);
		$export->expects($this->once())->method('exportFor')->with('admin')
			->willReturn(['name' => 'pipelinq-export.zip', 'path' => '/admin/files/pipelinq-export.zip', 'fileId' => 42, 'manifest' => []]);

		$this->runJob(export: $export, argument: ['userId' => ' admin ']);

		$this->assertSame('pipelinq', $this->sent['setApp']);
		$this->assertSame('admin', $this->sent['setUser']);
		$this->assertSame(['export', '42'], $this->sent['object']);
		$this->assertSame(['full_export_ready', ['name' => 'pipelinq-export.zip', 'fileId' => '42']], $this->sent['subject']);
	}//end testAReadyArchiveIsAnnounced()

	/**
	 * A failed export is announced as failed.
	 *
	 * @return void
	 */
	public function testAFailedExportIsAnnouncedAsFailed(): void {
		$this->state = $this->createMock(FullExportState::class);
		$this->state->expects($this->once())->method('failed')->with('admin');
		$this->state->expects($this->never())->method('ready');
		$export = $this->createMock(FullExportService::class);
		$export->method('exportFor')->willThrowException(new RuntimeException('disk full'));

		$this->runJob(export: $export, argument: ['userId' => 'admin']);

		$this->assertSame(['export', 'full'], $this->sent['object']);
		$this->assertSame(['full_export_failed', []], $this->sent['subject']);
	}//end testAFailedExportIsAnnouncedAsFailed()

	/**
	 * Without an administrator nothing is exported or announced.
	 *
	 * @return void
	 */
	public function testWithoutAnAdministratorNothingHappens(): void {
		$export = $this->createMock(FullExportService::class);
		$export->expects($this->never())->method('exportFor');

		$this->runJob(export: $export, argument: [], notify: false);

		$this->assertSame([], $this->sent);
	}//end testWithoutAnAdministratorNothingHappens()
}//end class

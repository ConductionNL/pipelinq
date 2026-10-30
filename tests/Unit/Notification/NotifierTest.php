<?php

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Notification;

use OCA\Pipelinq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

class NotifierTest extends TestCase {
	private Notifier $notifier;
	protected function setUp(): void {
		$factory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnArgument(0);
		$factory->method('get')->willReturn($l10n);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$this->notifier = new Notifier($factory, $urlGenerator);
	}
	public function testGetIdReturnsPipelinq(): void {
		$this->assertSame('pipelinq', $this->notifier->getID());
	}
	public function testGetNameReturnsString(): void {
		$this->assertIsString($this->notifier->getName());
	}
	/**
	 * A switched-off export job names the job, its failed runs and the last
	 * error, and links to the BI export page.
	 *
	 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
	 */
	public function testASwitchedOffExportJobNamesTheJobAndTheErrorAndLinksToTheExportPage(): void {
		$factory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l10n->method('n')->willReturnCallback(
			static fn (string $singular, string $plural, int $count, array $parameters = []): string => vsprintf($count === 1 ? $singular : $plural, $parameters)
		);
		$factory->method('get')->willReturn($l10n);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturn('https://cloud.example/apps/pipelinq/');
		$notifier = new Notifier($factory, $urlGenerator);

		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('pipelinq');
		$notification->method('getSubject')->willReturn('export_job_switched_off');
		$notification->method('getSubjectParameters')->willReturn(
			['name' => 'Leads and pipeline value', 'failures' => 3, 'error' => 'connection refused']
		);
		$notification->method('getObjectType')->willReturn('exportJob');
		$notification->method('getObjectId')->willReturn('job-1');
		$notification->expects($this->once())->method('setParsedSubject')
			->with('Export job Leads and pipeline value was switched off after 3 failed runs in a row')->willReturnSelf();
		$notification->expects($this->once())->method('setParsedMessage')
			->with('Last error: connection refused. Fix the destination, then switch the job on again on the BI export page.')->willReturnSelf();
		$notification->expects($this->once())->method('setLink')->with('https://cloud.example/apps/pipelinq/export/jobs')->willReturnSelf();

		$notifier->prepare($notification, 'en');
	}

	public function testPrepareThrowsForWrongApp(): void {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('other_app');
		$this->expectException(UnknownNotificationException::class);
		$this->notifier->prepare($notification, 'en');
	}
}

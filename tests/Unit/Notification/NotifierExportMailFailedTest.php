<?php

/**
 * Notifier: the mailed report delivery-failed subject.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Notification
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * @spec openspec/changes/archive/2026-11-26-reports-compare-periods-and-scheduled-delivery
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Notification;

use OCA\Pipelinq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * The owner of a mailed report hears which report, how many recipients it
 * missed and why, and the link opens the export job.
 *
 * @covers \OCA\Pipelinq\Notification\Notifier
 */
class NotifierExportMailFailedTest extends TestCase {

	/**
	 * Setter calls made on the notification.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Prepare a notification of this subject and object type.
	 *
	 * @param string               $subject    The subject.
	 * @param array<string, mixed> $params     The subject parameters.
	 * @param string               $objectType The object type.
	 *
	 * @return void
	 */
	private function prepare(string $subject, array $params, string $objectType): void {
		$this->calls = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('pipelinq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getObjectType')->willReturn($objectType);
		$notification->method('getObjectId')->willReturn('j-1');
		foreach (['setParsedSubject', 'setRichSubject', 'setParsedMessage', 'setIcon', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $notification): INotification {
					$this->calls[$setter] = $args;
					return $notification;
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => vsprintf($text, (array)$parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img.svg');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud/apps/pipelinq/');

		(new Notifier(l10nFactory: $factory, urlGenerator: $urls))->prepare(notification: $notification, languageCode: 'en');
	}//end prepare()

	/**
	 * The subject names the report and the missed recipients, the message the reason.
	 *
	 * @return void
	 */
	public function testTheOwnerIsToldWhichReportHowManyAndWhy(): void {
		$this->prepare(
			subject: 'export_mail_delivery_failed',
			params: ['title' => 'Weekly pipeline', 'failed' => 2, 'error' => 'mailbox full'],
			objectType: 'exportJob'
		);

		$this->assertSame(['Report Weekly pipeline could not be mailed to 2 recipients'], $this->calls['setParsedSubject']);
		$this->assertSame('Report {title} could not be mailed to 2 recipients', $this->calls['setRichSubject'][0]);
		$this->assertSame('Weekly pipeline', $this->calls['setRichSubject'][1]['title']['name']);
		$this->assertSame(['Reason: mailbox full'], $this->calls['setParsedMessage']);
		$this->assertSame(['https://cloud/apps/pipelinq/#/export/jobs/j-1'], $this->calls['setLink']);
	}//end testTheOwnerIsToldWhichReportHowManyAndWhy()

	/**
	 * Missing parameters read as no recipients and no reason; a count is read as a whole number.
	 *
	 * @return void
	 */
	public function testMissingOrLooseParametersAreReadSafely(): void {
		$this->prepare(subject: 'export_mail_delivery_failed', params: ['title' => 'Weekly pipeline'], objectType: 'exportJob');

		$this->assertSame(['Report Weekly pipeline could not be mailed to 0 recipients'], $this->calls['setParsedSubject']);
		$this->assertSame(['Reason: '], $this->calls['setParsedMessage']);

		$this->prepare(subject: 'export_mail_delivery_failed', params: ['title' => 'Weekly pipeline', 'failed' => '3 people', 'error' => 'timeout'], objectType: 'exportJob');

		$this->assertSame(['Report Weekly pipeline could not be mailed to 3 recipients'], $this->calls['setParsedSubject']);
		$this->assertSame('Report {title} could not be mailed to 3 recipients', $this->calls['setRichSubject'][0]);
		$this->assertSame(['Reason: timeout'], $this->calls['setParsedMessage']);
	}//end testMissingOrLooseParametersAreReadSafely()
}//end class

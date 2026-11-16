<?php

/**
 * Notifier: the export job switched-off subject.
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
 * @spec openspec/changes/exports-switch-off-after-repeated-failure/specs/export-failure-switch-off/spec.md#requirement-administrators-are-told-once-when-a-job-is-switched-off-req-efs-002
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
 * The notification names the job, the failures and the last error, and links
 * to the export job's page.
 *
 * @covers \OCA\Pipelinq\Notification\Notifier
 */
class NotifierExportSwitchOffTest extends TestCase {

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
	 * The subject names the job and the failures, the message the last
	 * error, and the link opens the job on the BI export page.
	 *
	 * @return void
	 */
	public function testTheNotificationNamesTheJobTheFailuresAndTheError(): void {
		$this->prepare(
			subject: 'export_job_switched_off',
			params: ['title' => 'Leads and pipeline value', 'failures' => 3, 'error' => 'connection refused'],
			objectType: 'exportJob'
		);

		$this->assertSame(
			['Export job Leads and pipeline value was switched off after 3 failed runs in a row'],
			$this->calls['setParsedSubject']
		);
		$this->assertSame('Export job {title} was switched off after 3 failed runs in a row', $this->calls['setRichSubject'][0]);
		$this->assertSame('Leads and pipeline value', $this->calls['setRichSubject'][1]['title']['name']);
		$this->assertSame(
			['Last error: connection refused. Fix the destination, then switch the job on again on the BI export page.'],
			$this->calls['setParsedMessage']
		);
		$this->assertSame(['https://cloud/apps/pipelinq/#/export/jobs/j-1'], $this->calls['setLink']);
	}//end testTheNotificationNamesTheJobTheFailuresAndTheError()

	/**
	 * Other object types keep their plural route.
	 *
	 * @return void
	 */
	public function testOtherObjectTypesKeepTheirPluralRoute(): void {
		$this->prepare(subject: 'lead_assigned', params: ['title' => 'Acme'], objectType: 'lead');

		$this->assertSame(['https://cloud/apps/pipelinq/#/leads/j-1'], $this->calls['setLink']);
	}//end testOtherObjectTypesKeepTheirPluralRoute()
}//end class

<?php

/**
 * Unit tests for the Notifier's follow-up reminder subjects.
 *
 * @category Tests
 * @package  OCA\Pipelinq\Tests\Unit\Notification
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://github.com/ConductionNL/pipelinq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/calendar-deepening/tasks.md#3-followupreminderjob
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
 * The reminder subjects are translated and deep-link to the object they are about.
 *
 * @covers \OCA\Pipelinq\Notification\Notifier
 */
class NotifierReminderSubjectTest extends TestCase {

	/**
	 * Setter calls recorded on the notification.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * Prepare a notification and record what was set.
	 *
	 * @param string               $subject    The subject.
	 * @param array<string, mixed> $params     The parameters.
	 * @param string               $objectType The object type.
	 * @param string               $objectId   The object id.
	 *
	 * @return array<string, array<int, mixed>> The setter calls.
	 */
	private function prepare(string $subject, array $params, string $objectType, string $objectId): array {
		$this->calls = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('pipelinq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getObjectType')->willReturn($objectType);
		$notification->method('getObjectId')->willReturn($objectId);
		foreach (['setParsedSubject', 'setRichSubject', 'setIcon', 'setLink'] as $setter) {
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
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud/apps/pipelinq/');

		(new Notifier(l10nFactory: $factory, urlGenerator: $urls))->prepare(notification: $notification, languageCode: 'nl');

		return $this->calls;
	}//end prepare()

	/**
	 * An upcoming meeting names the lead, the meeting and its start, and links to the lead.
	 *
	 * @return void
	 */
	public function testAnUpcomingMeetingNamesTheLeadAndLinksToIt(): void {
		$calls = $this->prepare(
			subject: 'followup_event_upcoming',
			params: ['title' => 'Site visit', 'lead' => 'Fiber rollout', 'start' => '2027-01-15T09:00:00+00:00'],
			objectType: 'lead',
			objectId: 'lead-1'
		);

		$this->assertSame(['Upcoming meeting for Fiber rollout: Site visit at 2027-01-15T09:00:00+00:00'], $calls['setParsedSubject']);
		$this->assertSame('Upcoming meeting: {title}', $calls['setRichSubject'][0]);
		$this->assertSame(['title' => ['type' => 'highlight', 'id' => 'lead-1', 'name' => 'Site visit']], $calls['setRichSubject'][1]);
		$this->assertSame(['https://cloud/apps/pipelinq/#/leads/lead-1'], $calls['setLink']);
	}//end testAnUpcomingMeetingNamesTheLeadAndLinksToIt()

	/**
	 * A follow-up task falling due names its deadline.
	 *
	 * @return void
	 */
	public function testAFollowUpDueNamesItsDeadline(): void {
		$calls = $this->prepare(
			subject: 'followup_task_due',
			params: ['title' => 'Send quote', 'deadline' => '2027-01-15'],
			objectType: 'task',
			objectId: 'task-2'
		);

		$this->assertSame(['Follow-up due: Send quote (deadline: 2027-01-15)'], $calls['setParsedSubject']);
		$this->assertSame('Follow-up due: {title}', $calls['setRichSubject'][0]);
		$this->assertSame(['https://cloud/apps/pipelinq/#/tasks/task-2'], $calls['setLink']);
	}//end testAFollowUpDueNamesItsDeadline()
}//end class

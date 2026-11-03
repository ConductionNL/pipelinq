<?php

/**
 * Golden test for Notifier::prepare().
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Notification
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
 * @spec openspec/specs/notifications-activity/spec.md
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Notification;

use OCA\Pipelinq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\TestCase;

/**
 * Every setter call prepare() makes on a notification (translation key and
 * parameters included), for every subject with full and empty parameters,
 * plus an unknown subject and another app, compared with
 * NotifierSubjectGolden.json. Set NOTIFIER_SUBJECT_GOLDEN_RECORD=1 to rewrite
 * the file, then run once more without it.
 *
 * @covers \OCA\Pipelinq\Notification\Notifier
 *
 * @spec openspec/specs/notifications-activity/spec.md
 */
class NotifierSubjectGoldenTest extends TestCase {
	private const GOLDEN = __DIR__ . '/NotifierSubjectGolden.json';

	private const SUBJECTS = [
		'lead_assigned', 'request_assigned', 'task_assigned', 'task_completed', 'task_reassigned', 'task_expired',
		'lead_stage_changed', 'request_status_changed', 'note_added', 'lead_won', 'lead_lost',
		'forecast_snapshot_partial_failure', 'wip_sync_failed', 'billing_handoff_failed',
		'social_share_requested', 'social_relink_needed', 'something_else',
	];

	private const PARAMS = [
		'title' => 'Acme', 'resultText' => 'done', 'deadline' => '2026-12-01', 'stage' => 'Proposal',
		'status' => 'Closed', 'entityType' => 'lead', 'value' => '1250', 'period' => 'Q4',
		'errors' => '2 reps', 'batchId' => 'b-7', 'handle' => '@acme',
	];

	/**
	 * Calls on the notification in the current case.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Run every case and compare with the golden file.
	 *
	 * @return void
	 */
	public function testGolden(): void {
		$actual = [];
		foreach (self::SUBJECTS as $subject) {
			foreach (['full' => self::PARAMS, 'empty' => []] as $kind => $params) {
				$actual[$subject . ' / ' . $kind] = $this->prepare(app: 'pipelinq', subject: $subject, params: $params);
			}
		}

		$actual['other app'] = $this->prepare(app: 'files', subject: 'lead_assigned', params: self::PARAMS);

		$flags = (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if (getenv('NOTIFIER_SUBJECT_GOLDEN_RECORD') !== false) {
			file_put_contents(self::GOLDEN, json_encode($actual, $flags) . "\n");
			$this->markTestSkipped(message: 'recorded');
		}

		$expected = json_decode((string)file_get_contents(self::GOLDEN), true);
		$this->assertSame(expected: $expected, actual: $actual);
	}//end testGolden()

	/**
	 * Prepare one notification and return its setter calls, or the exception.
	 *
	 * @param string               $app     The notification's app.
	 * @param string               $subject The subject.
	 * @param array<string, mixed> $params  The subject parameters.
	 *
	 * @return array<int|string, mixed>
	 */
	private function prepare(string $app, string $subject, array $params): array {
		$this->calls = [];
		$notification = $this->createMock(originalClassName: INotification::class);
		$notification->method('getApp')->willReturn($app);
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getObjectType')->willReturn('lead');
		$notification->method('getObjectId')->willReturn('obj-9');
		foreach (['setParsedSubject', 'setRichSubject', 'setParsedMessage', 'setIcon', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $notification): INotification {
					$this->calls[] = [$setter => $args];
					return $notification;
				}
			);
		}

		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array|string $parameters = []): string => 'T[' . $text . ']' . json_encode($parameters)
		);
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l10n);

		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $appName, string $file): string => '/img/' . $appName . '/' . $file);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud' . $url);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud/apps/pipelinq/');

		try {
			(new Notifier(l10nFactory: $factory, urlGenerator: $urls))->prepare(notification: $notification, languageCode: 'nl');
		} catch (UnknownNotificationException $e) {
			$this->calls[] = ['threw' => get_class($e)];
		}

		return $this->calls;
	}//end prepare()
}//end class

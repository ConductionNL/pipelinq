<?php

/**
 * Tests for the Notifier's changed-page subject: it names the competitor and
 * the page, and opens the Competitors page rather than the competitor object.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Notification
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/specify-capability-rows/specs/marketing-competitor-watches/spec.md#requirement-the-owner-of-a-watch-is-told-when-the-watched-page-changes
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
 * @covers \OCA\Pipelinq\Notification\Notifier
 */
class NotifierWatchSubjectTest extends TestCase {

	/**
	 * Prepare a notification of the given subject and record what was set on it.
	 *
	 * @param string               $subject The subject.
	 * @param array<string, mixed> $params  The subject parameters.
	 *
	 * @return array<string, string> What the notifier set: subject, message, link.
	 */
	private function prepared(string $subject, array $params): array {
		$factory = $this->createMock(IFactory::class);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://nc.example/apps/pipelinq/');
		$urls->method('imagePath')->willReturn('/apps/pipelinq/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);

		$set = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('pipelinq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getObjectType')->willReturn('competitor');
		$notification->method('getObjectId')->willReturn('acme');
		foreach (['setParsedSubject' => 'subject', 'setParsedMessage' => 'message', 'setLink' => 'link', 'setIcon' => 'icon', 'setRichSubject' => 'rich'] as $method => $key) {
			$notification->method($method)->willReturnCallback(
				function (string $value) use ($key, &$set, $notification): INotification {
					$set[$key] = $value;
					return $notification;
				}
			);
		}

		(new Notifier($factory, $urls))->prepare($notification, 'en');

		return $set;
	}//end prepared()

	public function testAChangedPageNamesTheCompetitorAndOpensTheCompetitorsPage(): void {
		$set = $this->prepared('competitor_page_changed', ['competitor' => 'Acme', 'page' => 'https://acme.example/pricing']);

		$this->assertSame('Acme changed its page https://acme.example/pricing', $set['subject']);
		$this->assertSame('The Competitors page lists the change with its summary.', $set['message']);
		$this->assertSame('https://nc.example/apps/pipelinq/marketing/competitors', $set['link']);
	}

	public function testOtherSubjectsStillOpenTheirObject(): void {
		$set = $this->prepared('social_relink_needed', ['handle' => '@acme']);

		$this->assertSame('https://nc.example/apps/pipelinq/#/competitors/acme', $set['link']);
	}
}

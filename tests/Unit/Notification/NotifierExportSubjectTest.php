<?php

/**
 * Unit tests for the full data export notifications.
 *
 * @category Test
 * @package  OCA\Pipelinq\Tests\Unit\Notification
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

namespace OCA\Pipelinq\Tests\Unit\Notification;

use OCA\Pipelinq\Notification\Notifier;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

/**
 * The export notifications: ready links to the archive in Files, failed says nothing was stored.
 *
 * @covers \OCA\Pipelinq\Notification\Notifier
 */
class NotifierExportSubjectTest extends TestCase {

	/**
	 * What the notifier set.
	 *
	 * @var array<string, mixed>
	 */
	private array $set = [];

	/**
	 * Prepare one notification.
	 *
	 * @param string               $subject The subject.
	 * @param array<string, mixed> $params  The subject parameters.
	 *
	 * @return array<string, mixed> What the notifier set.
	 */
	private function prepare(string $subject, array $params): array {
		$this->set    = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('pipelinq');
		$notification->method('getSubject')->willReturn($subject);
		$notification->method('getSubjectParameters')->willReturn($params);
		$notification->method('getObjectType')->willReturn('export');
		$notification->method('getObjectId')->willReturn((string) ($params['fileId'] ?? 'full'));
		foreach (['setParsedSubject', 'setParsedMessage', 'setRichSubject', 'setIcon', 'setLink'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed $value) use ($setter, $notification): INotification {
					$this->set[$setter] = $value;
					return $notification;
				}
			);
		}

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->willReturn('/img/app-dark.svg');
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $url): string => 'https://cloud' . $url);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string => 'https://cloud/' . $route . ($args === [] ? '' : '?' . http_build_query($args))
		);

		(new Notifier(l10nFactory: $factory, urlGenerator: $urls))->prepare(notification: $notification, languageCode: 'en');

		return $this->set;
	}//end prepare()

	/**
	 * A ready archive names itself and links to the file in Files.
	 *
	 * @return void
	 */
	public function testAReadyArchiveLinksToTheFile(): void {
		$set = $this->prepare(subject: 'full_export_ready', params: ['name' => 'pipelinq-export.zip', 'fileId' => '42']);

		$this->assertSame('Your data export is ready: pipelinq-export.zip', $set['setParsedSubject']);
		$this->assertSame('The archive with all Pipelinq data is in your Files.', $set['setParsedMessage']);
		$this->assertSame('https://cloud/files.viewcontroller.showFile?fileid=42', $set['setLink']);
		$this->assertSame('https://cloud/img/app-dark.svg', $set['setIcon']);
	}//end testAReadyArchiveLinksToTheFile()

	/**
	 * A ready archive without a file id falls back to the app link.
	 *
	 * @return void
	 */
	public function testAReadyArchiveWithoutAFileIdLinksToTheApp(): void {
		$set = $this->prepare(subject: 'full_export_ready', params: ['name' => 'x.zip']);

		$this->assertSame('https://cloud/pipelinq.dashboard.page#/exports/full', $set['setLink']);
	}//end testAReadyArchiveWithoutAFileIdLinksToTheApp()

	/**
	 * A failed export says nothing was stored.
	 *
	 * @return void
	 */
	public function testAFailedExportSaysNothingWasStored(): void {
		$set = $this->prepare(subject: 'full_export_failed', params: []);

		$this->assertSame('Your data export could not be built', $set['setParsedSubject']);
		$this->assertSame('Nothing was stored. Try again; if it fails again, ask your administrator to check the log.', $set['setParsedMessage']);
		$this->assertSame('https://cloud/pipelinq.dashboard.page#/exports/full', $set['setLink']);
	}//end testAFailedExportSaysNothingWasStored()
}//end class

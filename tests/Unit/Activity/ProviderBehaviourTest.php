<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Pipelinq\Tests\Unit\Activity;

use OCA\Pipelinq\Activity\Provider;
use OCA\Pipelinq\Activity\ProviderSubjectHandler;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use PHPUnit\Framework\TestCase;

/**
 * Which events the provider parses, in which language, and what it sets on them.
 *
 * @covers \OCA\Pipelinq\Activity\Provider
 * @uses   \OCA\Pipelinq\Activity\ProviderSubjectHandler
 */
class ProviderBehaviourTest extends TestCase {
	/**
	 * An event of pipelinq with this subject.
	 *
	 * @param string $subject The subject.
	 * @param array  $params  The subject parameters.
	 */
	private function event(string $subject, array $params = []): IEvent {
		$event = $this->createMock(originalClassName: IEvent::class);
		$event->method('getApp')->willReturn('pipelinq');
		$event->method('getSubject')->willReturn($subject);
		$event->method('getSubjectParameters')->willReturn($params);
		$event->method('getObjectId')->willReturn(42);
		return $event;
	}

	/**
	 * A URL generator that resolves the app icon.
	 */
	private function urls(): IURLGenerator {
		$urls = $this->createMock(originalClassName: IURLGenerator::class);
		$urls->method('imagePath')->willReturnCallback(static fn (string $app, string $file): string => '/apps/'.$app.'/img/'.$file);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example'.$path);
		return $urls;
	}

	public function testAnUnknownSubjectIsRefusedBeforeTranslating(): void {
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->expects($this->never())->method('get');
		$handler = $this->createMock(originalClassName: ProviderSubjectHandler::class);
		$handler->expects($this->never())->method('applySubjectText');

		$provider = new Provider(l10nFactory: $factory, urlGenerator: $this->urls(), subjectHandler: $handler);

		$this->expectException(UnknownActivityException::class);
		$provider->parse('en', $this->event(subject: 'file_created'));
	}

	public function testAnotherAppsEventIsRefusedEvenWithAKnownSubject(): void {
		$event = $this->createMock(originalClassName: IEvent::class);
		$event->method('getApp')->willReturn('files');
		$event->method('getSubject')->willReturn('lead_created');
		$handler = $this->createMock(originalClassName: ProviderSubjectHandler::class);
		$handler->expects($this->never())->method('applySubjectText');

		$provider = new Provider(l10nFactory: $this->createMock(originalClassName: IFactory::class), urlGenerator: $this->urls(), subjectHandler: $handler);

		$this->expectException(UnknownActivityException::class);
		$provider->parse('en', $event);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function handledSubjects(): array {
		$subjects = ['lead_created', 'lead_assigned', 'lead_stage_changed', 'request_created', 'request_status_changed', 'note_added', 'lead_won', 'lead_lost'];
		return array_combine($subjects, array_map(static fn (string $s): array => [$s], $subjects));
	}

	/**
	 * @dataProvider handledSubjects
	 */
	public function testAHandledSubjectIsTranslatedInTheReadersLanguageAndGetsTheIcon(string $subject): void {
		$l10n    = $this->createMock(originalClassName: IL10N::class);
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->expects($this->once())->method('get')->with('pipelinq', 'nl')->willReturn($l10n);
		$event   = $this->event(subject: $subject, params: ['title' => 'Acme']);
		$handler = $this->createMock(originalClassName: ProviderSubjectHandler::class);
		$handler->expects($this->once())->method('applySubjectText')->with($event, $l10n, ['title' => 'Acme']);
		$event->expects($this->once())->method('setIcon')->with('https://cloud.example/apps/pipelinq/img/app.svg');

		$provider = new Provider(l10nFactory: $factory, urlGenerator: $this->urls(), subjectHandler: $handler);

		$this->assertSame($event, $provider->parse('nl', $event));
	}

	public function testTheRealHandlerSetsTheParsedAndRichSubject(): void {
		$l10n = $this->createMock(originalClassName: IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $params = []): string => vsprintf($text, $params));
		$factory = $this->createMock(originalClassName: IFactory::class);
		$factory->method('get')->willReturn($l10n);
		$event = $this->event(subject: 'lead_stage_changed', params: ['title' => 'Acme', 'stage' => 'Proposal']);
		$event->expects($this->once())->method('setParsedSubject')->with('Lead Acme moved to Proposal');
		$event->expects($this->once())->method('setRichSubject')->with(
			'{title} moved to Proposal',
			['title' => ['type' => 'highlight', 'id' => '42', 'name' => 'Acme']]
		);

		$provider = new Provider(l10nFactory: $factory, urlGenerator: $this->urls(), subjectHandler: new ProviderSubjectHandler());

		$provider->parse('en', $event);
	}
}
